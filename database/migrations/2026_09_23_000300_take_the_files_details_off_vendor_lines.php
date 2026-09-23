<?php

use App\Models\WorkFileModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Takes the file's typed details off every vendor's ledger line.
 *
 * A vendor's statement is printed and sent to the vendor, and a vendor is
 * never told a customer's name or money. The line a file writes for its
 * vendor used to carry the file's details — typed at the counter, where the
 * form asks for the party's name — word for word. New lines no longer do
 * (WorkFileModel::vendorParticular); this puts the old ones right, each to
 * what syncVendors() would write for it now, so a file nobody saves again
 * does not keep them for ever. Found in review: left to a command run by
 * hand, they would have stayed.
 *
 * Only a vendor's line's wording changes. Amounts, dates, parties and every
 * customer line are left exactly as they are. There is nothing to undo: the
 * details are still on the file, and on the customer's line.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('party_ledger', 'file_role')) {
            return;
        }

        $suffixes = ['vendor' => '', 'vendor_return' => ' - returned by vendor'];

        $files = DB::table('party_ledger')
            ->whereIn('file_role', array_keys($suffixes))
            ->whereNotNull('work_file_id')
            ->distinct()
            ->pluck('work_file_id');

        foreach ($files->chunk(200) as $chunk) {
            foreach (WorkFileModel::whereIn('id', $chunk)->get() as $file) {
                $lines = DB::table('party_ledger')
                    ->where('work_file_id', $file->id)
                    ->whereIn('file_role', array_keys($suffixes))
                    ->get(['id', 'party_id', 'file_role', 'particular']);

                foreach ($lines as $line) {
                    $should = $file->vendorParticularFor((int) $line->party_id).$suffixes[$line->file_role];

                    if ($line->particular !== $should) {
                        DB::table('party_ledger')->where('id', $line->id)->update(['particular' => $should]);
                    }
                }
            }
        }
    }

    public function down(): void
    {
        // Nothing to put back: the details are still on the file.
    }
};
