<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a file's sticker says that the file did not hold until now.
 *
 * Asked for by the owner on 2026-11-19: a 4x6 thermal sticker on every file
 * received, with the customer and their mobile, the vehicle, the vehicle
 * owner's mobile, the works, and for some works the people they are about.
 * An HPT (hypothecation terminated) names its lender, an HPA (hypothecation
 * added) its financer, a TO (transfer of ownership) its seller and buyer.
 *
 * Which works ask for which is a setting on the work type, work_type.asks_for,
 * rather than a reading of its name: types are named by the office and can be
 * renamed. The three types named exactly HPT, HPA and TO are given theirs here
 * to start with, and only where nothing has been chosen; any other is set on
 * the Work Types screen.
 *
 * Every column is new and nullable, and added only where it is missing, so
 * this is safe to run again.
 */
return new class extends Migration
{
    private const FILE_COLUMNS = [
        // [name, length, after]
        ['owner_mobile', 15, 'registration_no'],
        ['lender_name', 120, 'owner_mobile'],
        ['financer_name', 120, 'lender_name'],
        ['seller_name', 120, 'financer_name'],
        ['seller_mobile', 15, 'seller_name'],
        ['buyer_name', 120, 'seller_mobile'],
        ['buyer_mobile', 15, 'buyer_name'],
    ];

    public function up(): void
    {
        Schema::table('work_type', function (Blueprint $table) {
            if (! Schema::hasColumn('work_type', 'asks_for')) {
                $table->string('asks_for', 20)->nullable()->after('default_vendor_rate');
            }
        });

        Schema::table('work_file', function (Blueprint $table) {
            foreach (self::FILE_COLUMNS as [$name, $length, $after]) {
                if (! Schema::hasColumn('work_file', $name)) {
                    $table->string($name, $length)->nullable()->after($after);
                }
            }
        });

        foreach (['HPT' => 'lender', 'HPA' => 'financer', 'TO' => 'parties'] as $name => $asks) {
            DB::table('work_type')
                ->whereRaw('UPPER(TRIM(name)) = ?', [$name])
                ->whereNull('asks_for')
                ->update(['asks_for' => $asks]);
        }
    }

    public function down(): void
    {
        Schema::table('work_file', function (Blueprint $table) {
            foreach (array_reverse(self::FILE_COLUMNS) as [$name]) {
                if (Schema::hasColumn('work_file', $name)) {
                    $table->dropColumn($name);
                }
            }
        });

        Schema::table('work_type', function (Blueprint $table) {
            if (Schema::hasColumn('work_type', 'asks_for')) {
                $table->dropColumn('asks_for');
            }
        });
    }
};
