<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a work type usually costs to have done.
 *
 * The existing default_rate is the other side of the same job: what a customer
 * is usually charged. Handing a file to a vendor had nothing to prefill from,
 * so the rate was typed every time or — more often — left blank and agreed
 * later, which is how files end up sitting unpriced.
 *
 * Two figures rather than one, because they are two different numbers and the
 * difference between them is the margin. An earlier attempt to prefill the
 * vendor amount from default_rate would have credited the vendor the full
 * customer charge and booked every file at nothing.
 *
 * Nullable, and it stays nullable. A rate that varies by vendor or by job has
 * no default worth storing, and a blank box has always meant "not agreed yet" —
 * that meaning does not change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('work_type') || Schema::hasColumn('work_type', 'default_vendor_rate')) {
            return;
        }

        Schema::table('work_type', function (Blueprint $table) {
            $table->decimal('default_vendor_rate', 15, 2)->nullable()->after('default_rate');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('work_type') && Schema::hasColumn('work_type', 'default_vendor_rate')) {
            Schema::table('work_type', function (Blueprint $table) {
                $table->dropColumn('default_vendor_rate');
            });
        }
    }
};
