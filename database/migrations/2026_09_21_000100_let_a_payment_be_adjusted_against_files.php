<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which files a payment was for.
 *
 * Money arrived against the account and nothing said what it covered, so the
 * oldest charge was treated as paid first. That is still what happens to money
 * nobody says anything about. But a dealer who pays 10,000 "for F-00050 and
 * F-00057" meant those two files, and now the office can record it: one row
 * here for each file a payment is adjusted against, and the rest of the
 * payment settles the oldest charges as before.
 *
 * Keyed on the file and the party, never on the file's charge in the ledger.
 * Those rows are the file's own and are deleted and written again as it moves —
 * cancelled and un-cancelled, re-priced to nothing and back, given to another
 * customer — and an allocation pointing at one would be orphaned or, worse,
 * follow the row to somebody else's account. The file and the party who paid
 * are the facts that do not change.
 *
 * Rows are released, never deleted: a payment adjusted again later keeps the
 * record of what it was first said to cover, and who changed it.
 *
 * And, beside it, who typed each payment in. Nothing recorded that.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('party_ledger_allocation')) {
            Schema::create('party_ledger_allocation', function (Blueprint $table) {
                $table->id();

                // The payment: a customer's receipt or a payment to a vendor,
                // typed on the Entry screen. Never one of a file's own rows.
                $table->foreignId('entry_id')->constrained('party_ledger')->restrictOnDelete();

                // Always the payment's own party. Stored so a file's bill is
                // looked up for the one who paid, and no one else.
                $table->foreignId('party_id')->constrained('party')->restrictOnDelete();

                $table->foreignId('work_file_id')->constrained('work_file')->restrictOnDelete();
                $table->decimal('amount', 15, 2);

                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

                // Set when the payment is adjusted again, or taken back.
                $table->timestamp('released_at')->nullable();
                $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();

                $table->timestamps();

                $table->index('entry_id', 'party_ledger_allocation_entry_index');
                $table->index(['party_id', 'work_file_id'], 'party_ledger_allocation_party_file_index');
            });
        }

        Schema::table('party_ledger', function (Blueprint $table) {
            if (! Schema::hasColumn('party_ledger', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('particular')->constrained('users')->nullOnDelete();
            }
        });
    }

    /**
     * Refused while any payment is adjusted against a file: dropping the table
     * would quietly send that money back to the oldest charges, and every
     * report would change with nothing to say why.
     */
    public function down(): void
    {
        if (Schema::hasTable('party_ledger_allocation')
            && DB::table('party_ledger_allocation')->whereNull('released_at')->exists()) {
            throw new RuntimeException(
                'Payments are adjusted against files. Rolling this back would move that money back to the oldest '
                .'charges. Release the adjustments first if that is really what is wanted.'
            );
        }

        Schema::dropIfExists('party_ledger_allocation');

        Schema::table('party_ledger', function (Blueprint $table) {
            if (Schema::hasColumn('party_ledger', 'created_by')) {
                $table->dropConstrainedForeignId('created_by');
            }
        });
    }
};
