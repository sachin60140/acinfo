<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a file costs beyond the vendor.
 *
 * A transfer challan, an affidavit, a notary's fee: money the office pays out
 * at the counter on a particular file, recorded nowhere until now. Every margin
 * this application has ever shown has therefore been too high by exactly the
 * amount nobody was tracking, and the files where it happens most are the ones
 * where the margin looked best.
 *
 * Office cash, and cost only. No party ledger entry: this money went out of the
 * till rather than to a vendor or on to a customer, so there is nobody for it to
 * be owed by or to. It raises what the file cost and lowers what it earned, and
 * that is all it does.
 *
 * Kept on the file rather than on each work. An affidavit is drawn for the
 * vehicle, not for the transfer as opposed to the hypothecation on the same
 * papers, and splitting one by hand across three works would be inventing a
 * division nobody made.
 */
return new class extends Migration
{
    /**
     * The two the office named, and the one every list of this kind needs.
     *
     * Seeded rather than left empty so the first expense can be entered without
     * setting up reference data first. More are added on the Expense Types
     * screen; none of these is special to the code.
     */
    private const SEED = [
        'Transfer Challan',
        'Affidavit',
        'Other',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('expense_type')) {
            Schema::create('expense_type', function (Blueprint $table) {
                $table->id();
                $table->string('name');

                // What this kind usually costs, filled in when one is added and
                // always overridable — a challan is not the same everywhere.
                // Null means there is no usual figure.
                $table->decimal('default_amount', 15, 2)->nullable();

                // Retired rather than deleted: an expense already recorded still
                // has to be able to say what kind it was.
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        foreach (self::SEED as $name) {
            if (! DB::table('expense_type')->where('name', $name)->exists()) {
                DB::table('expense_type')->insert([
                    'name' => $name,
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if (Schema::hasTable('work_file_expense')) {
            return;
        }

        Schema::create('work_file_expense', function (Blueprint $table) {
            $table->id();

            // Gone when the file is: an expense is a line of that file and
            // means nothing without it.
            $table->foreignId('work_file_id')->constrained('work_file')->cascadeOnDelete();

            // Restricted, not cascaded: retiring a type must never take the
            // money that was spent under it out of the accounts.
            $table->foreignId('expense_type_id')->constrained('expense_type');

            $table->decimal('amount', 15, 2);

            // The day the money left, which is not always the day somebody got
            // round to entering it.
            $table->date('spent_on');

            // What it was for, when the type alone does not say — the whole
            // reason "Other" exists.
            $table->string('remark', 255)->nullable();

            $table->timestamps();

            // Every read is "the expenses on this file", in file order.
            $table->index(['work_file_id', 'spent_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_file_expense');
        Schema::dropIfExists('expense_type');
    }
};
