<?php

namespace App\Http\Controllers;

use App\Models\PartyLedgerModel;
use App\Models\PartyModel;
use App\Support\Screen;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Vendor & Customer ledgers.
 *
 * Entirely separate from the client ledger: its own tables, its own routes and
 * its own screens. Nothing here reads or writes client, client_ledger or
 * payment_type.
 */
class PartyController extends Controller
{
    /**
     * Reject anything that is not a known party type before it reaches a query.
     * Every route in this controller is keyed on {type}, so this is the single
     * gate that keeps an arbitrary URL segment out of the WHERE clause.
     */
    private function guardType(string $type): void
    {
        if (! array_key_exists($type, PartyModel::TYPES)) {
            abort(404);
        }
    }

    public function index(Request $req, string $type)
    {
        $this->guardType($type);

        $label = PartyModel::label($type);
        $parties = PartyModel::withBalance($type);

        /*
         * Both sides, never netted. A customer in debit set against one in
         * credit would report a business with nothing outstanding when it has
         * money to collect and money to refund — two different facts.
         */
        $totalDr = 0.0;
        $totalCr = 0.0;

        foreach ($parties as $p) {
            if ($p->current_balance >= 0) {
                $totalDr += (float) $p->current_balance;
            } else {
                $totalCr += (float) abs($p->current_balance);
            }
        }

        $rows = $parties->map(function ($party) {
            // Blank means "no separate WhatsApp number", so the link falls back
            // to the mobile — which is the same number in most cases.
            $wa = $party->whatsapp ?: $party->mobile;

            return [
                'id' => (int) $party->id,
                'name' => $party->name,
                'statement_url' => route('party.statement', $party->id),
                'inactive_note' => $party->is_active ? null : 'Inactive',
                'mobile' => $party->mobile,
                'mobile_url' => 'tel:'.$party->mobile,
                'whatsapp' => $wa,
                'whatsapp_url' => 'https://wa.me/91'.$wa,
                'address' => $party->address,
                'entry_count' => (int) $party->entry_count,
                'current_balance' => (float) $party->current_balance,
                'action' => 'Edit',
                'edit_url' => route('party.edit', $party->id),

                // The second action the old cell carried; it reuses the URL the
                // name already links to.
                'statement_action' => 'Statement',
            ];
        })->values();

        /*
         * Column order, sortability and the export set are the ones the old
         * DataTables config carried; several of them were fixes.
         *
         * Balance is typed 'balance', so it sorts on the raw signed figure and
         * prints "1,200.00 Cr" — which is what the data-order attribute on the
         * old cell existed to achieve. Its figures follow number_format, the
         * same convention as the Receivable/Payable line above the grid, so a
         * balance reads identically whichever of the two the eye lands on.
         *
         * Action is unsortable, as it was, kept out of exports and out of the
         * search text: a column of the word "Edit" is not data, and while it is
         * searched every row matches anyone typing "edit". It carries both of
         * the actions the old cell offered — Edit on the line, Statement quietly
         * beneath it. That way round because a sub-line link always opens in a
         * new tab, and the statement is the one meant to be read beside the
         * list; editing replaces it, as it always did.
         *
         * No totals row, for the reason the two sides are summed separately
         * above.
         *
         * The grid opens unsorted, which lands on name ascending: withBalance()
         * already orders by name, and that is the order the old table was
         * configured to sort itself into on load.
         */
        $props = [
            'columns' => [
                ['key' => 'id', 'label' => '#'],

                // The name and the WhatsApp number both leave this page for
                // somewhere read alongside it, so neither takes the list with it.
                ['key' => 'name', 'label' => 'Name', 'type' => 'link', 'linkTo' => 'statement_url', 'newTab' => true, 'sub' => 'inactive_note'],
                ['key' => 'mobile', 'label' => 'Mobile', 'type' => 'link', 'linkTo' => 'mobile_url'],
                ['key' => 'whatsapp', 'label' => 'WhatsApp', 'type' => 'link', 'linkTo' => 'whatsapp_url', 'newTab' => true, 'class' => 'wa-cell'],
                ['key' => 'address', 'label' => 'Address'],
                ['key' => 'entry_count', 'label' => 'Entries', 'type' => 'count'],
                ['key' => 'current_balance', 'label' => 'Balance', 'type' => 'balance'],
                [
                    'key' => 'action',
                    'label' => 'Action',
                    'type' => 'link',
                    'linkTo' => 'edit_url',
                    'sub' => 'statement_action',
                    'subLinkTo' => 'statement_url',
                    'sortable' => false,
                    'searchable' => false,
                    'exportable' => false,
                ],

                // Carried for searching only: "inactive" finds the deactivated
                // parties, whose marker is otherwise a quiet line under the name.
                ['key' => 'inactive_note', 'label' => 'Status', 'hidden' => true],
            ],
            'rows' => $rows,
            'title' => $label.' Ledgers',
            'perPage' => 50,
            'emptyText' => 'No '.Str::lower($label).'s yet — use the Add button above.',
        ];

        return Screen::make('admin.party.index', 'vue-party-list', $props, [
            'type' => $type,
            'label' => $label,
            'totalDr' => $totalDr,
            'totalCr' => $totalCr,
            'partyCount' => $rows->count(),
        ])->toResponse($req);
    }

