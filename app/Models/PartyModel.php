<?php

namespace App\Models;

use App\Http\Controllers\CloseClientLedgerController;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PartyModel extends Model
{
    public $table = 'party';

    /**
     * Kept out of anything this model is serialised into.
     *
     * Screens hand their data to the browser as JSON, and a model that ever
     * reaches one of those payloads whole would carry the hash with it. Nothing
     * does that today; this makes it not matter if something starts to.
     */
    protected $hidden = ['password'];

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
                DB::raw('COUNT(party_ledger.id) as entry_count'),
                // Whether a portal login exists, as a flag rather than the hash
                // itself — the list has no use for the hash and it should not
                // travel to a screen just because it sits on the same row.
                DB::raw("(party.password is not null and party.password <> '') as has_login")
            )
            /*
             * party.password is grouped rather than left to functional
             * dependency on the primary key: MySQL 8 works that out, MariaDB is
             * less willing, and production is MariaDB. Grouping by it changes
             * nothing — party.id is already here and is unique.
             */
            ->groupBy('party.id', 'party.name', 'party.mobile', 'party.whatsapp', 'party.address', 'party.is_active', 'party.password')
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
     * @return array{receivable: float, payable: float, customers: int, vendors: int, owing: int, owed: int}
     */
    public static function outstanding(): array
    {
        $rows = DB::table('party')
            ->leftJoin('party_ledger', 'party_ledger.party_id', '=', 'party.id')
            ->select('party.id', 'party.party_type', DB::raw(PartyLedgerModel::BALANCE_SQL.' as balance'))
            ->groupBy('party.id', 'party.party_type')
            ->get();

        // owing: the customers the receivable is made of — the Collection List;
        // owed: the vendors the payable is, Vendor Payments.
        $totals = ['receivable' => 0.0, 'payable' => 0.0, 'customers' => 0, 'vendors' => 0, 'owing' => 0, 'owed' => 0];

        foreach ($rows as $row) {
            $balance = (float) $row->balance;

            if ($row->party_type === 'customer') {
                $totals['customers']++;
                // The Collection List's own threshold, so the two never
                // disagree about who owes by a customer.
                if ($balance > 0.005) {
                    $totals['receivable'] += $balance;
                    $totals['owing']++;
                }
            } else {
                $totals['vendors']++;
                if ($balance < -0.005) {
                    $totals['payable'] += abs($balance);
                    $totals['owed']++;
                }
            }
        }

        return $totals;
    }

    /**
     * The customer who has owed money longest, and since when.
     *
     * Not the largest debt — the oldest. A balance of 5,000 from March is a
     * different conversation from 50,000 from last week, and only one of them
     * is a problem that has been ignored.
     *
     * Dated from the oldest charge a customer has still not paid, rather than
     * from their last movement — somebody who part-paid last week has not
     * stopped owing you for March — or from their first entry ever: a
     * customer since 2024 who paid everything and owes for last week's file
     * has owed for a week. The Collection List's rule, so the tile and the
     * list it sits beside name the same customer and the same day.
     *
     * Returns null when nobody is in debit, which is when the tile should not
     * appear at all — see the note on the Awaiting Price tile.
     *
     * @return array{id: int, name: string, amount: float, since: string, days: int}|null
     */
    public static function oldestUnpaid(): ?array
    {
        $owing = self::withBalance('customer')
            ->filter(fn ($party) => (float) $party->current_balance > 0.005)
            ->keyBy('id');

        $dues = self::dues($owing->keys()->map(fn ($id) => (int) $id)->all());

        // The list's order: the oldest day, then the most owed, then the name.
        $oldest = $owing->filter(fn ($party) => isset($dues[(int) $party->id]))
            ->sort(fn ($a, $b) => [$dues[(int) $a->id][0]['since'], -(float) $a->current_balance, $a->name]
                <=> [$dues[(int) $b->id][0]['since'], -(float) $b->current_balance, $b->name])
            ->first();

        if (! $oldest) {
            return null;
        }

        $since = $dues[(int) $oldest->id][0]['since'];

        return [
            'id' => (int) $oldest->id,
            'name' => $oldest->name,
            'amount' => round((float) $oldest->current_balance, 2),
            'since' => date('d-m-Y', strtotime($since)),
            // Never negative: a charge dated ahead is not owed for minus days.
            'days' => max(0, (int) Carbon::parse($since)->startOfDay()->diffInDays(now()->startOfDay())),
        ];
    }

    /**
     * What each customer still owes, bill by bill, the longest-owed first —
     * PartyLedgerModel::dueBills(), with one correction.
     *
     * A balance carried from the old Client Ledger is dated the day it was
     * carried, so statements already sent stay as they were; left at that it
     * would read as a debt from today. It is dated instead from the old book's
     * own charges (ClientLedgerModel::owedSince), found through the client the
     * carrying line names, and marked old_book so a screen can say where it
     * came from. A line that names no client keeps the day it was carried.
     *
     * @param  array<int, int>  $customerIds
     * @return array<int, list<array{file_id: int|null, entry_id: int|null, seq: int, since: string, due: float, old_book: bool}>>
     */
    public static function dues(array $customerIds): array
    {
        $dues = PartyLedgerModel::dueBills($customerIds);

        $looseIds = [];

        foreach ($dues as $bills) {
            foreach ($bills as $bill) {
                if ($bill['entry_id'] !== null) {
                    $looseIds[] = $bill['entry_id'];
                }
            }
        }

        $brought = $looseIds
            ? DB::table('party_ledger')
                ->whereIn('id', $looseIds)
                ->where('particular', CloseClientLedgerController::BROUGHT)
                ->get(PartyLedgerModel::reversible() ? ['id', 'note'] : ['id'])
                ->keyBy('id')
            : collect();

        // Which client each came from, and how much of it is still unpaid.
        $clientOf = [];
        $owed = [];

        foreach ($dues as $bills) {
            foreach ($bills as $bill) {
                $line = $bill['entry_id'] !== null ? ($brought[$bill['entry_id']] ?? null) : null;

                if ($line && preg_match('/client #(\d+)/', (string) ($line->note ?? ''), $match)) {
                    $clientOf[$bill['entry_id']] = (int) $match[1];
                    $owed[(int) $match[1]] = ($owed[(int) $match[1]] ?? 0) + $bill['due'];
                }
            }
        }

        $since = ClientLedgerModel::owedSince($owed);

        foreach ($dues as $partyId => $bills) {
            foreach ($bills as $i => $bill) {
                $entry = $bill['entry_id'];
                $client = $entry !== null ? ($clientOf[$entry] ?? null) : null;

                $dues[$partyId][$i]['old_book'] = $entry !== null && isset($brought[$entry]);

                if ($client !== null && isset($since[$client]) && $since[$client] < $bill['since']) {
                    $dues[$partyId][$i]['since'] = $since[$client];
                }
            }

            usort($dues[$partyId], fn ($a, $b) => [$a['since'], $a['seq']] <=> [$b['since'], $b['seq']]);
        }

        return $dues;
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

    /**
     * The other account of the same person — a customer's vendor account, or a
     * vendor's customer account — as the office linked them by hand, or null.
     *
     * Never guessed from a mobile number: two people share a phone, and a typo
     * would set one person's debt off against a stranger's money.
     */
    public function counterpartId(): ?int
    {
        if (! PartyLedgerModel::canSetOff()) {
            return null;
        }

        $id = $this->party_type === 'customer'
            ? $this->linked_vendor_id
            : self::where('party_type', 'customer')->where('linked_vendor_id', $this->id)->value('id');

        return $id ? (int) $id : null;
    }

    /**
     * Every party of one type that is linked to an account of the other, with
     * that account as the Entry screen needs it: whose it is (own_id), its id,
     * name, balance and whether it is active. For the office's screen only.
     *
     * A list rather than keyed by party. Found in review: keyed by id, the
     * screen's recorded shape named one party's id, and the check on it failed
     * for good the day the office linked anybody.
     *
     * @return list<array{own_id: int, id: int, name: string, balance: float, active: bool}>
     */
    public static function counterparts(string $type): array
    {
        if (! PartyLedgerModel::canSetOff()) {
            return [];
        }

        $linked = DB::table('party')->where('party_type', 'customer')->whereNotNull('linked_vendor_id');

        // Own id => the other account's id, whichever side this screen is.
        $pairs = $type === 'customer'
            ? $linked->pluck('linked_vendor_id', 'id')
            : $linked->pluck('id', 'linked_vendor_id');

        if ($pairs->isEmpty()) {
            return [];
        }

        $others = DB::table('party')->whereIn('id', $pairs->values())->get(['id', 'name', 'is_active'])->keyBy('id');
        $balances = PartyLedgerModel::balancesFor($pairs->values()->all());
        $out = [];

        foreach ($pairs as $own => $other) {
            if ($one = $others[$other] ?? null) {
                $out[] = [
                    'own_id' => (int) $own,
                    'id' => (int) $other,
                    'name' => (string) $one->name,
                    'balance' => round((float) ($balances[$other] ?? 0), 2),
                    'active' => (bool) $one->is_active,
                ];
            }
        }

        return $out;
    }

    /**
     * The one party a mobile number may sign in as, or null.
     *
     * Three conditions, all of them here rather than spread across the
     * controller: it is a customer, it is still active, and it has been given a
     * password. A vendor with a password set by accident, or a customer
     * deactivated after they stopped trading, must not get in — and the place
     * to be sure of that is the query, not a sequence of ifs that a later edit
     * can reorder.
     *
     * The password itself is not checked here. That is the caller's job, and it
     * has to happen against a hash even when no party matched, or how long the
     * response takes says whether the number is one of ours.
     */
    public static function findForLogin(string $mobile): ?self
    {
        return self::query()
            ->where('party_type', 'customer')
            ->where('is_active', 1)
            ->where('mobile', $mobile)
            ->whereNotNull('password')
            ->where('password', '!=', '')
            ->first();
    }

    /** Whether a login has been issued for this party at all. */
    public function hasLogin(): bool
    {
        return filled($this->password);
    }
}
