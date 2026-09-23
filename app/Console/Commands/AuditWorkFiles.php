<?php

namespace App\Console\Commands;

use App\Models\PartyLedgerModel;
use App\Models\WorkFileModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reads every work file against its works and its ledger, and reports where
 * they disagree.
 *
 * A file, the works on it, and the two statements it writes are three records
 * of one thing, kept in step by the code that changes them. This checks that
 * they still say the same — which is the only way to find a disagreement one
 * of those paths caused and then stopped causing.
 *
 * Writes nothing. It is safe to run on a live ledger at any time, and worth
 * running after a release that touched how a file is priced or moved.
 */
class AuditWorkFiles extends Command
{
    protected $signature = 'files:audit';

    protected $description = 'Check every work file against its works and its ledger entries';

    public function handle(): int
    {
        $problems = [];

        $note = function (string $file, string $what) use (&$problems) {
            $problems[] = [$file, $what];
        };

        $files = 0;

        WorkFileModel::with('items.workType')->chunkById(200, function ($chunk) use ($note, &$files) {
            foreach ($chunk as $file) {
                $files++;
                $this->checkFile($file, $note);
            }
        });

        $this->checkOrphans($note);
        $this->checkAdjustments($note);

        $this->line("Read $files files.");

        if (! $problems) {
            $this->newLine();
            $this->info('Every file agrees with its works and its ledger.');

            return self::SUCCESS;
        }

        $this->newLine();

        foreach ($problems as [$file, $what]) {
            $this->line(sprintf('  <fg=yellow>%-12s</> %s', $file, $what));
        }

        $this->newLine();
        $this->warn(count($problems).' disagreements. None of this is changed by running the audit.');

        return self::FAILURE;
    }

    /**
     * Payments adjusted against files, against the payments and the files.
     *
     * Nothing on the screens can make most of these, and the engine caps what
     * it reads — a line can never settle more than its payment or its file. So
     * they are here for what a hand in the database, or a bug, might leave:
     * a payment adjusted for more than it was, lines on something that is not
     * a payment, and a line whose party is not the payment's. And one that
     * ordinary use does make, worth knowing about: a file adjusted against
     * that no longer charges the party who paid — cancelled, or given to
     * somebody else — whose share of that payment is now on account.
     */
    private function checkAdjustments(callable $note): void
    {
        if (! \App\Models\PartyLedgerModel::adjustable()) {
            return;
        }

        $lines = \Illuminate\Support\Facades\DB::table('party_ledger_allocation as a')
            ->join('party_ledger as e', 'e.id', '=', 'a.entry_id')
            ->join('party as p', 'p.id', '=', 'e.party_id')
            ->join('work_file as f', 'f.id', '=', 'a.work_file_id')
            ->whereNull('a.released_at')
            ->get([
                'a.entry_id', 'a.party_id', 'a.work_file_id', 'a.amount',
                'e.party_id as entry_party', 'e.entry_type', 'e.work_file_id as entry_file', 'e.amount as entry_amount', 'e.entry_kind',
                'p.party_type', 'f.file_no',
            ]);

        foreach ($lines->groupBy('entry_id') as $entryId => $mine) {
            $first = $mine->first();
            $label = 'payment #'.$entryId;
            $paymentSide = $first->party_type === 'customer' ? 'credit' : 'debit';

            if ($first->entry_file || $first->entry_type !== $paymentSide) {
                $note($label, 'is adjusted against files but is not a payment');
            }

            $sum = round($mine->sum(fn ($line) => (float) $line->amount), 2);

            if ($sum > (float) $first->entry_amount + 0.005) {
                $note($label, "is adjusted against $sum of files but was for {$first->entry_amount}");
            }

            foreach ($mine as $line) {
                if ((int) $line->party_id !== (int) $line->entry_party) {
                    $note($label, "has a line against {$line->file_no} recorded for a different party");
                }
            }
        }

        /*
         * A write-off whose bill is no longer charged that much: the papers
         * went back, the work was struck off, or the price came down under it.
         * The forgiveness settles nothing now and sits on the account until it
         * is taken back, so it is named here rather than left to be noticed in
         * a balance.
         */
        $forgiven = $lines->filter(fn ($line) => $line->entry_kind === \App\Models\PartyLedgerModel::WRITEOFF);

        if ($forgiven->isNotEmpty()) {
            $charges = \Illuminate\Support\Facades\DB::table('party_ledger')
                ->whereIn('work_file_id', $forgiven->pluck('work_file_id')->unique())
                ->whereNotNull('file_role')
                ->get(['work_file_id', 'party_id', 'file_role', 'amount'])
                ->groupBy(fn ($row) => $row->work_file_id.':'.$row->party_id)
                ->map(fn ($rows) => round($rows->sum(fn ($row) => str_ends_with($row->file_role, '_return')
                    ? -(float) $row->amount
                    : (float) $row->amount), 2));

            foreach ($forgiven as $line) {
                $left = (float) ($charges[$line->work_file_id.':'.$line->party_id] ?? 0);

                if ((float) $line->amount > $left + 0.005) {
                    $note($line->file_no, "was given a discount of {$line->amount} by entry #{$line->entry_id}, "
                        ."but only $left is charged on it now — the discount settles nothing and is sitting on the account. Take it back on the statement.");
                }
            }
        }

        // Adjusted against a file that no longer charges the one who paid.
        $billing = \Illuminate\Support\Facades\DB::table('party_ledger')
            ->whereNotNull('work_file_id')
            ->whereIn('file_role', ['customer', 'vendor'])
            ->get(['work_file_id', 'party_id'])
            ->map(fn ($row) => $row->work_file_id.':'.$row->party_id)
            ->flip();

        foreach ($lines as $line) {
            if (! isset($billing[$line->work_file_id.':'.$line->party_id])) {
                $note($line->file_no, "is adjusted against payment #{$line->entry_id}, but no longer charges that party — that share of the payment is on account");
            }
        }
    }

