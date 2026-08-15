<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The vehicle registration number a file is about.
 *
 * Deliberately not unique: the same vehicle comes back for a second job, and a
 * transfer followed later by a hypothecation termination is two files against
 * one number. That repetition is the point — it lets the receive screen show
 * what was done on that vehicle before, and at what price, so a new job can be
 * checked against history before it is booked.
 *
 * Indexed because that lookup runs on every keystroke-completion of the field.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('work_file') || Schema::hasColumn('work_file', 'registration_no')) {
            return;
        }

        Schema::table('work_file', function (Blueprint $table) {
            $table->string('registration_no', 20)->nullable()->after('work_type_id');
            $table->index('registration_no');
        });

        // Files recorded before this column existed put the vehicle number in
        // the free-text details field, which is exactly where it still is.
        // Carrying it across means their history is searchable too, rather than
        // the lookup silently starting from empty.
        DB::table('work_file')
            ->whereNull('registration_no')
            ->whereNotNull('description')
            ->where('description', '!=', '')
            // Registration numbers are letters and digits only; anything with a
            // space or punctuation is a description, not a number.
            ->whereRaw("description REGEXP '^[A-Za-z0-9-]{6,20}$'")
            ->update(['registration_no' => DB::raw('UPPER(REPLACE(description, "-", ""))')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('work_file', 'registration_no')) {
            Schema::table('work_file', function (Blueprint $table) {
                $table->dropIndex(['registration_no']);
                $table->dropColumn('registration_no');
            });
        }
    }
};
