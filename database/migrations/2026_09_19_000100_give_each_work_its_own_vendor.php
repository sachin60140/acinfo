<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A work goes to its own vendor.
 *
 * A folder holding a transfer, a hypothecation addition and a termination is
 * one job to the customer and three jobs to the office — and the office does
 * not send all three to the same person. One agent is quick with transfers;
 * another has the bank contact. Until now the vendor, the day it went out and
 * the day it came back lived on the folder, so the office either sent the whole
 * thing to one vendor or split the folder into three files and charged the
 * customer three times for one visit.
 *
 * The columns move down to the work. The folder keeps its own — they are what
 * every screen still reads — and they are derived from the works from here on:
 * one vendor when the works agree, none when they do not, and the earliest date
 * among them.
 *
 * The ledger's unique key has to widen with it. It holds one row per file and
 * role, which is exactly the rule being lifted: a folder split between two
 * vendors owes two of them. Keyed on the party as well, a file writes one line
 * per vendor — and a folder that is not split writes the one line it always
 * did, to the same vendor, with the same words on it.
 *
 * Nothing on screen changes yet. This is the shape underneath it.
 */
return new class extends Migration
{
    private const WAS = 'party_ledger_file_role_unique';

    private const NOW = 'party_ledger_file_role_party_unique';

    public function up(): void
    {
        Schema::table('work_file_item', function (Blueprint $table) {
            if (! Schema::hasColumn('work_file_item', 'vendor_id')) {
                // Nulled rather than cascaded: a vendor removed from the books
                // must not take the record of who did the work with it.
                $table->foreignId('vendor_id')->nullable()->after('work_type_id')
                    ->constrained('party')->nullOnDelete();
            }

            if (! Schema::hasColumn('work_file_item', 'vendor_date')) {
                $table->date('vendor_date')->nullable()->after('vendor_amount');
            }

            if (! Schema::hasColumn('work_file_item', 'vendor_returned_on')) {
                $table->date('vendor_returned_on')->nullable()->after('vendor_date');
            }
        });

        /*
         * Every work inherits the folder's vendor, because that is where it
         * has been all along. Cancelled work included: it is a record of what
         * happened, and the sums leave it out on their own.
         */
        DB::statement('UPDATE work_file_item i
            JOIN work_file f ON f.id = i.work_file_id
            SET i.vendor_id = f.vendor_id,
                i.vendor_date = f.vendor_date,
                i.vendor_returned_on = f.vendor_returned_on
            WHERE i.vendor_id IS NULL AND f.vendor_id IS NOT NULL');

        if (! $this->has(self::NOW)) {
            /*
             * The same guard the narrower index was added with: a unique index
             * over existing duplicates fails outright and aborts the deploy, and
             * fixing it means deciding which row is the real one.
             */
            $duplicates = DB::table('party_ledger')
                ->whereNotNull('work_file_id')
                ->whereNotNull('file_role')
                ->select('work_file_id', 'file_role', 'party_id', DB::raw('COUNT(*) as total'))
                ->groupBy('work_file_id', 'file_role', 'party_id')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            if ($duplicates->isNotEmpty()) {
                $detail = $duplicates
                    ->map(fn ($row) => "work_file {$row->work_file_id} / {$row->file_role} / party {$row->party_id} x{$row->total}")
                    ->implode(', ');

                throw new RuntimeException(
                    'party_ledger already holds duplicate entries for the same file, role and party, so the '
                    ."wider unique index cannot be added: {$detail}. Decide which entry is correct, delete "
                    .'the others, then run this migration again.'
                );
            }

            Schema::table('party_ledger', function (Blueprint $table) {
                $table->unique(['work_file_id', 'file_role', 'party_id'], self::NOW);
            });
        }

        /*
         * Only now: work_file_id's foreign key needs an index beginning with it,
         * and until the wider one is in place this is the only one there is.
         * Dropped first, MySQL refuses outright — error 1553.
         */
        if ($this->has(self::WAS)) {
            Schema::table('party_ledger', function (Blueprint $table) {
                $table->dropUnique(self::WAS);
            });
        }
    }

    public function down(): void
    {
        /*
         * Going back means one vendor per file again, so a folder split between
         * two of them has two rows where the old index allows one. Said rather
         * than guessed: deleting one of them would take money off somebody's
         * statement.
         */
        if ($this->has(self::NOW)) {
            $split = DB::table('party_ledger')
                ->whereNotNull('work_file_id')
                ->whereNotNull('file_role')
                ->select('work_file_id', 'file_role', DB::raw('COUNT(*) as total'))
                ->groupBy('work_file_id', 'file_role')
                ->havingRaw('COUNT(*) > 1')
                ->count();

            if ($split) {
                throw new RuntimeException(
                    "$split file(s) are split between vendors and hold more than one entry for a role. "
                    .'Bring each of those files back to a single vendor before rolling this back, or the '
                    .'narrower index will drop money off a statement.'
                );
            }

        }

        if (! $this->has(self::WAS)) {
            Schema::table('party_ledger', function (Blueprint $table) {
                $table->unique(['work_file_id', 'file_role'], self::WAS);
            });
        }

        // And only now, for the reason given in up().
        if ($this->has(self::NOW)) {
            Schema::table('party_ledger', function (Blueprint $table) {
                $table->dropUnique(self::NOW);
            });
        }

        Schema::table('work_file_item', function (Blueprint $table) {
            if (Schema::hasColumn('work_file_item', 'vendor_id')) {
                $table->dropConstrainedForeignId('vendor_id');
            }

            foreach (['vendor_date', 'vendor_returned_on'] as $column) {
                if (Schema::hasColumn('work_file_item', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /**
     * Read the index list directly: Schema::hasIndex() does not exist on 11.9,
     * which is what the server runs. See make_file_ledger_entries_unique.
     */
    private function has(string $name): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->whereRaw('TABLE_SCHEMA = database()')
            ->where('TABLE_NAME', 'party_ledger')
            ->where('INDEX_NAME', $name)
            ->exists();
    }
};
