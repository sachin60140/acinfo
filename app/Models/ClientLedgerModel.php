<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ClientLedgerModel extends Model
{
    public $table = 'client_ledger';

    use HasFactory;

    /**
     * Ledger rows for a client, optionally limited to a date range.
     *
     * Ordered by transaction date so the running balance the views accumulate
     * reflects the real sequence of events — entries are frequently back-dated,
     * so entry order and date order are not the same thing.
     */
    public static function getRecord($id, $from = null, $to = null)
    {
        $query = ClientLedgerModel::select('client_ledger.*', 'client.name as client_name', DB::raw("COALESCE(payment_type.payment_mode, 'Unknown') as payment_type"))
            ->join('client', 'client.id', 'client_ledger.client_id')
            ->leftJoin('payment_type', 'payment_type.id', 'client_ledger.payment_by')
            ->where('client_ledger.client_id', $id);

        if ($from) {
            $query->whereDate('client_ledger.txn_date', '>=', $from);
        }

        if ($to) {
            $query->whereDate('client_ledger.txn_date', '<=', $to);
        }

        return $query
            ->orderBy('client_ledger.txn_date', 'asc')
            ->orderBy('client_ledger.id', 'asc')
            ->get();
    }

    /**
     * Balance brought forward from everything before the statement period.
     *
     * Without this, a filtered statement would start its running balance at
     * zero and every figure in the Balance column would be a partial sum
     * rather than what the client actually owed on that date.
     */
    public static function openingBalance($id, $from = null): float
    {
        if (! $from) {
            return 0.0;
        }

        return (float) ClientLedgerModel::where('client_id', $id)
            ->whereDate('txn_date', '<', $from)
            ->sum('amount');
    }

    /**
     * What the old book still holds for or against each client, where it holds
     * anything: client id => sum, as the book keeps it — positive is money held
     * for the client, negative money they owe.
     *
     * The old book was closed by the owner on 2026-09-23; this is what is left
     * to carry to Customers (see CloseClientLedgerController).
     *
     * @return array<int, float>
     */
    public static function openBalances(): array
    {
        return DB::table('client_ledger')
            ->groupBy('client_id')
            ->havingRaw('ABS(SUM(amount)) >= 0.005')
            ->selectRaw('client_id, SUM(amount) as balance')
            ->pluck('balance', 'client_id')
            ->map(fn ($balance) => round((float) $balance, 2))
            ->all();
    }

    /**
     * Since when a debt carried out of the old book has really been owed.
     *
     * The line that carried it to Customers is dated the day it was carried,
     * so a statement already sent does not change — which makes a debt from
     * 2025 look like one from today. What the old book says instead: its
     * charges oldest first, with what the client paid settling the oldest, and
     * so does whatever they have paid off the carried balance since. The day
     * of the first charge left is the answer. Only charges are read, so the
     * line that closed the book — money in, for a client who owed — is not
     * taken for one.
     *
     * @param  array<int, float>  $owed  client id => how much of what was carried is still unpaid
     * @return array<int, string>  client id => Y-m-d
     */
    public static function owedSince(array $owed): array
    {
        if (! $owed) {
            return [];
        }

        $lines = DB::table('client_ledger')
            ->whereIn('client_id', array_keys($owed))
            ->orderBy('txn_date')
            ->orderBy('id')
            ->get(['client_id', 'txn_date', 'amount'])
            ->groupBy('client_id');

        $since = [];

        foreach ($lines as $clientId => $rows) {
            // Negative in the old book is money the client owed.
            $charges = $rows->filter(fn ($row) => (float) $row->amount < 0);

            // Everything but the last $owed of their charges has been paid.
            $settled = -$charges->sum(fn ($row) => (float) $row->amount) - ($owed[$clientId] ?? 0);
            $running = 0.0;

            foreach ($charges as $row) {
                $running += -(float) $row->amount;

                if ($running > $settled + 0.005) {
                    $since[(int) $clientId] = substr((string) $row->txn_date, 0, 10);
                    break;
                }
            }
        }

        return $since;
    }

    /** Whether anything at all is left in the old book to carry over. */
    public static function hasOpenBalances(): bool
    {
        return DB::table('client_ledger')
            ->groupBy('client_id')
            ->havingRaw('ABS(SUM(amount)) >= 0.005')
            ->select('client_id')
            ->limit(1)
            ->exists();
    }

    /**
     * Everything a statement page needs: the rows, the balance brought forward,
     * period totals and the closing balance.
     */
    public static function statement($id, $from = null, $to = null): array
    {
        $rows = self::getRecord($id, $from, $to);
        $opening = self::openingBalance($id, $from);

        $receipts = 0.0;
        $payments = 0.0;

        foreach ($rows as $row) {
            if ($row->amount >= 0) {
                $receipts += $row->amount;
            } else {
                $payments += $row->amount;
            }
        }

        return [
            'getRecords' => $rows,
            'opening' => $opening,
            'receipts' => $receipts,
            'payments' => $payments,
            'closing' => $opening + $receipts + $payments,
            'from' => $from,
            'to' => $to,
        ];
    }
}
