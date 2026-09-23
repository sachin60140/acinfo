<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One person who is both a customer and a vendor, and clearing what they owe
 * against what they are owed.
 *
 * A dealer who also does work for the office owes it for files and is owed for
 * work, on two accounts, and nothing cleared one against the other: the office
 * either paid out and took the money straight back, or typed two unexplained
 * "Adjustment" entries nobody could tell were one act.
 *
 * Two things arrive here.
 *
 * The link: the office says, by hand, that this customer is that vendor. It is
 * never guessed from a mobile number — two people share a phone, and a typo
 * would set one person's debt off against a stranger's money. The customer's
 * row holds it, one vendor to one customer (unique), with who said so and when.
 *
 * The pair: a set-off is two real rows, a credit on the customer and a debit
 * on the vendor for the same amount on the same day, each naming the other in
 * setoff_with_id. Every balance, statement and file then reads right with no
 * change. Each row keeps its partner itself, so a set-off already made stays
 * whole — and can be taken back whole — whatever happens to the link later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('party', function (Blueprint $table) {
            if (! Schema::hasColumn('party', 'linked_vendor_id')) {
                // On the customer's row: the vendor account that is the same person.
                $table->foreignId('linked_vendor_id')->nullable()->after('is_active')
                    ->constrained('party')->restrictOnDelete();
                // One vendor is one customer's, and no one else's.
                $table->unique('linked_vendor_id', 'party_linked_vendor_unique');
                $table->foreignId('linked_by')->nullable()->after('linked_vendor_id')
                    ->constrained('users')->nullOnDelete();
                $table->timestamp('linked_at')->nullable()->after('linked_by');
            }
        });

        Schema::table('party_ledger', function (Blueprint $table) {
            if (! Schema::hasColumn('party_ledger', 'setoff_with_id')) {
                $table->foreignId('setoff_with_id')->nullable()->after('reverses_id')
                    ->constrained('party_ledger')->restrictOnDelete();
                // Each half has one partner, and is no other half's partner.
                $table->unique('setoff_with_id', 'party_ledger_setoff_unique');
            }
        });
    }

    /**
     * Refused while any set-off exists: dropping the pair would leave its two
     * halves as unexplained entries on two accounts, and the link would go
     * with nothing to say who was whom.
     */
    public function down(): void
    {
        if (Schema::hasColumn('party_ledger', 'setoff_with_id')
            && DB::table('party_ledger')->whereNotNull('setoff_with_id')->exists()) {
            throw new RuntimeException(
                'Customers have been set off against their vendor accounts. Rolling this back would leave each half '
                .'as an unexplained entry on two statements, so it is refused while any set-off exists. Reverse the '
                .'set-offs from the statement first if that is really what is wanted.'
            );
        }

        Schema::table('party_ledger', function (Blueprint $table) {
            if (Schema::hasColumn('party_ledger', 'setoff_with_id')) {
                // The foreign key leans on the unique index, so it goes first.
                $table->dropForeign(['setoff_with_id']);
                $table->dropUnique('party_ledger_setoff_unique');
                $table->dropColumn('setoff_with_id');
            }
        });

        Schema::table('party', function (Blueprint $table) {
            if (Schema::hasColumn('party', 'linked_vendor_id')) {
                $table->dropForeign(['linked_vendor_id']);
                $table->dropUnique('party_linked_vendor_unique');
                $table->dropColumn('linked_vendor_id');
                $table->dropConstrainedForeignId('linked_by');
                $table->dropColumn('linked_at');
            }
        });
    }
};
