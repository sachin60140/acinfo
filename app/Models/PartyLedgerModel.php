<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
     * What is still owed, file by file.
     *
     * Money arrives against the account, not against a file: a customer pays
     * 40,000 on a Tuesday for whatever is outstanding, and nothing on that
     * receipt says which files it covers. So the oldest charge is treated as
     * settled first, which is how the office reads its own statement and the
     * only rule that does not need a conversation to apply.
     *
     * Two things are exact rather than in that queue. A refund sits against the
     * file it belongs to and reduces that file and no other. And a charge
     * somebody typed straight into the ledger, belonging to no file, still
     * takes its turn in the queue — leaving it out would make every file look
     * better paid than it is.
     *
     * @param  array<int, int>  $partyIds
     * @return array<int, array<int, float>>  party id => [file id => still owed]
     */
    public static function outstandingByFile(array $partyIds): array
    {
        if (! $partyIds) {
            return [];
        }

        $entries = DB::table('party_ledger')
            ->whereIn('party_id', $partyIds)
            ->orderBy('txn_date')
            ->orderBy('id')
            ->get(['party_id', 'work_file_id', 'file_role', 'entry_type', 'amount']);

        $owed = [];

        foreach ($entries->groupBy('party_id') as $partyId => $rows) {
            $charges = [];
            $paid = 0.0;

            foreach ($rows as $row) {
                $amount = (float) $row->amount;

                if ($row->entry_type === 'debit') {
                    // Keyed by position, not by file: one file can carry more
                    // than one charge, and a charge belonging to no file still
                    // has to hold its place in the queue.
                    $charges[] = ['file_id' => $row->work_file_id ? (int) $row->work_file_id : null, 'left' => $amount];

                    continue;
                }

                if ($row->work_file_id) {
                    // A refund knows its file. Against that file only, and only
                    // as far as what that file was charged.
                    foreach ($charges as $i => $charge) {
                        if ($charge['file_id'] === (int) $row->work_file_id && $charge['left'] > 0) {
                            $taken = min($charge['left'], $amount);
                            $charges[$i]['left'] -= $taken;
                            $amount -= $taken;

                            if ($amount <= 0) {
                                break;
                            }
                        }
                    }

                    // Anything left of it is money back on account like any other.
                    $paid += max(0, $amount);

                    continue;
                }

                $paid += $amount;
            }

            // Oldest first, which is the order they were read in.
            foreach ($charges as $i => $charge) {
                if ($paid <= 0) {
                    break;
                }

                $taken = min($charge['left'], $paid);
                $charges[$i]['left'] -= $taken;
                $paid -= $taken;
            }

            foreach ($charges as $charge) {
                if ($charge['file_id'] === null || $charge['left'] <= 0.005) {
                    continue;
                }

                $owed[(int) $partyId][$charge['file_id']] =
                    round(($owed[(int) $partyId][$charge['file_id']] ?? 0) + $charge['left'], 2);
            }
        }

        return $owed;
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
