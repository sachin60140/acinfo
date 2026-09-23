<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Figures the office sets for itself, and the record of who set them.
 *
 * The first is the most that may be written off — given up on a bill — at one
 * time. A figure like that cannot live in the code: it is the owner's to
 * change, on a host with no composer and no editor, and changing it is exactly
 * the moment somebody will later ask "who raised it, and when".
 *
 * So the table is written to and never updated: the value in force is the
 * newest row for its key, and every figure it ever held is still there with the
 * person who set it. One table rather than a settings table and a log beside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('office_setting', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60);
            $table->string('value', 255)->nullable();
            // Null for the figure this migration starts with, and for anyone
            // whose login is removed later.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            // The newest row for a key is the one in force; this is the read.
            $table->index(['key', 'id'], 'office_setting_key_index');
        });

        // The figure the owner asked for, as the first entry in its own history.
        DB::table('office_setting')->insert([
            'key' => 'writeoff_cap',
            'value' => '500.00',
            'created_at' => now(),
        ]);
    }

    /**
     * Rolling back drops the figures. Nothing is lost that the office cannot
     * type again, and no money row points at this table.
     */
    public function down(): void
    {
        Schema::dropIfExists('office_setting');
    }
};
