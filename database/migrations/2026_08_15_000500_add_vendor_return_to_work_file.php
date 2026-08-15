<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Papers coming back from a vendor.
 *
 * The mirror of Paper Returned to Customer, and it works the same way: the
 * credit that was booked when the vendor took the file stays, and a matching
 * debit is added beside it. The vendor's statement then reads "booked 3,500 on
 * 2 Apr, returned 15 Apr" and nets to nothing owed. Deleting the credit would
 * leave the vendor's own records disagreeing with yours about what was ever
 * agreed.
 *
 * The file itself goes back to In Office, ready to be worked or given out again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('work_file') && ! Schema::hasColumn('work_file', 'vendor_returned_on')) {
            Schema::table('work_file', function (Blueprint $table) {
                $table->date('vendor_returned_on')->nullable()->after('vendor_date');
            });
        }

        if (! Schema::hasTable('party_ledger') || ! Schema::hasColumn('party_ledger', 'file_role')) {
            return;
        }

        // file_role gains a fourth slot. Read the members from the column
        // definition; no schema builder call reports them on 11.9 or on 13.
        $column = DB::selectOne(
            'select COLUMN_TYPE as column_type from information_schema.COLUMNS
             where TABLE_SCHEMA = database() and TABLE_NAME = ? and COLUMN_NAME = ?',
            ['party_ledger', 'file_role']
        );

        if ($column && ! str_contains($column->column_type, "'vendor_return'")) {
            DB::statement("ALTER TABLE party_ledger MODIFY file_role
                ENUM('customer','vendor','customer_return','vendor_return') NULL");
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('party_ledger', 'file_role')) {
            // Those entries have no home in the narrower enum, and MySQL would
            // write an empty string rather than refuse.
            DB::table('party_ledger')->where('file_role', 'vendor_return')->delete();

            DB::statement("ALTER TABLE party_ledger MODIFY file_role
                ENUM('customer','vendor','customer_return') NULL");
        }

        if (Schema::hasColumn('work_file', 'vendor_returned_on')) {
            Schema::table('work_file', function (Blueprint $table) {
                $table->dropColumn('vendor_returned_on');
            });
        }
    }
};
