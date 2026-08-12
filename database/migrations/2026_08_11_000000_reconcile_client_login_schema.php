<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('client', 'password')) {
            Schema::table('client', function (Blueprint $table) {
                $table->string('password')->nullable()->after('mobile');
            });
        }

        if (! $this->hasUniqueIndexOn('client', 'mobile')) {
            Schema::table('client', function (Blueprint $table) {
                $table->unique('mobile', 'client_mobile_login_unique');
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndexNamed('client', 'client_mobile_login_unique')) {
            Schema::table('client', function (Blueprint $table) {
                $table->dropUnique('client_mobile_login_unique');
            });
        }

        // Keep the password column on rollback to avoid deleting credentials.
    }

    /**
     * Queried directly rather than through Schema::hasIndex(), which is not
     * present in every Laravel 11.x release — including 11.9, which production
     * runs. This works on any version.
     */
    private function hasUniqueIndexOn(string $table, string $column): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->where('NON_UNIQUE', 0)
            ->exists();
    }

    private function hasIndexNamed(string $table, string $index): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $index)
            ->exists();
    }
};
