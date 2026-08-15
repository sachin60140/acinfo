<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Part refunds on a returned file.
 *
 * Until now returning papers reversed the whole amount, which is only right when
 * nothing was done. Work often gets part way — fees paid, a trip made — and the
 * business keeps that part. These hold how much actually goes back.
 *
 * Null keeps the old meaning, a full reversal, so every file already recorded
 * stays exactly as it was and the common case still needs no typing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('work_file') || Schema::hasColumn('work_file', 'returned_amount')) {
            return;
        }

        Schema::table('work_file', function (Blueprint $table) {
            // How much of the customer's charge goes back to them.
            $table->decimal('returned_amount', 15, 2)->nullable()->after('returned_on');

            // How much of what was booked to the vendor is reversed.
            $table->decimal('vendor_returned_amount', 15, 2)->nullable()->after('vendor_returned_on');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('work_file', 'returned_amount')) {
            Schema::table('work_file', function (Blueprint $table) {
                $table->dropColumn(['returned_amount', 'vendor_returned_amount']);
            });
        }
    }
};
