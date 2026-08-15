<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One ledger entry per file per role, enforced by the database.
 *
 * syncSide() looks an entry up and inserts it if it finds none. Two saves of
 * the same file landing together can both find nothing and both insert, and the
 * file's amount is then posted twice — a customer charged 5,000 shows 10,000
 * with no clue why. This makes that impossible rather than merely unlikely.
 *
 * Manual ledger entries carry no work_file_id and no file_role. MySQL allows
 * any number of rows where an indexed column is NULL, so they are unaffected.
 */
return new class extends Migration
{
    private const INDEX = 'party_ledger_file_role_unique';

    public function up(): void
    {
        if (! Schema::hasTable('party_ledger') || ! Schema::hasColumn('party_ledger', 'file_role')) {
            return;
        }

        if ($this->indexExists()) {
            return;
        }

        // Adding a unique index over existing duplicates fails outright and
        // aborts the deploy. Say what is wrong instead of dying on a constraint
        // error, since fixing it means deciding which entry is the real one.
        $duplicates = DB::table('party_ledger')
            ->whereNotNull('work_file_id')
            ->whereNotNull('file_role')
            ->select('work_file_id', 'file_role', DB::raw('COUNT(*) as total'))
            ->groupBy('work_file_id', 'file_role')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $detail = $duplicates
                ->map(fn ($row) => "work_file {$row->work_file_id} / {$row->file_role} x{$row->total}")
                ->implode(', ');

            throw new RuntimeException(
                'party_ledger already holds duplicate entries for the same file and role, so a '
                ."unique index cannot be added: {$detail}. Decide which entry is correct, delete "
                .'the others, then run this migration again.'
            );
        }

        Schema::table('party_ledger', function (Blueprint $table) {
            $table->unique(['work_file_id', 'file_role'], self::INDEX);
        });
    }

    public function down(): void
    {
        if ($this->indexExists()) {
            Schema::table('party_ledger', function (Blueprint $table) {
                $table->dropUnique(self::INDEX);
            });
        }
    }

    /**
     * Read the index list directly: Schema has no portable "does this index
     * exist" call on 11.9 or on 13.
     */
    private function indexExists(): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->whereRaw('TABLE_SCHEMA = database()')
            ->where('TABLE_NAME', 'party_ledger')
            ->where('INDEX_NAME', self::INDEX)
            ->exists();
    }
};
