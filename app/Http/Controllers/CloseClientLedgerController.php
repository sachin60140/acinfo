<?php

namespace App\Http\Controllers;

use App\Models\ClientLedgerModel;
use App\Models\ClientModel;
use App\Models\PartyLedgerModel;
use App\Models\PartyModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closing the old Client Ledger, balance by balance.
 *
 * The owner retired the old book on 2026-09-23: money is recorded on the
 * Customer/Vendor Ledger's Entry screen only, and two books for the same money
 * meant it could be in either. What the old book still holds for or against a
 * client is carried to the customer who is that client — picked by the office,
 * or made from the client — as one line on each side. The old book's brings
 * the client to nothing ("Balance carried to Customer Ledger"); the
 * customer's opens with the same amount ("Balance brought from old Client
 * Ledger"). Both dated today, so a statement already sent is not changed.
 * Nothing is deleted: both books say what happened.
 *
 * Which customer is never guessed. A customer with the client's mobile is
 * offered first; the office decides.
 */
class CloseClientLedgerController extends Controller
{
    /** What the old book's closing line says. The client can read it on the old portal. */
    public const CARRIED = 'Balance carried to Customer Ledger';

    /** And the customer's opening line. */
    public const BROUGHT = 'Balance brought from old Client Ledger';

    public function index(Request $req)
    {
        $open = ClientLedgerModel::openBalances();

        $clients = ClientModel::whereIn('id', array_keys($open))->orderBy('name')->get(['id', 'name', 'mobile']);

        /*
         * Offered first where a customer has the client's own mobile — and one
         * is then not made again. Inactive too: found in checking it, such a
         * customer was not on the list to pick, and a new one could not be
         * made with their mobile, so that client could never be carried over.
         */
        $matched = PartyModel::where('party_type', 'customer')->whereIn('mobile', $clients->pluck('mobile'))->get(['id', 'name', 'mobile', 'is_active']);
        $byMobile = $matched->keyBy('mobile');

        $customers = PartyModel::selectList('customer')
            ->concat($matched->where('is_active', false)->map(fn ($party) => (object) [
                'id' => (int) $party->id,
                'name' => $party->name.' (inactive)',
                'mobile' => $party->mobile,
            ]))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $rows = $clients->map(fn ($client) => [
            'id' => (int) $client->id,
            'name' => (string) $client->name,
            'mobile' => (string) $client->mobile,
            // In the customer's book's words: Dr is owed to the office.
            'balance' => PartyLedgerModel::formatBalance(-$open[$client->id]),
            'suggested' => isset($byMobile[$client->mobile]) ? (int) $byMobile[$client->mobile]->id : null,
            'canCreate' => ! isset($byMobile[$client->mobile]),
        ])->values()->all();

        return view('admin.client-close-book', [
            'rows' => $rows,
            'customers' => $customers,
        ]);
    }

    public function carry(Request $req, int $id)
    {
        $req->validate([
            'customer' => ['required', 'string', 'max:20'],
        ], [
            'customer.required' => 'Pick the customer this client is, or make one from the client.',
        ]);

        [$client, $customer, $balance] = DB::transaction(function () use ($req, $id) {
            // Read again under a lock, so the same balance is never carried twice.
            $client = ClientModel::whereKey($id)->lockForUpdate()->firstOrFail();
            $balance = round((float) DB::table('client_ledger')->where('client_id', $client->id)->sum('amount'), 2);

            if (abs($balance) < 0.005) {
                throw ValidationException::withMessages(['customer' => $client->name.' has nothing left in the old book. It was carried over already.']);
            }

            if ($req->input('customer') === 'new') {
                if (PartyModel::where('party_type', 'customer')->where('mobile', $client->mobile)->exists()) {
                    throw ValidationException::withMessages(['customer' => 'A customer with '.$client->mobile.' already exists. Pick them instead.']);
                }

                $customer = new PartyModel;
                $customer->party_type = 'customer';
                $customer->name = $client->name;
                $customer->mobile = $client->mobile;
                $customer->address = $client->address;
                $customer->is_active = 1;
                $customer->save();
            } else {
                $customer = PartyModel::where('party_type', 'customer')->whereKey((int) $req->input('customer'))->first();

                if (! $customer) {
                    throw ValidationException::withMessages(['customer' => 'Pick a customer from the list.']);
                }
            }

            PartyModel::whereKey($customer->id)->lockForUpdate()->first();

            // The old book: the client to nothing.
            DB::table('client_ledger')->insert([
                'client_id' => $client->id,
                'payment_by' => (string) (DB::table('payment_type')->where('payment_mode', 'Other')->value('id') ?? ''),
                'amount' => -$balance,
                'particular' => self::CARRIED,
                'txn_date' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            /*
             * The customer: the same amount, on the side it falls. The old book
             * keeps money held for the client positive — in the customer's book
             * a credit — and money the client owes negative: a debit.
             */
            $line = new PartyLedgerModel;
            $line->party_id = $customer->id;
            $line->txn_date = now()->toDateString();
            $line->entry_type = $balance < 0 ? 'debit' : 'credit';
            $line->amount = abs($balance);
            $line->payment_mode = null;
            $line->particular = self::BROUGHT;

            // Where it came from, for the office. The customer reads only the line.
            if (PartyLedgerModel::reversible()) {
                $line->note = 'From the old Client Ledger: client #'.$client->id.', '.$client->name;
            }

            if (PartyLedgerModel::adjustable()) {
                $line->created_by = Auth::id();
            }

            $line->save();

            return [$client, $customer, $balance];
        });

        return redirect()->route('client.closebook')->with('success', 'Carried '.PartyLedgerModel::formatBalance(-$balance)
            .' from client '.$client->name.' to customer '.$customer->name.'. The old book now shows nothing for them.');
    }
}
