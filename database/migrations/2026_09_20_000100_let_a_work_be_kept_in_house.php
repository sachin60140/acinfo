<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A work the office is doing itself.
 *
 * Since a folder's works each go to their own vendor, a folder can hold a
 * transfer that went to one agent, a hypothecation addition that went to
 * another, and a termination the office is doing at its own counter. The first
 * two leave. The third never does.
 *
 * Nothing said so. Give to Vendor offers every work that has no vendor and is
 * not finished, which is exactly what a work being done here looks like, so
 * that folder sat on the list of work waiting to go out for as long as the
 * termination took — on a to-do list, next to the work that really is waiting.
 * It cleared itself only when the work was approved.
 *
 * A date rather than a flag, for the reason handed_over_on is one: it is worth
 * knowing when the office decided, and a date answers both questions with one
 * column. Null means nobody has said either way, which is every work there has
 * ever been.
 *
 * And not a status. A work's status is worked out afresh from the papers and
 * the handovers every time its folder is saved, so "in house" would have to be
 * taught to every one of those sums and would be overwritten by the next save
 * regardless. Where the work is being done and how far along it is are two
 * different facts, so they are two different columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_file_item', function (Blueprint $table) {
            if (! Schema::hasColumn('work_file_item', 'kept_in_house_on')) {
                // Beside the vendor columns it is the answer to: this is the
                // other thing that can happen to a work when it is given out.
                $table->date('kept_in_house_on')->nullable()->after('vendor_returned_on');
            }
        });
    }

    public function down(): void
    {
        Schema::table('work_file_item', function (Blueprint $table) {
            if (Schema::hasColumn('work_file_item', 'kept_in_house_on')) {
                $table->dropColumn('kept_in_house_on');
            }
        });
    }
};
