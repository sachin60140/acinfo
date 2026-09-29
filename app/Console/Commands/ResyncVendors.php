<?php

namespace App\Console\Commands;

use App\Models\PartyLedgerModel;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Puts every file's vendor lines, and the vendor it names, in step with its
 * works — once, after a release that changed what a vendor is owed.
 *
 * A file's ledger lines are rewritten when the file is saved, and only then.
 * So when the rule for what a vendor is owed changes, a file that is never
 * saved again — an approved one, a returned one — keeps the lines the old rule
 * wrote for good. Asked for by the owner on 2026-09-28, with the rule that a
 * vendor is owed for their own works only: a vendor whose one given work was
 * cancelled was still credited the rate of the work the office kept.
 *
 * Only the vendor side. The folder's vendor, date and hand-back day where its
 * works now say otherwise, and each vendor's credit and reversal, from
 * WorkFileModel::vendorLines() — the same answer syncLedger() writes and
 * files:audit checks. The customer's lines and every status are untouched.
 *
 * Nothing is written without --write, so the list can be read first. A file
 * where a vendor would be owed less while a payment to them is adjusted
 * against it is skipped and named: the office moves the payment first, or the
 * money would silently go on account. Party ids and amounts only — no names.
 */
class ResyncVendors extends Command
{
    protected $signature = 'files:resync-vendors {--write : Save the changes rather than only listing them}';

    protected $description = "Rewrite each file's vendor lines, and the vendor it names, from its works";

    public function handle(): int
    {
        $changes = [];
        $skipped = [];
        $stranded = [];

        WorkFileModel::with('items')->chunkById(200, function ($files) use (&$changes, &$skipped, &$stranded) {
            foreach ($files as $file) {
                $plan = $this->planFor($file);

                if ($plan['office_dispatched']) {
                    $stranded[] = [$file->file_no, $plan['office_dispatched']];
                }

                if (! $plan['says']) {
                    continue;
                }

                if ($plan['held']) {
                    $skipped[] = [$file, $plan];
                } else {
                    $changes[] = [$file, $plan];
                }
            }
        });

        foreach ($changes as [$file, $plan]) {
            $this->line(sprintf('<fg=yellow>%-12s</> %s', $file->file_no, implode('; ', $plan['says'])));
        }

        if ($skipped) {
            $this->newLine();
            $this->warn('Skipped — a payment to the vendor is adjusted against the file. Move the payment first:');

            foreach ($skipped as [$file, $plan]) {
                $this->line(sprintf('  %-12s %s (%s)', $file->file_no, implode('; ', $plan['says']), implode('; ', $plan['held'])));
            }
        }

        if ($stranded) {
            $this->newLine();
            $this->warn('Work out with nobody, standing at File Dispatch — move it on the board:');

            foreach ($stranded as [$fileNo, $count]) {
                $this->line(sprintf('  %-12s %d %s', $fileNo, $count, $count === 1 ? 'work' : 'works'));
            }
        }

        $this->newLine();

        if (! $changes) {
            $this->info(($skipped ? 'Nothing else to change.' : 'Every file\'s vendor lines already follow its works. Nothing to do.'));

            return self::SUCCESS;
        }

        if (! $this->option('write')) {
            $this->warn(count($changes).' files would change. Run again with --write to save.');

            return self::SUCCESS;
        }

        foreach ($changes as [$file, $plan]) {
            DB::transaction(function () use ($file, $plan) {
                if ($plan['folder']) {
                    $file->vendor_id = $plan['folder']['vendor_id'];
                    $file->vendor_date = $plan['folder']['vendor_date'];
                    $file->vendor_returned_on = $plan['folder']['vendor_returned_on'];
                }

                if ($plan['folder'] || $plan['reversal']) {
                    // Refigured from the works whenever the folder is written,
                    // so a changed hand-back day cannot leave a stale figure.
                    if (WorkFileItemModel::partReversals() && ! WorkFileModel::isOlderFolder($file->items)) {
                        $file->vendor_returned_amount = $file->reversalFromWorks($file->items);
                    }

                    $file->save();
                }

                $file->syncVendors();
            });
        }

        $this->info(count($changes).' files put right. Run files:audit to check.');

        return self::SUCCESS;
    }

