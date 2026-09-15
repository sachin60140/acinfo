<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The papers themselves, scanned against a file.
 *
 * A file has carried one approval screenshot per work — evidence that a
 * particular job came through. This is a different thing: the documents that
 * belong to the file, several of them, and the ones a customer asks to be sent.
 *
 * Several rather than one, because a document gets revised. A corrected form is
 * a new upload and not an overwrite: the earlier one is what was acted on at the
 * time, and a file that silently replaced it would lose the record of what the
 * office actually sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('work_file') || Schema::hasTable('work_file_document')) {
            return;
        }

        Schema::create('work_file_document', function (Blueprint $table) {
            $table->id();

            // Gone when the file is: a scan of a file's papers means nothing
            // without the file.
            $table->foreignId('work_file_id')->constrained('work_file')->cascadeOnDelete();

            // Where it landed under public/, the same shape as an approval
            // screenshot's path.
            $table->string('path', 255);

            /*
             * What it was called when it arrived. Never used to build a path —
             * the stored name is generated — but it is what the office
             * recognises and what the customer should receive it as, rather
             * than a string of hex.
             */
            $table->string('original_name', 255);

            $table->unsignedBigInteger('size')->default(0);

            // Who put it there, for the same reason the status log records a
            // user: a document nobody can account for is worse than none.
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Every read is "this file's documents, newest first".
            $table->index(['work_file_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_file_document');
    }
};
