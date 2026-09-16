<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the office calls a document, as opposed to what the scanner called it.
 *
 * A file's papers arrive as scan_00123.pdf and IMG-20260912-WA0004.pdf. The
 * office knows them as "RC", "Form 29", "NOC" — and so does the customer, who
 * is now offered every one of them rather than only the newest and has to tell
 * them apart from a list. So each document carries a name somebody chose.
 *
 * Nullable, because every document uploaded before this has none, and inventing
 * one here would be putting a guess on a customer's screen as if the office had
 * written it. Until someone names them they are shown under the name they
 * arrived with; see WorkFileDocumentModel::displayName().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('work_file_document', 'title')) {
            Schema::table('work_file_document', function (Blueprint $table) {
                // Long enough for "Form 29 (Transfer of Ownership) — signed copy",
                // short enough to sit on one line of a phone screen.
                $table->string('title', 120)->nullable()->after('original_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('work_file_document', 'title')) {
            Schema::table('work_file_document', function (Blueprint $table) {
                $table->dropColumn('title');
            });
        }
    }
};