    public function create(Request $req, string $type)
    {
        $this->guardType($type);

        if ($req->isMethod('POST')) {
            $req->validate([
                'name' => 'required|string|max:255',
                'mobile' => ['required', 'digits:10', Rule::unique('party', 'mobile')->where('party_type', $type)],
                'whatsapp' => 'nullable|digits:10',
                'address' => 'nullable|string|max:255',
                'opening_balance' => 'nullable|numeric|gte:0|max:99999999',
                'opening_type' => 'required_with:opening_balance|nullable|in:debit,credit',
                'opening_date' => 'required_with:opening_balance|nullable|date_format:Y-m-d',
            ]);

            // The party and its opening balance are one act of data entry — a
            // party saved without the opening figure the user typed would show a
            // wrong balance from the very first screen.
            $party = DB::transaction(function () use ($req, $type) {
                $party = new PartyModel;
                $party->party_type = $type;
                $party->name = $req->name;
                $party->mobile = $req->mobile;
                $party->whatsapp = $req->whatsapp;
                $party->address = $req->address;
                $party->is_active = 1;
                $party->save();

                if ($req->filled('opening_balance') && (float) $req->opening_balance > 0) {
                    $opening = new PartyLedgerModel;
                    $opening->party_id = $party->id;
                    $opening->txn_date = $req->opening_date;
                    $opening->entry_type = $req->opening_type;
                    $opening->amount = (float) $req->opening_balance;
                    $opening->payment_mode = null;
                    $opening->particular = 'Opening Balance';
                    $opening->save();
                }

                return $party;
            });

            return redirect()->route('party.index', $type)
                ->with('success', PartyModel::label($type).' "'.$party->name.'" added successfully. ID: '.$party->id);
        }

        return view('admin.party.form', [
            'type' => $type,
            'label' => PartyModel::label($type),
            'party' => null,
            // A customer normally starts out owing you (Dr); a vendor normally
            // starts out owed by you (Cr).
            'defaultOpeningType' => $type === 'customer' ? 'debit' : 'credit',
        ]);
    }

    public function edit(Request $req, $id)
    {
        $party = PartyModel::findOrFail($id);

        if ($req->isMethod('POST')) {
            $req->validate([
                'name' => 'required|string|max:255',
                'mobile' => ['required', 'digits:10', Rule::unique('party', 'mobile')->where('party_type', $party->party_type)->ignore($party->id)],
                'whatsapp' => 'nullable|digits:10',
                'address' => 'nullable|string|max:255',
            ]);

            $party->name = $req->name;
            $party->mobile = $req->mobile;
            $party->whatsapp = $req->whatsapp;
            $party->address = $req->address;
            $party->is_active = $req->boolean('is_active');
            $party->save();

            return redirect()->route('party.index', $party->party_type)
                ->with('success', PartyModel::label($party->party_type).' "'.$party->name.'" updated successfully.');
        }

        return view('admin.party.form', [
            'type' => $party->party_type,
            'label' => PartyModel::label($party->party_type),
            'party' => $party,
            'defaultOpeningType' => null,
        ]);
    }

    public function entry(Request $req, string $type)
    {
        $this->guardType($type);

        if ($req->isMethod('POST')) {
            $req->validate([
                // Constrained to this type as well as to an existing row, so a
                // tampered form cannot post a vendor id into the customer screen.
                'party_id' => ['required', 'integer', Rule::exists('party', 'id')->where('party_type', $type)],
                'entry_type' => 'required|in:debit,credit',
                'txn_date' => 'required|date_format:Y-m-d',
                'amount' => 'required|numeric|gt:0|max:99999999',
                'payment_mode' => ['nullable', Rule::in(PartyLedgerModel::PAYMENT_MODES)],
                'ref_no' => 'nullable|string|max:50',
                'particular' => 'required|string|max:255',
            ]);

            $entry = new PartyLedgerModel;
            $entry->party_id = $req->party_id;
            $entry->txn_date = $req->txn_date;
            $entry->entry_type = $req->entry_type;
            $entry->amount = (float) $req->amount;
            $entry->payment_mode = $req->payment_mode;
            $entry->ref_no = $req->ref_no;
            $entry->particular = $req->particular;
            $entry->save();

            return back()->with('success', ucfirst($entry->entry_type).' entry saved successfully. Transaction ID: '.$entry->id);
        }

        return view('admin.party.entry', [
            'type' => $type,
            'label' => PartyModel::label($type),
            'partylist' => PartyModel::selectList($type),
            'paymentModes' => PartyLedgerModel::PAYMENT_MODES,
            // Pre-select the entry that adds to what the party owes, since that
            // is the commoner of the two on each screen.
            'defaultEntryType' => $type === 'customer' ? 'debit' : 'credit',
        ]);
    }

    public function statement(Request $req, $id)
    {
        $req->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
        ]);

        $party = PartyModel::findOrFail($id);

        $data = PartyLedgerModel::statement($party->id, $req->query('from'), $req->query('to'));
        $data['party'] = $party;
        $data['type'] = $party->party_type;
        $data['label'] = PartyModel::label($party->party_type);

        return view('admin.party.statement', $data);
    }
}
