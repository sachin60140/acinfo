<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A dated note against every status change.
 *
 * Kept as its own table rather than a column on work_file, because a file moves
 * through five or six states and the reason it sat in Paper Pendency is worth
 * as much a month later as it was on the day. A single column would have each
 * change erase the last one's explanation.
 *
 * Statuses are stored as plain strings, not the enum work_file uses: the log is
 * a record of what was true at the time, and it must keep reading correctly
 * after the enum is next changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('work_file_status_log')) {
            return;
        }

        Schema::create('work_file_status_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_file_id')->constrained('work_file')->cascadeOnDelete();

            // Null on the opening entry: a file that has just arrived came from
            // nowhere. from === to means a note added without moving the file.
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);

            $table->string('remark')->nullable();

            // Who made the change. Nullable because the users table is not
            // guaranteed to still hold them, and a lost account must not take the
            // history of the file with it.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['work_file_id', 'id']);
        });

        // Give every existing file an opening entry, so the timeline starts where
        // the file did rather than at whatever happens to it next.
        $existing = DB::table('work_file')->select('id', 'status', 'created_at')->get();

        foreach ($existing->chunk(200) as $chunk) {
            DB::table('work_file_status_log')->insert($chunk->map(fn ($file) => [
                'work_file_id' => $file->id,
                'from_status' => null,
                'to_status' => $file->status,
                'remark' => 'Recorded when status history began',
                'user_id' => null,
                'created_at' => $file->created_at,
                'updated_at' => $file->created_at,
            ])->all());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('work_file_status_log');
    }
};
