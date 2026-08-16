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

        // A customer normally starts out owing you (Dr); a vendor normally
        // starts out owed by you (Cr).
        return $this->partyFormScreen(null, $type, $type === 'customer' ? 'debit' : 'credit')->toResponse($req);
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

        return $this->partyFormScreen($party, $party->party_type, null)->toResponse($req);
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

        $label = PartyModel::label($type);

        // Pre-select the entry that adds to what the party owes, since that is
        // the commoner of the two on each screen.
        $defaultEntryType = $type === 'customer' ? 'debit' : 'credit';

        $props = [
            'action' => route('party.entry', $type),
            'csrf' => csrf_token(),
            'label' => $label,
            'indexUrl' => route('party.index', $type),
            // __ID__ is swapped client-side rather than the URL being built by
            // concatenation, so it always matches what the route generates.
            'statementUrl' => route('party.statement', ['id' => '__ID__']),
            'sideHint' => $type === 'customer'
                ? 'Debit = sale / amount charged · Credit = payment received'
                : 'Debit = payment made to vendor · Credit = purchase / bill received',
            'paymentModes' => PartyLedgerModel::PAYMENT_MODES,
            'parties' => PartyModel::selectList($type)->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'mobile' => $p->mobile,
                'current_balance' => (float) $p->current_balance,
            ])->values(),
            // The date field stays the shared partial rather than being rebuilt
            // in Vue: assets/js/datepicker.js owns that markup, and dd-mm-yyyy
            // everywhere is the whole reason it exists.
            'dateField' => view('partials._datefield', [
                'name' => 'txn_date',
                'value' => old('txn_date', date('Y-m-d')),
                'required' => true,
            ])->render(),
            // What Reset puts back, which is what the page loaded with —
            // including a rejected submission's own values.
            'initial' => [
                'party_id' => (string) old('party_id'),
                'entry_type' => old('entry_type', $defaultEntryType),
                'amount' => (string) old('amount'),
                'payment_mode' => (string) old('payment_mode'),
                'ref_no' => (string) old('ref_no'),
                'particular' => (string) old('particular'),
            ],
        ];

        return Screen::make('admin.party.entry', 'vue-party-entry', $props, [
            'type' => $type,
            'label' => $label,
            // Nothing can be entered against a side with no parties on it.
            'partyCount' => count($props['parties']),
        ])->toResponse($req);
    }

    public function statement(Request $req, $id)
    {
        $req->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
        ]);

        $party = PartyModel::findOrFail($id);

        $data = PartyLedgerModel::statement($party->id, $req->query('from'), $req->query('to'));

        $from = $req->query('from');
        $to = $req->query('to');

        $fromText = $from ? date('d-m-Y', strtotime($from)) : 'Beginning';
        $toText = $to ? date('d-m-Y', strtotime($to)) : 'Till date';
        $periodText = $from || $to ? $fromText.' to '.$toText : 'All transactions';

        /*
         * The running balance is accumulated here, in the order the query
         * returned, carried forward from the opening balance. That order is the
         * only order in which these figures mean anything, which is why the grid
         * is handed sortable => false.
         */
        $running = (float) $data['opening'];
        $entries = [];

        foreach ($data['getRecords'] as $entry) {
            $running += $entry->signedAmount();
            $isDebit = $entry->entry_type === 'debit';

            $entries[] = [
                'id' => $entry->id,
                'txn_date' => date('d-m-Y', strtotime($entry->txn_date)),
                'particular' => $entry->particular,
                'payment_mode' => $entry->payment_mode,
                'ref_no' => $entry->ref_no,
                // Entries a work file generated link back to it; entries typed
                // straight into the ledger just carry whatever reference was given.
                'ref_url' => $entry->work_file_id ? route('workfile.edit', $entry->work_file_id) : null,
                // The side an entry does not fall on stays null, so it exports as
                // a blank cell the way the old table did rather than as 0.00.
                'debit' => $isDebit ? (float) $entry->amount : null,
                'credit' => $isDebit ? null : (float) $entry->amount,
                'balance' => round($running, 2),
            ];
        }

        $props = [
            // Also the export filename and the heading on the PDF and the printout.
            'title' => $party->name.' Statement '.$periodText,
            'columns' => [
                ['key' => 'id', 'label' => '#'],
                ['key' => 'txn_date', 'label' => 'Txn Date'],
                ['key' => 'particular', 'label' => 'Particulars', 'width' => '14rem'],
                ['key' => 'payment_mode', 'label' => 'Mode'],
                // The work file opens in a new tab because a statement is read
                // through rather than clicked out of: following the reference in
                // this tab costs the reader their place in the run and the period
                // they filtered to, both of which have to be set up again.
                ['key' => 'ref_no', 'label' => 'Ref No.', 'type' => 'link', 'linkTo' => 'ref_url', 'newTab' => true],
                // The column colour says which side of the ledger it is; the cell
                // dims itself on the side an entry did not fall on.
                ['key' => 'debit', 'label' => 'Debit', 'type' => 'money', 'class' => 'ui-money--dr'],
                ['key' => 'credit', 'label' => 'Credit', 'type' => 'money', 'class' => 'ui-money--cr'],
                ['key' => 'balance', 'label' => 'Balance', 'type' => 'balance', 'class' => 'ui-money--strong'],
            ],
            'rows' => $entries,
            'perPage' => 50,
            /*
             * Never sortable. Balance is a running total carried forward from the
             * opening balance, so re-ordering the rows detaches every figure from
             * the row it belongs to and the statement is quietly wrong.
             */
            'sortable' => false,
            'totals' => ['debit' => 'sum', 'credit' => 'sum'],
            'emptyText' => 'No transactions in this period.',
        ];

        $today = now();

        // The Indian financial year starts in April, so "this year" on a
        // statement means April to March, not January to December.
        $fyStart = $today->month >= 4
            ? $today->copy()->startOfYear()->addMonths(3)
            : $today->copy()->subYear()->startOfYear()->addMonths(3);

        return Screen::make('admin.party.statement', 'vue-party-statement', $props, [
            'type' => $party->party_type,
            'label' => PartyModel::label($party->party_type),
            'partyName' => $party->name,
            'partyMobile' => $party->mobile,
            'partyAddress' => $party->address,
            // Blank means "no separate WhatsApp number", so it falls back to the
            // mobile — the same number in most cases.
            'wa' => $party->whatsapp ?: $party->mobile,
            'from' => $from,
            'to' => $to,
            'fromText' => $fromText,
            'toText' => $toText,
            'periodText' => $periodText,
            'entryCount' => count($entries),
            'opening' => (float) $data['opening'],
            'debits' => (float) $data['debits'],
            'credits' => (float) $data['credits'],
            'closing' => (float) $data['closing'],
            'base' => route('party.statement', $party->id),
            'maxDate' => $today->toDateString(),
            // The quick-range links, resolved here so the template does no date
            // arithmetic of its own.
            'monthStart' => $today->copy()->startOfMonth()->toDateString(),
            'monthEnd' => $today->copy()->endOfMonth()->toDateString(),
            'fyStart' => $fyStart->toDateString(),
            'fyEnd' => $fyStart->copy()->addYear()->subDay()->toDateString(),
        ])->toResponse($req);
    }
    /**
     * The add and edit forms are the same screen with a party or without one,
     * so they describe it in one place rather than twice.
     */
    private function partyFormScreen(?PartyModel $party, string $type, ?string $defaultOpeningType): Screen
    {
        $isEdit = (bool) $party;

        /*
         * An unchecked checkbox posts nothing, so old('is_active') is absent
         * both when the form is fresh and when the user deliberately cleared it.
         * Only the presence of validation errors tells the two apart.
         */
        $bag = session('errors');
        $activeChecked = $isEdit ? (($bag && $bag->any()) ? old('is_active') : $party->is_active) : true;

        $props = [
            'action' => $isEdit ? route('party.edit', $party->id) : route('party.create', $type),
            'csrf' => csrf_token(),
            'label' => PartyModel::label($type),
            'indexUrl' => route('party.index', $type),
            'isEdit' => $isEdit,
            'isActive' => (bool) $activeChecked,
            'defaultOpeningType' => $defaultOpeningType ?? 'debit',
            'values' => [
                'name' => old('name', $isEdit ? $party->name : ''),
                'mobile' => old('mobile', $isEdit ? $party->mobile : ''),
                'whatsapp' => old('whatsapp', $isEdit ? $party->whatsapp : ''),
                'address' => old('address', $isEdit ? $party->address : ''),
                'opening_balance' => old('opening_balance', ''),
                'opening_type' => old('opening_type', $defaultOpeningType ?? 'debit'),
            ],
            // Rendered here rather than rebuilt in the component: the date box
            // keeps one markup contract, the one assets/js/datepicker.js binds
            // by class.
            'dateField' => $isEdit ? '' : view('partials._datefield', [
                'name' => 'opening_date',
                'value' => old('opening_date', date('Y-m-d')),
            ])->render(),
            // The summary list stays as it is; this puts the same message
            // against the field it came from. Cast so an empty bag still
            // arrives as an object rather than as an array.
            'errors' => (object) array_map(fn ($m) => $m[0], $bag ? $bag->messages() : []),
        ];

        return Screen::make('admin.party.form', 'vue-party-form', $props, [
            'type' => $type,
            'label' => PartyModel::label($type),
            'isEdit' => $isEdit,
        ]);
    }

}
