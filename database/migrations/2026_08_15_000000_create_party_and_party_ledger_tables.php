<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor & Customer ledgers.
 *
 * Deliberately self-contained: two brand new tables that no existing table
 * references and that reference nothing existing. The client / client_ledger /
 * payment_type tables carry live financial data and are left untouched, so this
 * feature can be rolled out (or dropped) without any risk to them.
 *
 * Vendors and customers share one table because they behave identically — the
 * only difference is which side of the balance is normal — and sharing lets a
 * single statement implementation serve both instead of two that drift apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('party')) {
            Schema::create('party', function (Blueprint $table) {
                $table->id();
                $table->enum('party_type', ['customer', 'vendor'])->index();
                $table->string('name');
                $table->string('mobile', 15);
                // Frequently the same number as `mobile`, but often not — the
                // business number takes calls while a personal one takes WhatsApp.
                $table->string('whatsapp', 15)->nullable();
                $table->string('address')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                // The same person can legitimately be both a customer and a
                // vendor, so the number is only unique within one role.
                $table->unique(['party_type', 'mobile']);
            });
        }

        if (! Schema::hasTable('party_ledger')) {
            Schema::create('party_ledger', function (Blueprint $table) {
                $table->id();
                $table->foreignId('party_id')->constrained('party');

                $table->date('txn_date');

                // Stored as an explicit side plus a positive amount rather than a
                // signed figure, so a row means the same thing on screen, in an
                // export and in a raw SQL query. Balance = SUM(debit) - SUM(credit).
                $table->enum('entry_type', ['debit', 'credit']);
                $table->decimal('amount', 15, 2);

                // Free text rather than a foreign key to payment_type: this module
                // owns all of its own data and never reads from the existing tables.
                $table->string('payment_mode', 50)->nullable();
                $table->string('ref_no', 50)->nullable();
                $table->string('particular');
                $table->timestamps();

                // Statements are always "one party, ordered by date".
                $table->index(['party_id', 'txn_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('party_ledger');
        Schema::dropIfExists('party');
    }
};
