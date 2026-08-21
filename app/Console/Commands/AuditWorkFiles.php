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
        $should = WorkFileModel::statusFromItems($file->items);

        if ($file->status !== $should) {
            $note($id, "is {$file->status} but its works say $should");
        }

        if (! $file->items->contains('work_type_id', $file->work_type_id)) {
            $note($id, 'is filed under a work type that is not on it');
        }

        // ---- The statements say what the file says ------------------------
        $entries = PartyLedgerModel::where('work_file_id', $file->id)->get()->keyBy('file_role');
        $customer = $entries->get('customer');

        if ($file->isCancelled()) {
            if ($customer) {
                $note($id, 'is cancelled but still debits the customer');
            }
        } elseif (! $customer) {
            $note($id, 'has no entry on the customer statement');
        } else {
            if ((int) $customer->party_id !== (int) $file->customer_id) {
                $note($id, "is billed to party {$customer->party_id}, but the file says {$file->customer_id}");
            }

            if (abs((float) $customer->amount - (float) $file->customer_amount) > 0.005) {
                $note($id, "debits the customer {$customer->amount} but charges {$file->customer_amount}");
            }

            if ($customer->particular !== $file->ledgerParticular()) {
                $note($id, 'reads "'.$customer->particular.'" on the statement, and "'.$file->ledgerParticular().'" on the file'
                    .' — files:relabel-ledger puts this right');
            }
        }

        $vendor = $entries->get('vendor');
        $owes = $file->vendor_id && $file->vendor_amount !== null && ! $file->isCancelled();

        if ($owes && ! $vendor) {
            $note($id, 'owes a vendor but has no entry on their statement');
        } elseif ($owes && abs((float) $vendor->amount - (float) $file->vendor_amount) > 0.005) {
            $note($id, "credits the vendor {$vendor->amount} but costs {$file->vendor_amount}");
        } elseif (! $owes && $vendor) {
            $note($id, 'has an entry on a vendor statement it should not');
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
