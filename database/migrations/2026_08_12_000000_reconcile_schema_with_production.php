<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brings the migrations back in line with the schema that production has actually
 * been running on. Three columns drifted apart from what the original migrations
 * created, so a fresh `artisan migrate` produced a database the application could
 * not use:
 *
 *   - client_ledger was created with `name` and no `client_id`, while every write
 *     in the app sets `client_id`. Saving a receipt or payment failed outright
 *     with "Unknown column 'client_id' in 'field list'".
 *   - users was created without `user_type`, which AdminMiddleware requires. On a
 *     fresh install `null == 1` is false, so the admin panel was unreachable even
 *     with correct credentials.
 *   - payment_type was created as `Payment_mode` rather than `payment_mode`. MySQL
 *     column names are case-insensitive so this worked, but the drift is real.
 *
 * Every step is guarded, so this is a no-op against the existing production
 * database and only does work on a freshly migrated one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('client_ledger', 'client_id')) {
            Schema::table('client_ledger', function (Blueprint $table) {
                $table->unsignedBigInteger('client_id')->after('id')->index();
            });
        }

        // The original `name` column was superseded by client_id and is unused by
        // the application. Only drop it where client_id is already in place, so
        // this can never run against a table that still depends on `name`.
        if (Schema::hasColumn('client_ledger', 'name') && Schema::hasColumn('client_ledger', 'client_id')) {
            Schema::table('client_ledger', function (Blueprint $table) {
                $table->dropColumn('name');
            });
        }

        if (! Schema::hasColumn('users', 'user_type')) {
            Schema::table('users', function (Blueprint $table) {
                // 1 = admin. AdminMiddleware gates the whole admin area on this.
                $table->integer('user_type')->default(0)->after('email');
            });
        }

        // Compare against the literal column listing: Schema::hasColumn() folds
        // case, so it can never tell these two spellings apart.
        $paymentTypeColumns = Schema::getColumnListing('payment_type');
        if (in_array('Payment_mode', $paymentTypeColumns, true) && ! in_array('payment_mode', $paymentTypeColumns, true)) {
            Schema::table('payment_type', function (Blueprint $table) {
                $table->renameColumn('Payment_mode', 'payment_mode');
            });
        }
    }

    public function down(): void
    {
        // Deliberately not reversed. Rolling these columns back would delete live
        // ledger relationships and lock the admin out of the panel again.
    }
};
