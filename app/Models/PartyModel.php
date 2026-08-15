<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class PartyModel extends Model
{
    public $table = 'party';

    use HasFactory;

    /**
     * The two roles a party can have, and how each is labelled on screen.
     */
    public const TYPES = [
        'customer' => 'Customer',
        'vendor' => 'Vendor',
    ];

    public static function label(string $type): string
    {
        return self::TYPES[$type] ?? 'Party';
    }

    /**
     * Every party of one type with its current balance, in a single query.
     *
     * The balance is derived from the ledger on every read rather than cached on
     * the party row, so a back-dated or corrected entry can never leave the list
     * disagreeing with the statement it links to.
     */
    public static function withBalance(string $type)
    {
        return DB::table('party')
            ->leftJoin('party_ledger', 'party_ledger.party_id', '=', 'party.id')
            ->where('party.party_type', $type)
            ->select(
                'party.id',
                'party.name',
                'party.mobile',
                'party.whatsapp',
                'party.address',
                'party.is_active',
                DB::raw(PartyLedgerModel::BALANCE_SQL.' as current_balance'),
                DB::raw('COUNT(party_ledger.id) as entry_count')
            )
            ->groupBy('party.id', 'party.name', 'party.mobile', 'party.whatsapp', 'party.address', 'party.is_active')
            ->orderBy('party.name', 'asc')
            ->get();
    }

    /**
     * What is owed to you and what you owe, for the dashboard.
     *
     * Summed per party and only in the direction that party sits, not as one net
     * figure: a customer 10,000 in debit and another 10,000 in credit are not a
     * business with nothing outstanding, they are 10,000 to collect and 10,000
     * to refund. Netting them would hide both.
     *
     * @return array{receivable: float, payable: float, customers: int, vendors: int}
     */
    public static function outstanding(): array
    {
        $rows = DB::table('party')
            ->leftJoin('party_ledger', 'party_ledger.party_id', '=', 'party.id')
            ->select('party.id', 'party.party_type', DB::raw(PartyLedgerModel::BALANCE_SQL.' as balance'))
            ->groupBy('party.id', 'party.party_type')
            ->get();

        $totals = ['receivable' => 0.0, 'payable' => 0.0, 'customers' => 0, 'vendors' => 0];

        foreach ($rows as $row) {
            $balance = (float) $row->balance;

            if ($row->party_type === 'customer') {
                $totals['customers']++;
                if ($balance > 0) {
                    $totals['receivable'] += $balance;
                }
            } else {
                $totals['vendors']++;
                if ($balance < 0) {
                    $totals['payable'] += abs($balance);
                }
            }
        }

        return $totals;
    }

    /**
     * Parties for a dropdown, each carrying its balance so the entry form can
     * show it the moment one is picked without a second request.
     *
     * $includeId keeps one specific party in the list even after it has been
     * deactivated. An edit form must offer the party the record already points
     * at: drop it and the field silently re-saves as something else — for a
     * vendor that means quietly deleting their entry and moving their balance.
     */
    public static function selectList(string $type, $includeId = null)
    {
        return DB::table('party')
            ->leftJoin('party_ledger', 'party_ledger.party_id', '=', 'party.id')
            ->where('party.party_type', $type)
            ->where(function ($q) use ($includeId) {
                $q->where('party.is_active', 1);

                if ($includeId) {
                    $q->orWhere('party.id', $includeId);
                }
            })
            ->select('party.id', 'party.name', 'party.mobile', 'party.is_active', DB::raw(PartyLedgerModel::BALANCE_SQL.' as current_balance'))
            ->groupBy('party.id', 'party.name', 'party.mobile', 'party.is_active')
            ->orderBy('party.name', 'asc')
            ->get();
    }
}
