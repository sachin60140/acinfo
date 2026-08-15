<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work files received from customers.
 *
 * A file is one job: it arrives from a customer, it is charged to that customer
 * (debit), and it may be handed to a vendor to actually do (credit). Holding
 * both sides on one row is what makes the margin on a job knowable — the two
 * amounts belong to the same piece of work, so splitting them across unrelated
 * records would throw that away.
 *
 * The money itself still lives in party_ledger, not here. The ledger stays the
 * single source of truth for every balance; a file only points at the entries it
 * caused, so nothing can be counted twice.
 *
 * Like the party tables, this touches nothing that predates the ledger module —
 * client, client_ledger and payment_type are neither read nor altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('work_type')) {
            Schema::create('work_type', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                // Pre-fills the amount on the file form. Nullable because plenty
                // of work is quoted per job rather than at a standard rate.
                $table->decimal('default_rate', 15, 2)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('work_file')) {
            Schema::create('work_file', function (Blueprint $table) {
                $table->id();
                // Nullable so a generated number can be derived from the id the
                // insert just produced, inside the same transaction. Picking the
                // number up front would mean reading max(id) and racing on it.
                $table->string('file_no', 30)->nullable()->unique();
                $table->date('received_date');

                $table->foreignId('work_type_id')->constrained('work_type');
                $table->foreignId('customer_id')->constrained('party');
                $table->decimal('customer_amount', 15, 2)->default(0);

                // The vendor side is filled in later, or never — a job done
                // in-house has no vendor and no cost against it.
                $table->foreignId('vendor_id')->nullable()->constrained('party');
                $table->decimal('vendor_amount', 15, 2)->nullable();
                $table->date('vendor_date')->nullable();

                $table->enum('status', ['pending', 'in_progress', 'completed', 'delivered'])->default('pending');
                $table->string('description')->nullable();
                $table->string('remarks')->nullable();
                $table->timestamps();

                $table->index(['status', 'received_date']);
                $table->index('customer_id');
                $table->index('vendor_id');
            });
        }

        // Ties a ledger entry back to the file that generated it, so the file can
        // keep its own entries in step when it is edited and the statement can
        // link each line to the job behind it. Nullable: entries typed straight
        // into the ledger have no file, and always will not.
        if (Schema::hasTable('party_ledger') && ! Schema::hasColumn('party_ledger', 'work_file_id')) {
            Schema::table('party_ledger', function (Blueprint $table) {
                $table->foreignId('work_file_id')->nullable()->after('party_id')->constrained('work_file');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('party_ledger', 'work_file_id')) {
            Schema::table('party_ledger', function (Blueprint $table) {
                $table->dropForeign(['work_file_id']);
                $table->dropColumn('work_file_id');
            });
        }

        Schema::dropIfExists('work_file');
        Schema::dropIfExists('work_type');
    }
};
