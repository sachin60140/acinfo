<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A login of their own, for the parties that want one.
 *
 * Customers ring to ask two things: where their file has reached, and what they
 * owe. Both answers already exist in this database and neither is reachable
 * without the office reading it out. This is the column that lets a customer
 * come and look.
 *
 * Nullable, and null for every row the day it ships: a party without a password
 * cannot sign in, which is the correct state for all of them until the office
 * issues one deliberately. Vendors get the columns too and simply never have
 * them filled — cheaper than a second table, and it leaves the door open.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('party')) {
            return;
        }

        Schema::table('party', function (Blueprint $table) {
            if (! Schema::hasColumn('party', 'password')) {
                // The hash, never the password. Sized for bcrypt with room for
                // whatever the default hasher becomes.
                $table->string('password', 255)->nullable()->after('address');
            }

            if (! Schema::hasColumn('party', 'last_login_at')) {
                // So the office can see who has actually started using this and
                // chase the rest, rather than assuming a password handed over
                // was a password used.
                $table->timestamp('last_login_at')->nullable()->after('password');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('party')) {
            return;
        }

        Schema::table('party', function (Blueprint $table) {
            foreach (['password', 'last_login_at'] as $column) {
                if (Schema::hasColumn('party', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
