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
