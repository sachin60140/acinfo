<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a Cancelled status to work files.
 *
 * A file entered in error needs a way out that an accountant can live with.
 * Deleting the row would make the file number vanish from a numbered sequence,
 * which is exactly what a reader takes as evidence something was hidden. Marking
 * it cancelled keeps the record and its number, and drops only the ledger
 * entries it had caused — so it stops affecting balances without pretending it
 * never existed. Un-cancelling puts the entries back.
 *
 * A separate migration rather than an edit to the one that created the table,
 * because that one has already run against real data.
 */
return new class extends Migration
{
    private const STATUSES = "'pending','in_progress','completed','delivered','cancelled'";

    public function up(): void
    {
        if (! Schema::hasTable('work_file') || $this->hasCancelled()) {
            return;
        }

        DB::statement('ALTER TABLE work_file MODIFY status ENUM('.self::STATUSES.") NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        if (! Schema::hasTable('work_file') || ! $this->hasCancelled()) {
            return;
        }

        // Anything still cancelled has no valid value in the narrower enum, and
        // MySQL would silently write an empty string rather than refuse.
        DB::table('work_file')->where('status', 'cancelled')->update(['status' => 'pending']);

        DB::statement("ALTER TABLE work_file MODIFY status ENUM('pending','in_progress','completed','delivered') NOT NULL DEFAULT 'pending'");
    }

    /**
     * Read the enum's members straight from the column definition — there is no
     * schema builder call that can report them, on 11.9 or on 13.
     */
    private function hasCancelled(): bool
    {
        $column = DB::selectOne(
            'select COLUMN_TYPE as column_type from information_schema.COLUMNS
             where TABLE_SCHEMA = database() and TABLE_NAME = ? and COLUMN_NAME = ?',
            ['work_file', 'status']
        );

        return $column && str_contains($column->column_type, "'cancelled'");
    }
};
