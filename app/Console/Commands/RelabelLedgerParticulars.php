<?php

namespace App\Console\Commands;

use App\Models\PartyLedgerModel;
use App\Models\WorkFileModel;
use Illuminate\Console\Command;

/**
 * Rewrites the description on ledger entries a work file owns.
 *
 * Entries carry the name the file had when they were last written, so files
 * taken in before a file could hold several works still read as though they
 * were for one — an entry covering a transfer, a hypothecation termination and
 * a hypothecation addition saying only "HPA".
 *
 * Only the description is touched. Amounts, dates, parties and roles are left
 * exactly as they stand: this corrects what an entry is called, never what it
 * says was charged. Nothing is written without --write, so the change can be
 * read first.
 */
class RelabelLedgerParticulars extends Command
{
    protected $signature = 'files:relabel-ledger {--write : Save the changes rather than only listing them}';

    protected $description = 'Name every work on the ledger entries a work file owns';

    public function handle(): int
    {
        $changes = [];

        WorkFileModel::with('items.workType')->chunkById(100, function ($files) use (&$changes) {
            foreach ($files as $file) {
                $wanted = $file->ledgerParticular();

                /*
                 * The two return entries carry a suffix saying which way the
                 * papers went, so they are matched by what they start with and
                 * keep their own ending.
                 */
                $suffixes = [
                    'customer' => '',
                    'vendor' => '',
                    'customer_return' => ' - papers returned',
                    'vendor_return' => ' - returned by vendor',
                ];

                foreach (PartyLedgerModel::where('work_file_id', $file->id)->get() as $entry) {
                    $should = $wanted.($suffixes[$entry->file_role] ?? '');

                    if ($entry->particular === $should) {
                        continue;
                    }

                    $changes[] = [$entry, $file->file_no, $entry->particular, $should];
                }
            }
        });

        if (! $changes) {
            $this->info('Every entry already names its work. Nothing to do.');

            return self::SUCCESS;
        }

        foreach ($changes as [, $fileNo, $was, $becomes]) {
            $this->line(sprintf('%-10s %s', $fileNo, $was));
            $this->line(sprintf('%-10s <info>%s</info>', '', $becomes));
        }

        $this->newLine();

        if (! $this->option('write')) {
            $this->warn(count($changes).' entries would be relabelled. Run again with --write to save.');

            return self::SUCCESS;
        }

        foreach ($changes as [$entry, , , $becomes]) {
            $entry->particular = $becomes;
            $entry->save();
        }

        $this->info(count($changes).' entries relabelled.');

        return self::SUCCESS;
    }
}