    /**
     * One file, against the works on it and the entries it owns.
     */
    private function checkFile(WorkFileModel $file, callable $note): void
    {
        $id = $file->file_no;

        if ($file->items->isEmpty()) {
            $note($id, 'has no works on it at all, so nothing can say what it is for');

            return;
        }

        // ---- The folder is the sum of its works ---------------------------
        $live = $file->items->reject(fn ($item) => $item->status === WorkFileModel::CANCELLED);

        $charged = round($live->sum(fn ($item) => (float) $item->customer_amount), 2);

        if (abs($charged - (float) $file->customer_amount) > 0.005) {
            $note($id, "charges {$file->customer_amount} but its works come to $charged");
        }

        $priced = $live->filter(fn ($item) => $item->vendor_amount !== null);
        $cost = $priced->isEmpty() ? null : round($priced->sum(fn ($item) => (float) $item->vendor_amount), 2);

        if (($cost === null) !== ($file->vendor_amount === null)) {
            $note($id, 'costs '.var_export($file->vendor_amount, true).' but its works say '.var_export($cost, true));
        } elseif ($cost !== null && abs($cost - (float) $file->vendor_amount) > 0.005) {
            $note($id, "costs {$file->vendor_amount} but its works come to $cost");
        }

        // ---- And its status is what they say ------------------------------
        $should = WorkFileModel::statusFromItems($file->items, $file->status);

        if ($file->status !== $should) {
            $note($id, "is {$file->status} but its works say $should");
        }

        /*
         * Returned to the customer, then un-returned by a save.
         *
         * Every work that was not cancelled went back, but the folder is not
         * returned. The roll-up used to count the cancelled work, read such a
         * folder as approved on the next save, and clear the return's date
         * and refund. The refund agreed is recorded nowhere now, so this is
         * for a person to put right, not the roll-up.
         */
        $standing = $file->items->reject(fn ($item) => $item->status === WorkFileModel::CANCELLED);

        if ($file->items->count() > 1
            && $standing->isNotEmpty()
            && $standing->count() < $file->items->count()
            && $standing->every(fn ($item) => $item->status === WorkFileModel::RETURNED)
            && $file->status !== WorkFileModel::RETURNED) {
            $note($id, "had its papers returned to the customer, but a later save lost the return and its refund (it is {$file->status}) — put the return and the refund agreed back by hand");
        }

        if (! $file->items->contains('work_type_id', $file->work_type_id)) {
            $note($id, 'is filed under a work type that is not on it');
        }

        // ---- The statements say what the file says ------------------------

        /*
         * What syncSide() would write, asked the same way it asks.
         *
         * It writes nothing without both a party and an amount above zero: a
         * file can be taken in, and even given to a vendor, before either price
         * is agreed, and a statement must not carry a line worth nothing. So a
         * file awaiting a price is not a file missing its entry — this is what
         * the dashboard's Awaiting Price tile is counting, and reporting it
         * here as a disagreement buries the ones that are.
         */
        $expected = [
            'customer' => ! $file->isCancelled()
                && $file->customer_id
                && (float) $file->customer_amount > 0,

            'vendor' => ! $file->isCancelled()
                && $file->vendor_id
                && (float) $file->vendor_amount > 0,

            'customer_return' => $file->isReturned()
                && $file->customer_id
                && $file->refundToCustomer() > 0,

            'vendor_return' => $file->vendor_returned_on
                && ! $file->isCancelled()
                && $file->vendor_id
                && $file->reversedToVendor() > 0,
        ];

        $amounts = [
            'customer' => (float) $file->customer_amount,
            'vendor' => (float) $file->vendor_amount,
            'customer_return' => $file->refundToCustomer(),
            'vendor_return' => $file->reversedToVendor(),
        ];

        $whose = [
            'customer' => (int) $file->customer_id,
            'vendor' => (int) $file->vendor_id,
            'customer_return' => (int) $file->customer_id,
            'vendor_return' => (int) $file->vendor_id,
        ];

        $said = [
            'customer' => 'charges the customer',
            'vendor' => 'owes the vendor',
            'customer_return' => 'refunds the customer',
            'vendor_return' => 'takes back from the vendor',
        ];

        $entries = PartyLedgerModel::where('work_file_id', $file->id)->get()->keyBy('file_role');

        foreach ($expected as $role => $wanted) {
            $entry = $entries->get($role);

            if ($wanted && ! $entry) {
                $note($id, "$said[$role] ".number_format($amounts[$role], 2).' but has no entry for it');

                continue;
            }

            if (! $wanted && $entry) {
                $note($id, "has a \"$role\" entry of {$entry->amount} that nothing on the file calls for");

                continue;
            }

            if (! $entry) {
                continue;
            }

            if (abs((float) $entry->amount - $amounts[$role]) > 0.005) {
                $note($id, "$said[$role] ".number_format($amounts[$role], 2)." but the entry says {$entry->amount}");
            }

            if ((int) $entry->party_id !== $whose[$role]) {
                $note($id, "posts \"$role\" against party {$entry->party_id}, and the file says {$whose[$role]}");
            }
        }

        // The wording, which files:relabel-ledger is what puts right.
        $customer = $entries->get('customer');

        if ($customer && $customer->particular !== $file->ledgerParticular()) {
            $note($id, 'reads "'.$customer->particular.'" on the statement and "'.$file->ledgerParticular().'" on the file'
                .' — files:relabel-ledger puts this right');
        }

        // ---- An approval is dated and evidenced ---------------------------
        foreach ($file->items as $item) {
            $work = $item->workType?->name ?? 'a work';

            if ($item->status === WorkFileModel::APPROVED) {
                if (! $item->approved_on) {
                    $note($id, "$work is approved with no date");
                }

                if (! $item->approval_screenshot) {
                    $note($id, "$work is approved with no screenshot");
                }
            } elseif ($item->approved_on) {
                $note($id, "$work is not approved but carries an approval date");
            }
        }
    }

    /**
     * Rows pointing at something that is no longer there.
     */
    private function checkOrphans(callable $note): void
    {
        $entries = DB::table('party_ledger')
            ->whereNotNull('work_file_id')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('work_file')
                ->whereColumn('work_file.id', 'party_ledger.work_file_id'))
            ->count();

        if ($entries) {
            $note('—', "$entries ledger entries point at a file that no longer exists");
        }

        $works = DB::table('work_file_item')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('work_type')
                ->whereColumn('work_type.id', 'work_file_item.work_type_id'))
            ->count();

        if ($works) {
            $note('—', "$works works point at a work type that no longer exists");
        }
    }
}
