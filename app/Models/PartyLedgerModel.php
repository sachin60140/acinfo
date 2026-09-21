<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PartyLedgerModel extends Model
{
    public $table = 'party_ledger';

    use HasFactory;

    /**
     * The one place the debit/credit sign convention is written down.
     *
     *   balance = SUM(debit) - SUM(credit)
     *
     * For a customer a debit is a sale (they owe you more) and a credit is money
     * received, so a positive balance is receivable — Dr. For a vendor a credit
     * is a purchase (you owe them more) and a debit is money paid, so a negative
     * balance is payable — Cr. One formula serves both because the sides mean
     * the same thing in both directions; only which sign is "normal" differs.
     */
    public const BALANCE_SQL = "COALESCE(SUM(CASE WHEN party_ledger.entry_type = 'debit' THEN party_ledger.amount ELSE -party_ledger.amount END), 0)";

    /**
     * Payment modes offered on the entry form. Kept here rather than in a table
     * so this module stays independent of the existing payment_type data.
     */
    public const PAYMENT_MODES = ['Cash', 'Bank Transfer', 'UPI', 'Cheque', 'NEFT / RTGS', 'Credit / Invoice', 'Adjustment'];

    /**
     * The modes that mean money actually changed hands.
     *
     * A customer credit booked as "Credit / Invoice" or "Adjustment" is the
     * office correcting its books, not the customer paying, and thanking them
     * for a payment they never made is worse than saying nothing.
     */
    public const MONEY_MODES = ['Cash', 'Bank Transfer', 'UPI', 'Cheque', 'NEFT / RTGS'];

    /**
     * A balance the way a ledger prints it: magnitude plus the side it falls on,
     * never a minus sign. "1,200.00 Cr" reads correctly to anyone who keeps
     * books; "-1,200.00" has to be interpreted against a convention first.
     */
    public static function formatBalance($balance): string
    {
        $balance = (float) $balance;
        $amount = number_format(abs($balance), 2, '.', ',');

        // Rounds to nothing on either side — a settled account has no side.
        if (abs($balance) < 0.005) {
            return $amount;
        }

        return $amount.' '.($balance < 0 ? 'Cr' : 'Dr');
    }

    /**
     * Signed value of one row, positive for a debit.
     */
    public function signedAmount(): float
    {
        return $this->entry_type === 'debit' ? (float) $this->amount : -(float) $this->amount;
    }

    /**
     * Ledger rows for a party, optionally limited to a date range.
     *
     * Ordered by transaction date, not entry order: entries are routinely
     * back-dated, so the running balance the statement accumulates has to follow
     * the real sequence of events. Id breaks ties within the same day.
     */
    public static function getRecord($id, $from = null, $to = null)
    {
        $query = PartyLedgerModel::where('party_id', $id);

        if ($from) {
            $query->whereDate('txn_date', '>=', $from);
        }

        if ($to) {
            $query->whereDate('txn_date', '<=', $to);
        }

        return $query
            ->orderBy('txn_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();
    }

    /**
     * Balance brought forward from everything before the statement period.
     *
     * Without this a filtered statement would start at zero and every figure in
     * the Balance column would be a partial sum rather than what the party
     * actually owed on that date.
     */
    public static function openingBalance($id, $from = null): float
    {
        if (! $from) {
            return 0.0;
        }

        $row = DB::table('party_ledger')
            ->where('party_id', $id)
            ->whereDate('txn_date', '<', $from)
            ->selectRaw(self::BALANCE_SQL.' as balance')
            ->first();

        return (float) ($row->balance ?? 0);
    }

    /**
     * Current balance for one party across its whole history.
     */
    public static function currentBalance($id): float
    {
        $row = DB::table('party_ledger')
            ->where('party_id', $id)
            ->selectRaw(self::BALANCE_SQL.' as balance')
            ->first();

        return (float) ($row->balance ?? 0);
    }

    /**
     * Current balance for a set of parties, in one query.
     *
     * A report listing thirty customers would otherwise ask the database for
     * thirty balances one at a time.
     *
     * @param  array<int, int>  $partyIds
     * @return array<int, float>
     */
    public static function balancesFor(array $partyIds): array
    {
        if (! $partyIds) {
            return [];
        }

        return DB::table('party_ledger')
            ->whereIn('party_id', $partyIds)
            ->groupBy('party_id')
            ->selectRaw('party_id, '.self::BALANCE_SQL.' as balance')
            ->pluck('balance', 'party_id')
            ->map(fn ($balance) => (float) $balance)
            ->all();
    }

    /**
     * Whether payments can be adjusted against files yet.
     *
     * The allocation table arrives with a migration, and a deploy here is a
     * git pull that does not run one. Until it has run, everything behaves as
     * it did before: no adjustments to read, and none offered.
     */
    public static function adjustable(): bool
    {
        static $known = null;

        return $known ??= Schema::hasTable('party_ledger_allocation');
    }

    /**
     * Whether an entry can be reversed yet: its columns arrive with a
     * migration, as the allocation table does.
     */
    public static function reversible(): bool
    {
        static $known = null;

        return $known ??= self::adjustable() && Schema::hasColumn('party_ledger', 'reverses_id');
    }

    /** The kind a reversal row is, in entry_kind. */
    public const REVERSAL = 'reversal';

    /** The payment mode a reversal is written with. */
    public const REVERSAL_MODE = 'Reversal';

    /**
     * What is still owed, file by file.
     *
     * A payment the office adjusted against files settles those files first —
     * a dealer who pays 10,000 "for F-00050 and F-00057" meant those two. What
     * nobody said anything about is treated as settling the oldest charge,
     * which is how the office reads its own statement and was the only rule
     * there was before a payment could be adjusted.
     *
     * Two things are exact whatever anyone says. A refund sits against the
     * file it belongs to and reduces that file and no other. And a charge
     * somebody typed straight into the ledger, belonging to no file, still
     * takes its turn in the queue — leaving it out would make every file look
     * better paid than it is.
     *
     * The same for a vendor, the other way round: what the office owes them is
     * a credit and what it pays is a debit.
     *
     * @param  array<int, int>  $partyIds
     * @param  string  $chargeSide  'debit' for customers, 'credit' for vendors
     * @return array<int, array<int, float>>  party id => [file id => still owed]
     */
    public static function outstandingByFile(array $partyIds, string $chargeSide = 'debit'): array
    {
        $owed = [];

        foreach (self::settleAll($partyIds, $chargeSide) as $partyId => $settled) {
            foreach ($settled['files'] as $fileId => $file) {
                if ($file['due'] > 0.005) {
                    $owed[$partyId][$fileId] = $file['due'];
                }
            }
        }

        return $owed;
    }

    /**
     * One party's files as bills: charged, returned, adjusted, and what is
     * still open against each.
     *
     * "Open" is what explicit adjustments have not covered — the most a new
     * payment can be adjusted against that file. "Due" is what is left once
     * money nobody adjusted has settled the oldest charges too, and is what
     * Not Yet Collected shows. A customer with an advance on account can have
     * a file open for 5,000 and due nothing: paying 5,000 "for that file"
     * settles it and moves the advance on to the next.
     *
     * @return array{files: array<int, array{charged: float, returned: float, adjusted: float, open: float, due: float, seq: int}>, unadjusted: array<int, float>}
     */
    public static function bills(int $partyId, string $chargeSide = 'debit'): array
    {
        return self::settleAll([$partyId], $chargeSide)[$partyId]
            ?? ['files' => [], 'unadjusted' => [], 'loose' => []];
    }

    /**
     * @param  array<int, int>  $partyIds
     * @return array<int, array{files: array<int, array<string, float>>, unadjusted: array<int, float>}>
     */
    private static function settleAll(array $partyIds, string $chargeSide): array
    {
        if (! $partyIds) {
            return [];
        }

        $entries = DB::table('party_ledger')
            ->whereIn('party_id', $partyIds)
            ->orderBy('txn_date')
            ->orderBy('id')
            ->get(array_merge(
                ['id', 'party_id', 'work_file_id', 'entry_type', 'amount'],
                self::reversible() ? ['reverses_id'] : []
            ))
            ->groupBy('party_id');

        // In the order the payments were made, then the order they were adjusted.
        $allocations = self::adjustable()
            ? DB::table('party_ledger_allocation as a')
                ->join('party_ledger as e', 'e.id', '=', 'a.entry_id')
                ->whereIn('a.party_id', $partyIds)
                ->whereNull('a.released_at')
                ->orderBy('e.txn_date')
                ->orderBy('e.id')
                ->orderBy('a.id')
                ->get(['a.party_id', 'a.entry_id', 'a.work_file_id', 'a.amount'])
                ->groupBy('party_id')
            : collect();

        $out = [];

        foreach ($entries as $partyId => $rows) {
            $out[(int) $partyId] = self::settle($rows, $allocations[$partyId] ?? collect(), $chargeSide);
        }

        return $out;
    }

    /**
     * The rule, for one party's rows.
     *
     *  1. In date order: each charge joins the queue; a refund against a file
     *     reduces that file's charges read so far, anything over going back on
     *     account; every other payment is held, by its entry.
     *  2. Each payment's adjustments, in the order the payments were made:
     *     against that file's charges, as far as the payment and the charges
     *     go. What cannot be taken — a file re-priced below it, cancelled, or
     *     moved to someone else — stays with the payment, on account.
     *  3. Everything still held settles the oldest charges first.
     *
     * With nothing adjusted, 2 does nothing and this is exactly the rule there
     * was before.
     */
    private static function settle($rows, $allocations, string $chargeSide): array
    {
        /*
         * An entry taken back and the reversal that took it back are read as if
         * neither had happened: the two cancel exactly, so the balance is
         * unmoved either way, and a reversed receipt must not go on settling
         * files — nor a reversed charge go on being owed.
         */
        $paired = [];

        foreach ($rows as $row) {
            if (! empty($row->reverses_id)) {
                $paired[(int) $row->id] = true;
                $paired[(int) $row->reverses_id] = true;
            }
        }

        $charges = [];
        // Where each file's charges sit in the queue, so taking from one file
        // looks at that file's charges and not the party's whole history.
        // Found in review: scanning every charge for every adjustment grew with
        // the square of a big dealer's files, on every dashboard load.
        $byFile = [];
        $files = [];
        $held = [];
        $pool = 0.0;

        $file = function (int $id) use (&$files) {
            $files[$id] ??= ['charged' => 0.0, 'returned' => 0.0, 'adjusted' => 0.0, 'open' => 0.0, 'due' => 0.0, 'seq' => 0];
        };

        // Take up to $amount from one file's charges still left, oldest first.
        $take = function (int $fileId, float $amount) use (&$charges, &$byFile): float {
            $taken = 0.0;

            foreach ($byFile[$fileId] ?? [] as $i) {
                if ($amount - $taken <= 0.005) {
                    break;
                }

                if ($charges[$i]['left'] > 0.005) {
                    $bite = min($charges[$i]['left'], $amount - $taken);
                    $charges[$i]['left'] -= $bite;
                    $taken += $bite;
                }
            }

            return $taken;
        };

        foreach ($rows as $row) {
            if (isset($paired[(int) $row->id])) {
                continue;
            }

            $amount = (float) $row->amount;
            $fileId = $row->work_file_id ? (int) $row->work_file_id : null;

            if ($row->entry_type === $chargeSide) {
                // Keyed by position, not by file: one file can carry more than
                // one charge, and a charge belonging to no file still has to
                // hold its place in the queue.
                $charges[] = ['file_id' => $fileId, 'left' => $amount];

                if ($fileId !== null) {
                    $byFile[$fileId][] = array_key_last($charges);
                    $file($fileId);
                    $files[$fileId]['charged'] += $amount;

                    // Its place in the queue, which is the order unadjusted
                    // money reaches it in.
                    if (! $files[$fileId]['seq']) {
                        $files[$fileId]['seq'] = count($charges);
                    }
                }

                continue;
            }

            if ($fileId !== null) {
                // A refund knows its file. Against that file only, and only as
                // far as what that file was charged.
                $taken = $take($fileId, $amount);
                $file($fileId);
                $files[$fileId]['returned'] += $taken;
                $pool += max(0, $amount - $taken);

                continue;
            }

            $held[(int) $row->id] = $amount;
        }

        foreach ($allocations as $allocation) {
            $entry = (int) $allocation->entry_id;
            $fileId = (int) $allocation->work_file_id;

            if (($held[$entry] ?? 0) <= 0.005) {
                continue;
            }

            $taken = $take($fileId, min((float) $allocation->amount, $held[$entry]));
            $held[$entry] -= $taken;

            if ($taken > 0) {
                $file($fileId);
                $files[$fileId]['adjusted'] += $taken;
            }
        }

        // What adjustments have not covered: the most a new payment can take.
        foreach ($charges as $charge) {
            if ($charge['file_id'] !== null) {
                $files[$charge['file_id']]['open'] += max(0, $charge['left']);
            }
        }

        // Oldest first, which is the order they were read in.
        $pool += array_sum(array_map(fn ($left) => max(0, $left), $held));

        foreach ($charges as $i => $charge) {
            if ($pool <= 0.005) {
                break;
            }

            $bite = min($charge['left'], $pool);
            $charges[$i]['left'] -= $bite;
            $pool -= $bite;
        }

        // And what is still owed on charges belonging to no file — a bill typed
        // straight into the ledger — with their places in the queue.
        $loose = [];

        foreach ($charges as $i => $charge) {
            if ($charge['file_id'] !== null) {
                $files[$charge['file_id']]['due'] += max(0, $charge['left']);
            } elseif ($charge['left'] > 0.005) {
                $loose[] = ['seq' => $i + 1, 'due' => round($charge['left'], 2)];
            }
        }

        foreach ($files as $id => $sums) {
            $seq = $sums['seq'];
            $files[$id] = array_map(fn ($value) => round($value, 2), $sums);
            $files[$id]['seq'] = $seq;
        }

        return [
            'files' => $files,
            // Each payment, and how much of it no adjustment has taken.
            'unadjusted' => array_map(fn ($left) => round(max(0, $left), 2), $held),
            'loose' => $loose,
        ];
    }

    /**
     * What payments were adjusted against, as a receipt and a statement say
     * it: the vehicle and the works, and how much — never a vendor.
     *
     * @param  array<int, int>  $entryIds
     * @return array<int, array<int, array{label: string, amount: float}>>  entry id => lines
     */
    public static function againstFor(array $entryIds): array
    {
        if (! $entryIds || ! self::adjustable()) {
            return [];
        }

        $lines = DB::table('party_ledger_allocation as a')
            ->join('party as p', 'p.id', '=', 'a.party_id')
            ->whereIn('a.entry_id', $entryIds)
            ->whereNull('a.released_at')
            ->orderBy('a.id')
            ->get(['a.entry_id', 'a.party_id', 'a.work_file_id', 'a.amount', 'p.party_type']);

        if ($lines->isEmpty()) {
            return [];
        }

        $fileIds = $lines->pluck('work_file_id')->unique()->all();

        /*
         * Only a file that still charges the one who paid. Found in review: a
         * file given to another customer since kept its adjustment — rightly,
         * the money stays with the payer, on account — and the payer's
         * statement then named the other customer's vehicle and works, read
         * from the file as it is now. That share is on account, and is said
         * nowhere rather than said wrong; files:audit names it for the office.
         */
        $charging = DB::table('party_ledger')
            ->whereIn('work_file_id', $fileIds)
            ->whereIn('file_role', ['customer', 'vendor'])
            ->get(['work_file_id', 'party_id'])
            ->map(fn ($row) => $row->work_file_id.':'.$row->party_id)
            ->flip();

        $files = WorkFileModel::with('items.workType')
            ->whereIn('id', $fileIds)
            ->get()
            ->keyBy('id');

        $out = [];

        foreach ($lines as $line) {
            $file = $files[$line->work_file_id] ?? null;

            if (! $file || ! isset($charging[$line->work_file_id.':'.$line->party_id])) {
                continue;
            }

            /*
             * The works as the payer knows them. Never a cancelled one — the
             * charge above it on the statement leaves those out — and for a
             * vendor only the works they were given: a folder split between
             * two vendors is none of the other's business.
             */
            $works = $file->worksFor($line->party_type === 'vendor' ? (int) $line->party_id : null);

            $out[(int) $line->entry_id][] = [
                'label' => trim(($file->registration_no ?: $file->file_no ?: 'File').($works ? ' ('.$works.')' : '')),
                'amount' => round((float) $line->amount, 2),
            ];
        }

        return $out;
    }

    /**
     * The same lines as one statement cell: "BR01AB1234 (TR) 6,000.00 · …".
     *
     * @param  array<int, array{label: string, amount: float}>  $lines
     */
    public static function againstText(array $lines): ?string
    {
        if (! $lines) {
            return null;
        }

        return implode(' · ', array_map(
            fn ($line) => $line['label'].' '.number_format($line['amount'], 2, '.', ','),
            $lines
        ));
    }

    /**
     * Everything a statement page needs: the rows, the balance brought forward,
     * period totals and the closing balance.
     */
    /**
     * The office's note on each file a set of ledger entries came from.
     *
     * A ledger row has no remark of its own — the column does not exist. What a
     * reader means by "the remark on this line" is the note typed on the file
     * that generated it, so it is fetched by work_file_id and left null for the
     * entries somebody typed straight into the ledger.
     *
     * Deliberately work_file.remarks and not the status log's, which the
     * application writes itself as "Given to <vendor>". That distinction is
     * kept in WorkFileModel::customerRemark and matters more on a statement
     * than anywhere else, because a statement is the document that gets
     * forwarded.
     *
     * @param  iterable<object>  $entries
     * @return array<int, string>
     */
    public static function fileRemarks(iterable $entries): array
    {
        $ids = [];

        foreach ($entries as $entry) {
            if ($entry->work_file_id) {
                $ids[$entry->work_file_id] = true;
            }
        }

        if (! $ids) {
            return [];
        }

        return DB::table('work_file')
            ->whereIn('id', array_keys($ids))
            ->whereNotNull('remarks')
            ->where('remarks', '!=', '')
            ->pluck('remarks', 'id')
            ->all();
    }

    public static function statement($id, $from = null, $to = null): array
    {
        $rows = self::getRecord($id, $from, $to);
        $opening = self::openingBalance($id, $from);

        $debits = 0.0;
        $credits = 0.0;

        foreach ($rows as $row) {
            if ($row->entry_type === 'debit') {
                $debits += (float) $row->amount;
            } else {
                $credits += (float) $row->amount;
            }
        }

        return [
            'getRecords' => $rows,
            'opening' => $opening,
            'debits' => $debits,
            'credits' => $credits,
            'closing' => $opening + $debits - $credits,
            'from' => $from,
            'to' => $to,
        ];
    }
}
