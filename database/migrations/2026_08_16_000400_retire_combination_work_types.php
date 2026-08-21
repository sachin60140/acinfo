<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retires the work types that name several works at once.
 *
 * "HPT + TR + HPA" was never a service. It was the only way to say that one
 * set of papers was for three jobs, back when a file could hold a single work
 * type — and it cost the business the answer it most wanted, because a folder
 * booked under that name could not say what the transfer earned, or that the
 * hypothecation addition was through while the transfer was not.
 *
 * A file holds its works as rows now, so the combinations have nothing left to
 * do. They are deactivated rather than deleted: files already booked against
 * them still point at them, the reports still name them, and a type that
 * vanished would take that history with it. Deactivated, they stay readable
 * everywhere and stop being offered for new work.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Matched on the name, because that is what makes one: a type whose
         * name joins two services with a plus is a combination whatever it is
         * called. Only active ones are touched, so the down() below can put
         * back exactly what it changed.
         */
        DB::table('work_type')
            ->where('is_active', 1)
            ->where('name', 'like', '%+%')
            ->update(['is_active' => 0]);
    }

    public function down(): void
    {
        DB::table('work_type')
            ->where('is_active', 0)
            ->where('name', 'like', '%+%')
            ->update(['is_active' => 1]);
    }
};
