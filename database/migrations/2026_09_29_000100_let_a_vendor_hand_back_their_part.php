<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How much of a work's rate came back when its vendor handed it back.
 *
 * Asked for by the owner on 2026-09-28: taking papers back from a vendor is
 * one vendor at a time now, and only their works move. A folder split between
 * two vendors, or kept partly in the office, has one figure of its own for the
 * part reversed — vendor_returned_amount — and it cannot say whose part it
 * was. So the figure goes down to the works, as the vendor and the dates did
 * on 2026-09-19, and the folder's becomes the sum of theirs.
 *
 * Null means all of the work's rate, as it means all of the folder's.
 *
 * The part reversals typed until now are carried down: a folder handed back
 * with a part figure has it shared over its works that came back — the works
 * not yet approved first, each up to its own rate — so the folder's figure
 * reads the same as it did. Folders whose works came back from two vendors at
 * once are left alone; nothing so far could make one, and files:audit names it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_file_item', function (Blueprint $table) {
            if (! Schema::hasColumn('work_file_item', 'vendor_returned_amount')) {
                $table->decimal('vendor_returned_amount', 15, 2)->nullable()->after('vendor_returned_on');
            }
        });

        $folders = DB::table('work_file')
            ->whereNotNull('vendor_returned_on')
            ->whereNotNull('vendor_returned_amount')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('work_file_item')
                ->whereColumn('work_file_item.work_file_id', 'work_file.id')
                ->whereNotNull('work_file_item.vendor_id'))
            ->get(['id', 'vendor_returned_amount']);

        foreach ($folders as $folder) {
            /*
             * Cancelled works too: a folder cancelled whole keeps its figure
             * frozen, and un-cancelled it must come back as the part agreed,
             * not all of it (found in review).
             */
            $back = DB::table('work_file_item')
                ->where('work_file_id', $folder->id)
                ->whereNotNull('vendor_id')
                ->whereNotNull('vendor_returned_on')
                ->orderByRaw("CASE WHEN status = 'approval_done' THEN 1 ELSE 0 END")
                ->orderBy('id')
                ->get(['id', 'vendor_id', 'vendor_amount', 'vendor_returned_amount']);

            /*
             * Shared again from the folder's own figure, over every work that
             * came back, whatever a run before wrote on them — and each folder
             * in one go. Found in review: a run cut off part-way through a
             * folder and run again gave the whole part a second time to the
             * works it had not reached. Or not one vendor's to carry.
             */
            if ($back->isEmpty() || $back->pluck('vendor_id')->unique()->count() > 1) {
                continue;
            }

            $left = (float) $folder->vendor_returned_amount;

            // All of it, which null already says.
            if ($left >= $back->sum(fn ($work) => (float) $work->vendor_amount)) {
                continue;
            }

            DB::transaction(function () use ($back, $left) {
                foreach ($back as $work) {
                    $rate = max(0.0, (float) $work->vendor_amount);
                    $take = round(min($left, $rate), 2);
                    $left = round($left - $take, 2);

                    DB::table('work_file_item')->where('id', $work->id)
                        ->update(['vendor_returned_amount' => abs($take - $rate) < 0.005 ? null : $take]);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('work_file_item', function (Blueprint $table) {
            if (Schema::hasColumn('work_file_item', 'vendor_returned_amount')) {
                $table->dropColumn('vendor_returned_amount');
            }
        });
    }
};
