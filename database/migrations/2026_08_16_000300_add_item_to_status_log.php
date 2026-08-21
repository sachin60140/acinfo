<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which job a timeline entry is about.
 *
 * A folder can hold several jobs now, and they move separately — so an entry
 * saying "Under Verification to Approval Done" is ambiguous on a file holding a
 * transfer and a hypothecation addition.
 *
 * It goes in its own column rather than into the remark. The remark is what a
 * person typed, and it is what the work report prints under Remarks; putting
 * machine text in front of it would turn that column into noise on the one
 * report that asked for it by name.
 *
 * Nullable, because entries written before jobs existed are about the folder,
 * and so is anything the file itself does.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('work_file_status_log') || Schema::hasColumn('work_file_status_log', 'work_file_item_id')) {
            return;
        }

        Schema::table('work_file_status_log', function (Blueprint $table) {
            $table->foreignId('work_file_item_id')->nullable()->after('work_file_id')
                ->constrained('work_file_item')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('work_file_status_log') && Schema::hasColumn('work_file_status_log', 'work_file_item_id')) {
            Schema::table('work_file_status_log', function (Blueprint $table) {
                $table->dropForeign(['work_file_item_id']);
                $table->dropColumn('work_file_item_id');
            });
        }
    }
};
