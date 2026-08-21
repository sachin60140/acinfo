<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A file whose jobs have not all finished together.
 *
 * Approvals arrive separately. A hypothecation addition can come back approved
 * while the transfer on the same papers is still with the office days later,
 * and until now the file had to claim one or the other: marking it approved
 * said work was done that was not, and leaving it pending hid work that was.
 *
 * So the file gets a state for what is actually true. It is only ever derived —
 * nobody sets it by hand — and it means what it says: some of this is through,
 * some is not, and the file is still in hand.
 */
return new class extends Migration
{
    private const STATUSES = [
        'in_office',
        'paper_pendency',
        'file_dispatch',
        'part_pesi_required',
        'under_verification',
        'partly_approved',
        'approval_done',
        'paper_returned',
        'cancelled',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('work_file')) {
            return;
        }

        $values = "'".implode("','", self::STATUSES)."'";

        // Widened in place. Every value that exists stays valid, so no row is
        // touched and nothing has to be re-saved.
        DB::statement("alter table work_file modify status enum($values) not null default 'in_office'");
        DB::statement("alter table work_file_item modify status enum($values) not null default 'in_office'");
    }

    public function down(): void
    {
        if (! Schema::hasTable('work_file')) {
            return;
        }

        // Anything left in the new state goes back to being in hand, which is
        // what it is: work that is not finished.
        DB::table('work_file')->where('status', 'partly_approved')->update(['status' => 'under_verification']);
        DB::table('work_file_item')->where('status', 'partly_approved')->update(['status' => 'under_verification']);

        $old = array_values(array_diff(self::STATUSES, ['partly_approved']));
        $values = "'".implode("','", $old)."'";

        DB::statement("alter table work_file modify status enum($values) not null default 'in_office'");
        DB::statement("alter table work_file_item modify status enum($values) not null default 'in_office'");
    }
};
