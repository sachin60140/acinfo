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
     * Everything a statement page needs: the rows, the balance brought forward,
     * period totals and the closing balance.
     */
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
