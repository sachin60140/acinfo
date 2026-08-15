<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the placeholder statuses with the ones the work actually moves
 * through, and adds the two things they need:
 *
 *   approval_screenshot  Approval Done has to be evidenced, so the proof lives
 *                        on the file rather than in someone's phone gallery.
 *
 *   party_ledger.file_role
 *                        Which side of a file a ledger entry represents. Until
 *                        now entry_type alone was enough — a file only ever
 *                        debited its customer and credited its vendor. Paper
 *                        Returned adds a second credit, to the customer, so the
 *                        two credits need telling apart when a file is edited.
 *
 * The enum is widened to hold old and new values at once, the rows are mapped
 * across, then it is narrowed. Going straight to the new list would leave every
 * existing row holding a value the column no longer allows, and MySQL writes an
 * empty string rather than refusing.
 */
return new class extends Migration
{
    private const OLD = ['pending', 'in_progress', 'completed', 'delivered', 'cancelled'];

    private const NEW = ['in_office', 'paper_pendency', 'file_dispatch', 'part_pesi_required',
        'under_verification', 'approval_done', 'paper_returned', 'cancelled'];

    /**
     * Anything still being worked on becomes In Office — the file is with us and
     * its real state can be set on the status board. completed and delivered map
     * to Approval Done, which is what they meant; those rows predate the
     * screenshot rule and so carry no evidence, and the rule only applies to
     * changes made from here on.
     */
    private const MAP = [
        'pending' => 'in_office',
        'in_progress' => 'in_office',
        'completed' => 'approval_done',
        'delivered' => 'approval_done',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('work_file')) {
            return;
        }

        if (! Schema::hasColumn('work_file', 'approval_screenshot')) {
            Schema::table('work_file', function (Blueprint $table) {
                // A path under public/uploads, not storage/. symlink() is disabled
                // on the production host, so php artisan storage:link cannot run
                // and anything under storage/app/public would be unreachable.
                $table->string('approval_screenshot')->nullable()->after('status');

                // The day the papers went back, so the credit that reverses the
                // charge is dated when it happened rather than when the file
                // arrived. Set the first time the status becomes Paper Returned.
                $table->date('returned_on')->nullable()->after('approval_screenshot');
            });
        }

        if (Schema::hasTable('party_ledger') && ! Schema::hasColumn('party_ledger', 'file_role')) {
            Schema::table('party_ledger', function (Blueprint $table) {
                $table->enum('file_role', ['customer', 'vendor', 'customer_return'])
                    ->nullable()
                    ->after('work_file_id');
            });

            // Existing file-generated entries predate the column. Their role is
            // unambiguous: back then a debit was only ever the customer's and a
            // credit only ever the vendor's. Entries typed straight into the
            // ledger have no file and keep a null role.
            DB::table('party_ledger')->whereNotNull('work_file_id')->where('entry_type', 'debit')
                ->update(['file_role' => 'customer']);
            DB::table('party_ledger')->whereNotNull('work_file_id')->where('entry_type', 'credit')
                ->update(['file_role' => 'vendor']);
        }

        if ($this->statusEnumHolds('in_office')) {
            return;
        }

        $this->setStatusEnum(array_values(array_unique(array_merge(self::OLD, self::NEW))), 'pending');

        foreach (self::MAP as $from => $to) {
            DB::table('work_file')->where('status', $from)->update(['status' => $to]);
        }

        $this->setStatusEnum(self::NEW, 'in_office');
    }

    public function down(): void
    {
        if (! Schema::hasTable('work_file') || ! $this->statusEnumHolds('in_office')) {
            return;
        }

        $this->setStatusEnum(array_values(array_unique(array_merge(self::OLD, self::NEW))), 'in_office');

        // Every new status collapses back to pending except cancelled, which
        // exists in both lists and means the same thing in each.
        DB::table('work_file')->whereIn('status', self::NEW)->where('status', '!=', 'cancelled')
            ->update(['status' => 'pending']);

        $this->setStatusEnum(self::OLD, 'pending');

        if (Schema::hasColumn('party_ledger', 'file_role')) {
            Schema::table('party_ledger', function (Blueprint $table) {
                $table->dropColumn('file_role');
            });
        }

        if (Schema::hasColumn('work_file', 'approval_screenshot')) {
            Schema::table('work_file', function (Blueprint $table) {
                $table->dropColumn(['approval_screenshot', 'returned_on']);
            });
        }
    }

    /**
     * @param  array<int, string>  $values
     */
    private function setStatusEnum(array $values, string $default): void
    {
        $list = implode(',', array_map(fn ($value) => "'".$value."'", $values));

        DB::statement('ALTER TABLE work_file MODIFY status ENUM('.$list.") NOT NULL DEFAULT '".$default."'");
    }

    /**
     * Read the enum's members from the column definition — no schema builder call
     * reports them, on 11.9 or on 13.
     */
    private function statusEnumHolds(string $value): bool
    {
        $column = DB::selectOne(
            'select COLUMN_TYPE as column_type from information_schema.COLUMNS
             where TABLE_SCHEMA = database() and TABLE_NAME = ? and COLUMN_NAME = ?',
            ['work_file', 'status']
        );

        return $column && str_contains($column->column_type, "'".$value."'");
    }
};
