<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Taking back an entry typed by mistake, and saying why.
 *
 * Nothing could be changed once it was in the ledger: a receipt typed for the
 * wrong customer, or 50,000 for 5,000, stayed there, and the only way round it
 * was a second entry nobody could tell apart from a real one.
 *
 * A reversal is a real row — the opposite side, the same amount, dated the day
 * it is made — so every balance, statement and total stays right with no
 * change, and a statement already sent to a customer still says what it said.
 * It says which entry it takes back, and it can take back only one, once:
 * reverses_id is unique. Why is kept in a note the office reads and the
 * customer never does.
 *
 * entry_kind says what sort of row this is when it is not an ordinary entry:
 * a reversal now, and the write-offs and set-offs that come after it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('party_ledger', function (Blueprint $table) {
            if (! Schema::hasColumn('party_ledger', 'entry_kind')) {
                $table->string('entry_kind', 20)->nullable()->after('particular');
            }

            if (! Schema::hasColumn('party_ledger', 'note')) {
                // The office's own reason. Never shown to the customer.
                $table->string('note', 255)->nullable()->after('entry_kind');
            }

            if (! Schema::hasColumn('party_ledger', 'reverses_id')) {
                $table->foreignId('reverses_id')->nullable()->after('note')
                    ->constrained('party_ledger')->restrictOnDelete();
                // Once: an entry can be taken back by one reversal and no more.
                $table->unique('reverses_id', 'party_ledger_reverses_unique');
            }
        });
    }

    /**
     * Refused while any entry has been reversed: dropping the link would leave
     * both rows standing as two unexplained entries.
     */
    public function down(): void
    {
        if (Schema::hasColumn('party_ledger', 'reverses_id')
            && DB::table('party_ledger')->whereNotNull('reverses_id')->exists()) {
            throw new RuntimeException(
                'Entries have been reversed. Rolling this back would leave each reversal and the entry it took back '
                .'as two unexplained rows, so it is refused while any reversal exists. There is no way to undo a '
                .'reversal from the application; removing the pairs means deleting ledger rows by hand, knowing that '
                .'every balance and statement they touch will change.'
            );
        }

        Schema::table('party_ledger', function (Blueprint $table) {
            if (Schema::hasColumn('party_ledger', 'reverses_id')) {
                // The foreign key leans on the unique index, so it goes first.
                $table->dropForeign(['reverses_id']);
                $table->dropUnique('party_ledger_reverses_unique');
                $table->dropColumn('reverses_id');
            }

            foreach (['note', 'entry_kind'] as $column) {
                if (Schema::hasColumn('party_ledger', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
