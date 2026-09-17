<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Approved papers going back to the customer.
 *
 * Until now the only way to record papers leaving the office was Return to
 * Customer, and a return is a refund: it credits the customer. Approved work is
 * finished work the customer pays for in full, so it was rightly kept off that
 * screen — which left the office with no way to say the RC and the NOC had
 * been collected.
 *
 * A date rather than a status, deliberately. Every money calculation here is
 * keyed on status, and a folder's status is worked out afresh from its works
 * every time it is saved; a "handed over" status would have to be taught to all
 * of them, and the next save would quietly turn it back into Approval Done
 * anyway. Where the work is and where the papers are are two different facts,
 * so they are two different columns.
 */
return new class extends Migration
{
    private const INDEX = 'work_file_status_handover_index';

    public function up(): void
    {
        Schema::table('work_file', function (Blueprint $table) {
            if (! Schema::hasColumn('work_file', 'handed_over_on')) {
                $table->date('handed_over_on')->nullable();
            }

            if (! Schema::hasColumn('work_file', 'handed_over_by')) {
                // Who recorded it. Kept if the account goes, as the status log's
                // user is: a lost login must not take the record with it.
                $table->foreignId('handed_over_by')->nullable()->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('work_file', 'collected_by')) {
                // Who took them away — "Rakesh, driver". For the day a customer
                // says they never got their RC. Office-only; never shown to them.
                $table->string('collected_by', 120)->nullable();
            }
        });

        if (! $this->indexExists()) {
            Schema::table('work_file', function (Blueprint $table) {
                // "Approved, papers not handed over" is the question the office
                // asks of this every day.
                $table->index(['status', 'handed_over_on'], self::INDEX);
            });
        }

        Schema::table('work_file_status_log', function (Blueprint $table) {
            if (! Schema::hasColumn('work_file_status_log', 'event')) {
                /*
                 * What happened, when it was something other than a move.
                 *
                 * A handover changes no status, so on the log it would read as a
                 * note — and telling it apart from a note by its wording is how a
                 * history ends up depending on nobody rewording a sentence.
                 * Null for everything that is a move or a plain note.
                 */
                $table->string('event', 30)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('work_file_status_log', function (Blueprint $table) {
            if (Schema::hasColumn('work_file_status_log', 'event')) {
                $table->dropColumn('event');
            }
        });

        Schema::table('work_file', function (Blueprint $table) {
            if ($this->indexExists()) {
                $table->dropIndex(self::INDEX);
            }

            if (Schema::hasColumn('work_file', 'handed_over_by')) {
                $table->dropConstrainedForeignId('handed_over_by');
            }

            foreach (['handed_over_on', 'collected_by'] as $column) {
                if (Schema::hasColumn('work_file', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /**
     * Read the index list directly: Schema::hasIndex() does not exist on 11.9,
     * which is what the server runs. See make_file_ledger_entries_unique.
     */
    private function indexExists(): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->whereRaw('TABLE_SCHEMA = database()')
            ->where('TABLE_NAME', 'work_file')
            ->where('INDEX_NAME', self::INDEX)
            ->exists();
    }
};