    /**
     * What would change on one file, in words, and what holds it back.
     *
     * @return array{says: array<int, string>, folder: ?array, held: array<int, string>, office_dispatched: int}
     */
    private function planFor(WorkFileModel $file): array
    {
        $says = [];
        $held = [];

        // The vendor the folder names, where its works now say another.
        $works = WorkFileModel::vendorFromWorks($file->items);
        $folder = $works !== null && (int) $works['vendor_id'] !== (int) $file->vendor_id ? $works : null;

        if ($folder) {
            $says[] = 'names vendor #'.($file->vendor_id ?: 'none').' → #'.($folder['vendor_id'] ?: 'none');
        }

        // What it says came back of its vendors' rates, where its works now say.
        $reversal = self::reversalDrift($file);

        if ($reversal) {
            $says[] = 'rates back '.$reversal[0].' → '.$reversal[1];
        }

        // Each vendor's lines, as they stand and as they should.
        $lines = $file->vendorLines();
        $entries = PartyLedgerModel::where('work_file_id', $file->id)
            ->whereIn('file_role', ['vendor', 'vendor_return'])->get();

        $net = [];

        foreach (['vendor' => 1, 'vendor_return' => -1] as $role => $sign) {
            $have = $entries->where('file_role', $role)->keyBy(fn ($entry) => (int) $entry->party_id);
            $vendors = array_unique(array_merge($have->keys()->all(), array_keys($lines[$role])));

            foreach ($vendors as $vendor) {
                $was = (float) ($have->get($vendor)?->amount ?? 0);
                $becomes = (float) ($lines[$role][$vendor]['amount'] ?? 0);

                $net[$vendor] = ($net[$vendor] ?? 0) + $sign * ($becomes - $was);

                if (abs($was - $becomes) > 0.005) {
                    $says[] = sprintf('vendor #%d %s %s → %s', $vendor, $role === 'vendor' ? 'owed' : 'takes back',
                        $was > 0 ? number_format($was, 2) : 'none', $becomes > 0 ? number_format($becomes, 2) : 'none');
                }
            }
        }

        /*
         * A vendor owed less on this file, with a payment to them adjusted
         * against it: moving the line would send part of that payment on
         * account with nothing to say why.
         */
        if (PartyLedgerModel::adjustable()) {
            foreach ($net as $vendor => $change) {
                if ($change >= -0.005) {
                    continue;
                }

                $adjusted = DB::table('party_ledger_allocation')
                    ->where('party_id', $vendor)
                    ->where('work_file_id', $file->id)
                    ->whereNull('released_at')
                    ->get(['entry_id', 'amount']);

                foreach ($adjusted as $one) {
                    $held[] = sprintf('payment #%d adjusted %s to vendor #%d', $one->entry_id, number_format((float) $one->amount, 2), $vendor);
                }
            }
        }

        // Work out with nobody — never given, or handed back — left at File
        // Dispatch by the old paper rule.
        $officeDispatched = WorkFileModel::isOlderFolder($file->items) ? 0 : $file->items
            ->filter(fn ($item) => $item->status === WorkFileModel::DISPATCHED
                && (! $item->vendor_id || $item->vendor_returned_on))
            ->count();

        return ['says' => $says, 'folder' => $folder, 'reversal' => $reversal !== null, 'held' => $held, 'office_dispatched' => $officeDispatched];
    }

    /**
     * The folder's own part reversal where its works say another — what a
     * folder handed back before the works carried their part, or before the
     * roll-up learned to add them, still says. Null where they agree, or where
     * the works cannot carry a part yet, or on an older folder.
     *
     * @return array{0: string, 1: string}|null  [what it says, what the works say]
     */
    public static function reversalDrift(WorkFileModel $file): ?array
    {
        if (! WorkFileItemModel::partReversals() || WorkFileModel::isOlderFolder($file->items)) {
            return null;
        }

        $has = $file->vendor_returned_amount === null ? null : round((float) $file->vendor_returned_amount, 2);
        $should = $file->reversalFromWorks($file->items);

        if ($has === $should || ($has !== null && $should !== null && abs($has - $should) < 0.005)) {
            return null;
        }

        $say = fn (?float $amount) => $amount === null
            ? ($file->vendor_returned_on ? 'all' : 'none')
            : number_format($amount, 2);

        return [$say($has), $say($should)];
    }
}
