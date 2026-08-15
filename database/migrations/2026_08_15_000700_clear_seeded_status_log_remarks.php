<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clears the placeholder text written when status history was introduced.
 *
 * Backfilling every existing file with an opening entry was right — a timeline
 * should start where the file did. Giving that entry a remark was not: it is
 * shown as "the most recent remark on this file", so every untouched file
 * appeared to carry a note from its operator that nobody wrote.
 *
 * The entry itself stays, so the timeline still opens where the file did. Only
 * the wording goes.
 */
return new class extends Migration
{
    private const SEEDED = 'Recorded when status history began';

    public function up(): void
    {
        if (! Schema::hasTable('work_file_status_log')) {
            return;
        }

        // Scoped to the opening entry with no user against it, so a remark a
        // person happened to type with the same words is left alone.
        DB::table('work_file_status_log')
            ->where('remark', self::SEEDED)
            ->whereNull('from_status')
            ->whereNull('user_id')
            ->update(['remark' => null]);
    }

    public function down(): void
    {
        // Deliberately not reversed: restoring the text would put words back in
        // an operator's mouth, which is the problem this removed.
    }
};
