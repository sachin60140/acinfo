<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

        if (! Schema::hasIndex('client', ['mobile'], 'unique')) {
            Schema::table('client', function (Blueprint $table) {
                $table->unique('mobile', 'client_mobile_login_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('client', 'client_mobile_login_unique')) {
            Schema::table('client', function (Blueprint $table) {
                $table->dropUnique('client_mobile_login_unique');
            });
        }

        // Keep the password column on rollback to avoid deleting credentials.
    }
};
