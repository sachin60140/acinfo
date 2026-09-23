<?php

namespace App\Http\Controllers;

use App\Models\PartyLedgerModel;
use App\Models\PartyModel;
use App\Models\WorkFileModel;
use App\Support\Screen;
use App\Support\WhatsApp;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

        // Only customers can sign in, so only their list offers the action.
        $hasPortal = $type === 'customer';

        $rows = $parties->map(function ($party) use ($hasPortal) {
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
                // None for a number WhatsApp cannot use: it is drawn as plain
                // text rather than as a link to an error.
                'whatsapp_url' => WhatsApp::url($wa),
                'address' => $party->address,
                'entry_count' => (int) $party->entry_count,
                'current_balance' => (float) $party->current_balance,
                'action' => 'Edit',
                'edit_url' => route('party.edit', $party->id),

                // The second action the old cell carried; it reuses the URL the
                // name already links to.
                'statement_action' => 'Statement',

                /*
                 * The portal login. The word changes with the state so the
                 * column answers "can this customer sign in?" at a glance,
                 * which is the question the office actually has — an unchanging
                 * "Password" on every row answers nothing.
                 */
                'login_action' => $hasPortal ? ($party->has_login ? 'Change' : 'Set') : null,
                'login_url' => $hasPortal ? route('party.password', $party->id) : null,
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
                ['key' => 'name', 'label' => 'Name', 'type' => 'link', 'linkTo' => 'statement_url', 'sub' => 'inactive_note'],
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
                    'sortable' => false,
                    'searchable' => false,
                    'exportable' => false,
                ],
                [
                    'key' => 'statement_action',
                    'label' => 'Statement',
                    'type' => 'link',
                    'linkTo' => 'statement_url',
                    'sortable' => false,
                    'searchable' => false,
                    'exportable' => false,
                ],

                /*
                 * The portal login, on the customer list only. Kept out of the
                 * exports and the search text for the same reason as the two
                 * actions above it: a column of the word "Set" is not data.
                 */
                ...($hasPortal ? [[
                    'key' => 'login_action',
                    'label' => 'Login',
                    'type' => 'link',
                    'linkTo' => 'login_url',
                    'sortable' => false,
                    'searchable' => false,
                    'exportable' => false,
                ]] : []),

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

    /**
     * Give a customer a login, or replace the one they have.
     *
     * The office issues these; there is no self-registration and no reset link,
     * because you know all five of your customers by name and a reset link is a
     * second way in to build and defend.
     *
     * Customers only. A vendor with a password could not sign in anyway —
     * PartyModel::findForLogin refuses them — but a screen offering to set one
     * says otherwise, and a guard that only exists in the query is a guard one
     * refactor from being the only thing anybody remembers.
     */
    public function password(Request $req, $id)
    {
        $party = PartyModel::findOrFail($id);

        abort_if($party->party_type !== 'customer', 404);

        if ($req->isMethod('POST')) {
            $req->validate([
                'password' => 'required|min:8|max:255|confirmed',
            ]);

            $party->password = Hash::make($req->password);
            $party->save();

            return redirect()->route('party.index', 'customer')
                ->with('success', 'Login password set for "'.$party->name.'".');
        }

        $props = [
            'action' => route('party.password', $party->id),
            'csrf' => csrf_token(),
            'cancelUrl' => route('party.index', 'customer'),
            // The component was written for clients and names its props that
            // way. Reused rather than copied: the screen is the same screen.
            'clientName' => $party->name,
            'clientMobile' => (string) $party->mobile,
            // Whether this replaces a working login or creates the first one.
            // The hash itself never leaves the server.
            'hasPassword' => $party->hasLogin(),
            'errors' => (object) array_map(fn ($messages) => $messages[0], session('errors') ? session('errors')->messages() : []),
        ];

        return Screen::make('admin.party.password', 'vue-client-password', $props, [
            'partyName' => $party->name,
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
            /*
             * The vendor account that is this same customer, said by the
             * office and never guessed; see PartyModel::counterpartId(). On
             * the customer's form only, so the link has one owner. One vendor
             * is one customer's, and a set-off already made keeps its own
             * pair whatever the link says later.
             */
            $linking = PartyLedgerModel::canSetOff() && $party->party_type === 'customer';

            $req->validate([
                'name' => 'required|string|max:255',
                'mobile' => ['required', 'digits:10', Rule::unique('party', 'mobile')->where('party_type', $party->party_type)->ignore($party->id)],
                'whatsapp' => 'nullable|digits:10',
                'address' => 'nullable|string|max:255',
            ] + ($linking ? [
                'linked_vendor_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('party', 'id')->where('party_type', 'vendor'),
                    Rule::unique('party', 'linked_vendor_id')->ignore($party->id),
                ],
            ] : []), [
                'linked_vendor_id.exists' => 'Pick a vendor from the list.',
                'linked_vendor_id.unique' => 'That vendor is already linked to another customer.',
            ]);

            $party->name = $req->name;
            $party->mobile = $req->mobile;
            $party->whatsapp = $req->whatsapp;
            $party->address = $req->address;
            $party->is_active = $req->boolean('is_active');

            if ($linking) {
                $vendorId = $req->filled('linked_vendor_id') ? (int) $req->linked_vendor_id : null;

                // Who said so and when, only when it changes.
                if ($vendorId !== ($party->linked_vendor_id ? (int) $party->linked_vendor_id : null)) {
                    $party->linked_vendor_id = $vendorId;
                    $party->linked_by = $vendorId ? Auth::id() : null;
                    $party->linked_at = $vendorId ? now() : null;
                }
            }

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
                // Not asked for on a write-off or a set-off: the server writes
                // what each says.
                'particular' => [Rule::requiredIf(! in_array($req->input('entry_kind'), [PartyLedgerModel::WRITEOFF, PartyLedgerModel::SETOFF], true)), 'nullable', 'string', 'max:255'],

                /*
                 * Writing off a difference rather than taking money: the entry
                 * is the same shape, said as a Discount on the customer's
                 * statement, and why it was given is kept for the office.
                 *
                 * Or setting a customer off against their own vendor account:
                 * see saveSetOff(). A remark is the office's own, and optional.
                 */
                'entry_kind' => ['nullable', Rule::in([PartyLedgerModel::WRITEOFF, PartyLedgerModel::SETOFF])],
                'reason' => [Rule::requiredIf($req->input('entry_kind') === PartyLedgerModel::WRITEOFF), 'nullable', 'string', 'max:255'],
                'counterpart_id' => 'nullable|integer',

                // The files this payment is adjusted against, keyed by file;
                // see below. An empty box is no line at all.
                // No count here: the limit is on lines with an amount, below.
                'alloc' => 'nullable|array',
                'alloc.*.work_file_id' => 'required|integer',
                'alloc.*.amount' => 'nullable|numeric|min:0|max:99999999',
                // A set-off's other account's files, the same way.
                'counter_alloc' => 'nullable|array',
                'counter_alloc.*.work_file_id' => 'required|integer',
                'counter_alloc.*.amount' => 'nullable|numeric|min:0|max:99999999',
            ], [
                'alloc.*.amount.numeric' => 'An amount against a file must be a number.',
                'counter_alloc.*.amount.numeric' => 'An amount against a file must be a number.',
                'reason.required' => 'Say why the rest is being given up — it is kept for the office and the customer never sees it.',
            ]);

            /*
             * Adjusted against files: which of this party's files the payment
             * is for. What it does not cover settles the oldest charges, as
             * every payment did before; see PartyLedgerModel::settle().
             */
            $lines = self::allocLines($req);

            // A customer pays with a credit; the office pays a vendor with a debit.
            $paymentSide = self::paymentSide($type);

            $writeOff = $req->input('entry_kind') === PartyLedgerModel::WRITEOFF;
            $cap = PartyLedgerModel::writeOffCap();

            if ($writeOff && ($refused = self::writeOffRefusal($req, $type, $lines, $cap))) {
                return back()->withInput()->withErrors(['alloc' => $refused]);
            }

            /*
             * The word belongs to a write-off. Typed on an ordinary entry it
             * would read to the customer exactly as one, with none of the rules
             * behind it and nothing in the report that counts them.
             */
            if (! $writeOff && strcasecmp(trim((string) $req->particular), PartyLedgerModel::WRITEOFF_PARTICULAR) === 0) {
                return back()->withInput()->withErrors([
                    'particular' => 'Tick "Write this off" to give up a difference. An ordinary entry cannot be called a Discount.',
                ]);
            }

            if ($req->input('entry_kind') === PartyLedgerModel::SETOFF) {
                return $this->saveSetOff($req, $type, $lines);
            }

            // Nor may one be worded as a set-off, with no other half behind it.
            $said = strtolower(trim((string) $req->particular));

            if (in_array($said, [strtolower(PartyLedgerModel::SETOFF_CUSTOMER_PARTICULAR), strtolower(PartyLedgerModel::SETOFF_VENDOR_PARTICULAR)], true)) {
                return back()->withInput()->withErrors([
                    'particular' => 'Tick "Set off" to clear what they owe against what they are owed. An ordinary entry cannot be worded as a set-off.',
                ]);
            }

            if ($lines->isNotEmpty()) {
                if ($req->entry_type !== $paymentSide) {
                    return back()->withInput()->withErrors([
                        'alloc' => 'Only a payment can be adjusted against files — a '
                            .($paymentSide === 'credit' ? 'Credit' : 'Debit').' on this screen.',
                    ]);
                }

                if (! PartyLedgerModel::adjustable()) {
                    return back()->withInput()->withErrors([
                        'alloc' => 'Adjusting against files needs the database update that came with it (php artisan migrate).',
                    ]);
                }
            }

            if ($refused = self::allocRefusal($lines, (float) $req->amount)) {
                return back()->withInput()->withErrors(['alloc' => $refused]);
            }

            $entry = DB::transaction(function () use ($req, $lines, $type, $writeOff, $cap) {
                /*
                 * One payment for a party at a time. Two typed at once would
                 * otherwise both be checked against the same open amount on a
                 * file, and both adjusted against it.
                 */
                PartyModel::whereKey($req->party_id)->lockForUpdate()->first();

                // Checked here, under the lock, against what the ledger says —
                // never against what the page showed.
                // Its own rules first: they say what is wrong in the words
                // of a write-off rather than of an adjustment.
                if ($writeOff) {
                    self::checkWriteOffAgainstLedger((int) $req->party_id, $type, $lines, $cap);
                }

                self::checkAgainstLedger((int) $req->party_id, $type, $lines);

                $entry = new PartyLedgerModel;
                $entry->party_id = $req->party_id;
                $entry->txn_date = $req->txn_date;
                $entry->entry_type = $req->entry_type;
                $entry->amount = (float) $req->amount;
                /*
                 * A write-off moves no money, so it carries no mode; what it
                 * says is the server's word, and why is the office's own.
                 */
                $entry->payment_mode = $writeOff ? null : $req->payment_mode;
                $entry->ref_no = $req->ref_no;
                $entry->particular = $writeOff ? PartyLedgerModel::WRITEOFF_PARTICULAR : $req->particular;

                if ($writeOff) {
                    $entry->entry_kind = PartyLedgerModel::WRITEOFF;
                    $entry->note = trim((string) $req->input('reason'));
                }

                // Who typed it in, from the day it could be recorded.
                if (PartyLedgerModel::adjustable()) {
                    $entry->created_by = Auth::id();
                }

                $entry->save();

                // Its lines at its own moment, so they are known as written with it.
                self::allocate($entry->id, (int) $req->party_id, $lines, $entry->created_at);

                return $entry;
            });

            $saved = back()->with('success', ucfirst($entry->entry_type).' entry saved successfully. Transaction ID: '.$entry->id);

            /*
             * Money in from a customer: offer them a receipt on WhatsApp.
             * See customerMessage().
             */
            if ($type === 'customer' && $entry->entry_type === 'credit'
                && in_array($entry->payment_mode, PartyLedgerModel::MONEY_MODES, true)) {
                $saved->with('receipt', self::customerMessage(PartyModel::find($entry->party_id), $entry));
            }

            /*
             * A difference written off: the customer is told of it as of any
             * adjustment — their statement says Discount, and so does this.
             */
            if ($type === 'customer' && $writeOff) {
                $saved->with('receipt', self::customerMessage(PartyModel::find($entry->party_id), $entry, 'writeoff'));
            }

            return $saved;
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
            // The party a refused save or a Correct brings back stays offered,
            // inactive or not — or the entry could not be typed again for them.
            'parties' => PartyModel::selectList($type, old('party_id') ?: null)->map(fn ($p) => [
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
                'entry_kind' => (string) old('entry_kind'),
                'reason' => (string) old('reason'),
            ],

            /*
             * Adjusting a payment against files. Offered on the payment side
             * only — a customer's Credit, a vendor's Debit — and only once the
             * database has the table for it.
             */
            'adjustable' => PartyLedgerModel::adjustable(),
            'paymentSide' => $type === 'customer' ? 'credit' : 'debit',
            /*
             * Writing a difference off, on the customer's side only, and only
             * while the office's own limit says it may be.
             */
            'writeOffCap' => $type === 'customer' ? PartyLedgerModel::writeOffCap() : 0.0,
            'limitsUrl' => route('setting.index'),
            'billsUrl' => route('party.bills', ['id' => '__ID__']),
            // A refused save's amounts, file by file, to be put back.
            'initialAlloc' => self::oldAlloc('alloc'),

            /*
             * Setting a customer off against their own vendor account: each
             * party's other account, where the office has linked one, with
             * its balance. The office's own screen, so it is named here.
             */
            'settable' => PartyLedgerModel::canSetOff(),
            'counterparts' => PartyModel::counterparts($type),
            'initialCounterAlloc' => self::oldAlloc('counter_alloc'),
        ];

        return Screen::make('admin.party.entry', 'vue-party-entry', $props, [
            'type' => $type,
            'label' => $label,
            // Nothing can be entered against a side with no parties on it.
            'partyCount' => count($props['parties']),
            // The receipt for a payment just saved; see above.
            'receipt' => session('receipt'),
        ])->toResponse($req);
    }

    /** The side a party pays on: a customer's Credit, the office's Debit to a vendor. */
    private static function paymentSide(string $type): string
    {
        return $type === 'customer' ? 'credit' : 'debit';
    }

    /**
     * What a customer is offered on WhatsApp after their account changes: a
     * payment's receipt, or word of a set-off, a discount or a reversal.
     *
     * Carried to the next page in the session and shown there once. Only
     * pre-filled — the office presses Send. Built from the figures named here
     * and nothing else: never the particular, which is the office's own
     * description of the entry. A vendor is never sent one.
     *
     * @param  ?string  $kind  null for a payment's receipt, or setoff, writeoff, reversal
     * @param  array<string, mixed>  $with  what a kind adds
     * @return array<string, mixed>
     */
    private static function customerMessage(PartyModel $customer, PartyLedgerModel $entry, ?string $kind = null, array $with = []): array
    {
        return ($kind ? ['kind' => $kind] : []) + [
            'name' => $customer->name,
            // Their WhatsApp number when one is saved, as everywhere.
            'mobile' => (string) ($customer->whatsapp ?: $customer->mobile),
            'amount' => (float) $entry->amount,
            'dateLabel' => date('d-m-Y', strtotime($entry->txn_date)),
            // Only a payment has a mode worth saying.
            'mode' => $kind ? '' : (string) $entry->payment_mode,
            /*
             * The customer's own reference, for finding it in their bank. Typed
             * by hand, so read for vendors as their statement reads it. Found
             * after the vendor-side sweep: it went onto the WhatsApp as typed.
             */
            'reference' => (string) WorkFileModel::redactVendors($entry->ref_no, WorkFileModel::vendorMarksFor((int) $customer->id)),
            // Today's, after this entry — not as of the day it is dated,
            // which can be earlier.
            'balance' => round(PartyLedgerModel::currentBalance($customer->id), 2),
            // So the message can say so: an entry dated last month beside an
            // undated balance reads as last month's balance.
            'todayLabel' => now()->format('d-m-Y'),
            // The files it was adjusted against, as the customer knows them:
            // the vehicle and the work, never a vendor.
            'against' => PartyLedgerModel::againstFor([$entry->id])[$entry->id] ?? [],
        ] + $with;
    }

    /** A refused save's amounts under one key, file by file, to be put back. */
    private static function oldAlloc(string $key): object
    {
        return (object) collect((array) old($key, []))
            ->filter(fn ($line) => is_array($line) && ($line['amount'] ?? '') !== '')
            ->mapWithKeys(fn ($line) => [(int) ($line['work_file_id'] ?? 0) => (string) $line['amount']])
            ->all();
    }

    /**
     * A customer set off against their own vendor account.
     *
     * One person owes the office for files and is owed for work, and the one
     * is cleared against the other with no money moving: a credit on the
     * customer, as a receipt would be, and a debit on the vendor, as a payment
     * would be — the same amount, the same day, each naming the other. So
     * every balance, statement and file reads right with nothing else changed,
     * and what is not named against files settles the oldest on each side.
     *
     * Only between two accounts the office linked by hand, both active, and
     * never more than the customer owes or the office owes the vendor, read
     * under a lock on both — or one side would be left in advance for money
     * nobody paid. Typed from either account's Entry screen, on its payment
     * side; the two halves are the same whichever it was.
     */
    private function saveSetOff(Request $req, string $type, Collection $lines)
    {
        $counterLines = self::allocLines($req, 'counter_alloc');
        $amount = round((float) $req->amount, 2);

        if (! PartyLedgerModel::canSetOff()) {
            return back()->withInput()->withErrors([
                'entry_kind' => 'Setting off needs the database update that came with it (php artisan migrate).',
            ]);
        }

        if ($req->entry_type !== self::paymentSide($type)) {
            return back()->withInput()->withErrors(['entry_kind' => $type === 'customer'
                ? "A set-off is a Credit on a customer's account: it lowers what they owe."
                : "A set-off is a Debit on a vendor's account: it lowers what we owe them."]);
        }

        foreach (['alloc' => $lines, 'counter_alloc' => $counterLines] as $key => $these) {
            if ($refused = self::allocRefusal($these, $amount)) {
                return back()->withInput()->withErrors([$key => $refused]);
            }
        }

        [$customerHalf, $vendorHalf, $customer, $vendor] = DB::transaction(function () use ($req, $type, $lines, $counterLines, $amount) {
            /*
             * Both accounts, the lower id first, so a set-off and a payment
             * typed on either at the same moment never wait on each other the
             * wrong way round. What the page said is checked again under them.
             */
            $ids = [(int) $req->party_id, (int) $req->input('counterpart_id')];
            sort($ids);

            foreach ($ids as $id) {
                PartyModel::whereKey($id)->lockForUpdate()->first();
            }

            $party = PartyModel::findOrFail($req->party_id);
            $otherId = $party->counterpartId();

            if (! $otherId) {
                throw ValidationException::withMessages(['entry_kind' => $party->name.' is not linked to a '
                    .($type === 'customer' ? 'vendor' : 'customer')." account. Link the two on the customer's Edit screen first."]);
            }

            if ($otherId !== (int) $req->input('counterpart_id')) {
                throw ValidationException::withMessages(['entry_kind' => 'The account '.$party->name
                    .' is linked to changed since this page was opened. Nothing was saved; check it and save again.']);
            }

            $other = PartyModel::findOrFail($otherId);

            [$customer, $vendor] = $type === 'customer' ? [$party, $other] : [$other, $party];
            [$customerLines, $vendorLines] = $type === 'customer' ? [$lines, $counterLines] : [$counterLines, $lines];
            [$customerKey, $vendorKey] = $type === 'customer' ? ['alloc', 'counter_alloc'] : ['counter_alloc', 'alloc'];

            foreach ([$customer, $vendor] as $one) {
                if (! $one->is_active) {
                    throw ValidationException::withMessages(['entry_kind' => $one->name.' is inactive, and nothing new is set off against an inactive account.']);
                }
            }

            $owes = round(PartyLedgerModel::currentBalance($customer->id), 2);
            $owed = round(-PartyLedgerModel::currentBalance($vendor->id), 2);

            if ($amount > $owes + 0.005) {
                throw ValidationException::withMessages(['amount' => $owes > 0.005
                    ? $customer->name.' owes only '.number_format($owes, 2, '.', ',').' as a customer, so no more than that can be set off.'
                    : $customer->name.' owes nothing as a customer, so there is nothing to set off.']);
            }

            if ($amount > $owed + 0.005) {
                throw ValidationException::withMessages(['amount' => $owed > 0.005
                    ? 'We owe '.$vendor->name.' only '.number_format($owed, 2, '.', ',').' as a vendor, so no more than that can be set off.'
                    : 'We owe '.$vendor->name.' nothing as a vendor, so there is nothing to set off.']);
            }

            self::checkAgainstLedger((int) $customer->id, 'customer', $customerLines, null, [], $customerKey);
            self::checkAgainstLedger((int) $vendor->id, 'vendor', $vendorLines, null, [], $vendorKey);

            $note = trim((string) $req->input('reason'));

            $half = function (PartyModel $who, string $side, string $particular, ?int $partner) use ($req, $amount, $note) {
                $row = new PartyLedgerModel;
                $row->party_id = $who->id;
                $row->txn_date = $req->txn_date;
                $row->entry_type = $side;
                $row->amount = $amount;
                // Nothing changed hands, and what it says is the server's own.
                $row->payment_mode = PartyLedgerModel::SETOFF_MODE;
                $row->ref_no = null;
                $row->particular = $particular;
                $row->entry_kind = PartyLedgerModel::SETOFF;
                $row->note = $note !== '' ? $note : null;
                $row->setoff_with_id = $partner;
                $row->created_by = Auth::id();
                $row->save();

                return $row;
            };

            $customerHalf = $half($customer, 'credit', PartyLedgerModel::SETOFF_CUSTOMER_PARTICULAR, null);
            $vendorHalf = $half($vendor, 'debit', PartyLedgerModel::SETOFF_VENDOR_PARTICULAR, (int) $customerHalf->id);

            // Each names the other.
            $customerHalf->setoff_with_id = $vendorHalf->id;
            $customerHalf->save();

            // Each half's files under its own account, never the other's.
            self::allocate((int) $customerHalf->id, (int) $customer->id, $customerLines, $customerHalf->created_at);
            self::allocate((int) $vendorHalf->id, (int) $vendor->id, $vendorLines, $vendorHalf->created_at);

            return [$customerHalf, $vendorHalf, $customer, $vendor];
        });

        $money = number_format($amount, 2, '.', ',');

        return back()
            ->with('success', 'Set off '.$money.': '.$customer->name.' owes '.$money.' less as a customer, and is owed '
                .$money.' less as a vendor. Entry #'.$customerHalf->id.' on the customer, #'.$vendorHalf->id.' on the vendor.')
            /*
             * The customer is told, as they are of any adjustment: a message
             * to send on WhatsApp, never sent from here. Their half only —
             * the vendor half's files are other customers' vehicles.
             */
            ->with('receipt', self::customerMessage($customer, $customerHalf, 'setoff'));
    }

    /**
     * The files a payment is posted against, as lines.
     *
     * Rounded before anything is judged, so an amount too small to be a paisa
     * is no line at all rather than a line of 0.00; an empty box is no line.
     *
     * @return Collection<int, array{work_file_id: int, amount: float}>
     */
    private static function allocLines(Request $req, string $key = 'alloc'): Collection
    {
        return collect((array) $req->input($key, []))
            ->filter(fn ($line) => is_array($line))
            ->map(fn ($line) => [
                'work_file_id' => (int) ($line['work_file_id'] ?? 0),
                'amount' => round((float) ($line['amount'] ?? 0), 2),
            ])
            ->filter(fn ($line) => $line['amount'] > 0)
            ->values();
    }

    /** What is wrong with the lines on their own, before the ledger is read. */
    private static function allocRefusal(Collection $lines, float $amount): ?string
    {
        /*
         * Counted after the empty boxes are gone. Found in review: the screen
         * once posted a box for every file listed, and a limit on the raw count
         * refused every payment from a party with more than two hundred files —
         * even one adjusted against nothing.
         */
        if ($lines->count() > 200) {
            return 'A payment can be adjusted against at most 200 files at a time.';
        }

        if ($lines->pluck('work_file_id')->duplicates()->isNotEmpty()) {
            return 'A file can be adjusted against only once in one payment.';
        }

        $against = round($lines->sum('amount'), 2);

        if ($against > $amount + 0.005) {
            return 'The files come to '.number_format($against, 2, '.', ',')
                .', more than the payment of '.number_format($amount, 2, '.', ',').'.';
        }

        return null;
    }

    /**
     * What is wrong with a write-off before the ledger is read.
     *
     * A write-off is a difference given up, not a payment taken: it is the
     * customer's side only, it must say which bill it closes and cover the
     * whole of itself against bills, and it may not be larger than the figure
     * the office set for itself (Setup → Limits).
     */
    private static function writeOffRefusal(Request $req, string $type, Collection $lines, float $cap): ?string
    {
        if ($cap <= 0) {
            return PartyLedgerModel::reversible()
                ? 'Writing off is off until a limit above nought is set in Setup → Limits.'
                : 'Writing off needs the database update that came with it (php artisan migrate).';
        }

        if ($type !== 'customer') {
            return "A vendor's bill is not written off here. Correct what was agreed on the file instead.";
        }

        if ($req->entry_type !== self::paymentSide($type)) {
            return 'A write-off is a Credit on a customer\'s account: it lowers what they owe.';
        }

        $amount = round((float) $req->amount, 2);

        if ($amount > $cap + 0.005) {
            return 'At most '.number_format($cap, 2, '.', ',').' can be written off at one time. '
                .'Raise the limit in Setup → Limits, or correct the charge on the file instead.';
        }

        if ($lines->isEmpty()) {
            return 'Say which bill this is off. Left on account it would settle the oldest one instead.';
        }

        if (abs($lines->sum('amount') - $amount) > 0.005) {
            return 'The whole '.number_format($amount, 2, '.', ',').' has to be against bills; '
                .number_format($lines->sum('amount'), 2, '.', ',').' is.';
        }

        return null;
    }

    /**
     * A write-off's lines against the ledger, under the party's lock.
     *
     * Two rules of its own, beyond the ones every adjustment has. A bill can
     * only be forgiven what it is still owed — "open" counts money on account
     * as not yet spoken for, and forgiving that would be giving up what has
     * already been paid. And the office's limit holds per bill as well as per
     * entry, or a 5,000 debt goes in a hundred lots of 50.
     */
    private static function checkWriteOffAgainstLedger(int $partyId, string $type, Collection $lines, float $cap): void
    {
        $bills = PartyLedgerModel::bills($partyId, $type === 'customer' ? 'debit' : 'credit')['files'];
        $names = DB::table('work_file')->whereIn('id', $lines->pluck('work_file_id'))->pluck('file_no', 'id');

        // What has already been written off against each of these bills.
        $already = DB::table('party_ledger_allocation as a')
            ->join('party_ledger as e', 'e.id', '=', 'a.entry_id')
            ->where('e.entry_kind', PartyLedgerModel::WRITEOFF)
            ->whereNull('a.released_at')
            // This party's own: a file given to somebody else since carries
            // what was forgiven them, which is not this customer's allowance.
            ->where('a.party_id', $partyId)
            ->whereIn('a.work_file_id', $lines->pluck('work_file_id'))
            ->groupBy('a.work_file_id')
            ->selectRaw('a.work_file_id, SUM(a.amount) as given')
            ->pluck('given', 'work_file_id');

        $problems = [];

        foreach ($lines as $line) {
            $name = $names[$line['work_file_id']] ?? 'That file';
            $due = (float) ($bills[$line['work_file_id']]['due'] ?? 0);
            $off = (float) ($already[$line['work_file_id']] ?? 0);

            if ($line['amount'] > $due + 0.005) {
                $problems[] = $due > 0.005
                    ? $name.' is owed only '.number_format($due, 2, '.', ',').' now.'
                    : $name.' is not owed anything now, so there is nothing on it to write off.';
            } elseif ($off + $line['amount'] > $cap + 0.005) {
                $problems[] = $name.' has already had '.number_format($off, 2, '.', ',')
                    .' written off, and the limit is '.number_format($cap, 2, '.', ',').' a bill.';
            }
        }

        if ($problems) {
            throw ValidationException::withMessages(['alloc' => implode(' ', $problems)]);
        }
    }

    /**
     * Each line against what the ledger says is open on that file — never what
     * the page showed. Called under the party's lock.
     *
     * With $except, a saved payment being adjusted again: open is read with its
     * own adjustments left out and every other payment's kept. And what it is
     * adjusted against already ($keep, read under the lock) may stay as it is
     * or be lowered without being judged again. Found in review: judged again,
     * a line on a file cancelled or moved since — which the ledger keeps for
     * when the file is charged again — refused every change to the payment, or
     * was let go by a save that meant only to add another file. Keeping or
     * lowering a line takes nothing from any other payment that it does not
     * already take.
     *
     * @param  array<int, string>  $keep  file id => amount
     * @param  string  $key  the field the problems are said against: a set-off's other account has its own
     */
    private static function checkAgainstLedger(int $partyId, string $type, Collection $lines, ?int $except = null, array $keep = [], string $key = 'alloc'): void
    {
        $lines = $lines->reject(fn ($line) => isset($keep[$line['work_file_id']])
            && $line['amount'] <= (float) $keep[$line['work_file_id']] + 0.005);

        if ($lines->isEmpty()) {
            return;
        }

        $bills = PartyLedgerModel::bills($partyId, $type === 'customer' ? 'debit' : 'credit', $except)['files'];
        $names = DB::table('work_file')->whereIn('id', $lines->pluck('work_file_id'))->pluck('file_no', 'id');
        $problems = [];

        foreach ($lines as $line) {
            $bill = $bills[$line['work_file_id']] ?? null;
            $name = $names[$line['work_file_id']] ?? 'That file';

            if (! $bill || $bill['charged'] <= 0.005) {
                $problems[] = $name.' is not one of this '.strtolower(PartyModel::label($type))."'s files.";
            } elseif ($line['amount'] > max($bill['open'], (float) ($keep[$line['work_file_id']] ?? 0)) + 0.005) {
                $problems[] = $name.' has only '.number_format(max($bill['open'], (float) ($keep[$line['work_file_id']] ?? 0)), 2, '.', ',').' left to adjust.';
            }
        }

        if ($problems) {
            throw ValidationException::withMessages([$key => implode(' ', $problems)]);
        }
    }

    /**
     * The lines, written against the payment, all stamped with one moment —
     * the same one a re-adjustment lets the old lines go at, so the office
     * note can tell what was let go by that save.
     */
    private static function allocate(int $entryId, int $partyId, Collection $lines, $at = null): void
    {
        $at ??= now();

        foreach ($lines as $line) {
            DB::table('party_ledger_allocation')->insert([
                'entry_id' => $entryId,
                'party_id' => $partyId,
                'work_file_id' => $line['work_file_id'],
                'amount' => $line['amount'],
                'created_by' => Auth::id(),
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }
    }

    /**
     * Why a saved entry cannot be adjusted against files, or null when it can:
     * an ordinary payment typed on the Entry screen, standing.
     */
    private static function adjustRefusal(PartyLedgerModel $entry, string $type): ?string
    {
        if ($entry->work_file_id) {
            $fileNo = DB::table('work_file')->where('id', $entry->work_file_id)->value('file_no');

            return 'This entry comes from file '.$fileNo.' and belongs to it already.';
        }

        if ($entry->entry_type !== self::paymentSide($type)) {
            return 'Only a payment is adjusted against files — a '
                .(self::paymentSide($type) === 'credit' ? 'Credit' : 'Debit').' on a '.strtolower(PartyModel::label($type))."'s account.";
        }

        if (PartyLedgerModel::reversible()) {
            // A set-off's half is, as a receipt is: its files can change with
            // no money moving, and the other half is not touched.
            if ($entry->reverses_id || ($entry->entry_kind && $entry->entry_kind !== PartyLedgerModel::SETOFF)) {
                return 'Entry #'.$entry->id.' is not an ordinary payment, and is not adjusted against files.';
            }

            if (PartyLedgerModel::where('reverses_id', $entry->id)->exists()) {
                return 'Entry #'.$entry->id.' has been reversed, and settles nothing.';
            }
        }

        return null;
    }

    /**
     * What a payment is adjusted against now: file id => amount, to the paisa.
     *
     * @return array<int, string>
     */
    private static function liveLines(int $entryId): array
    {
        return DB::table('party_ledger_allocation')
            ->where('entry_id', $entryId)
            ->whereNull('released_at')
            ->get(['work_file_id', 'amount'])
            ->mapWithKeys(fn ($line) => [(int) $line->work_file_id => number_format((float) $line->amount, 2, '.', '')])
            ->sortKeys()
            ->all();
    }

    /**
     * Posted lines in the same form, so the two compare as sets.
     *
     * @return array<int, string>
     */
    private static function plainLines(Collection $lines): array
    {
        return $lines
            ->mapWithKeys(fn ($line) => [$line['work_file_id'] => number_format($line['amount'], 2, '.', '')])
            ->sortKeys()
            ->all();
    }

    /** A fingerprint of a payment's lines, for telling whether they moved under a page. */
    private static function linesPrint(array $lines): string
    {
        return sha1(json_encode($lines));
    }

    /**
     * Lines as the office reads them: "F-00050 BR01AB1234 6,000.00 · …".
     *
     * By file number, which is what the office works by, and for every line —
     * a file cancelled or moved since included, which againstFor() leaves out
     * because the customer is not told of it.
     *
     * @param  array<int, string|float>  $lines  file id => amount
     * @param  Collection|null  $files  the files, when already read
     */
    private static function linesText(array $lines, ?Collection $files = null): string
    {
        $files ??= DB::table('work_file')->whereIn('id', array_keys($lines))->get(['id', 'file_no', 'registration_no'])->keyBy('id');

        return implode(' · ', array_map(function ($fileId) use ($lines, $files) {
            $file = $files[$fileId] ?? null;

            return trim(($file->file_no ?? 'File #'.$fileId).' '.($file->registration_no ?? ''))
                .' '.number_format((float) $lines[$fileId], 2, '.', ',');
        }, array_keys($lines)));
    }

    /**
     * When the files a payment is for were last changed, by whom, and what it
     * was for before — for the office, beside the entry on its statement.
     *
     * A change is a line let go other than by a reversal, or a line written
     * after the payment itself was. Nothing is said of a payment adjusted when
     * it was typed and never since.
     *
     * @param  array<int, int>  $entryIds  standing entries only; a reversal
     *     lets go of lines too, and says so itself
     * @return array<int, string>  entry id => "Files changed 21-09-2026 by Ravi — was F-00050 6,000.00"
     */
    private static function adjustHistory(array $entryIds): array
    {
        if (! $entryIds || ! PartyLedgerModel::adjustable()) {
            return [];
        }

        $rows = DB::table('party_ledger_allocation as a')
            ->join('party_ledger as e', 'e.id', '=', 'a.entry_id')
            ->whereIn('a.entry_id', $entryIds)
            ->orderBy('a.id')
            ->get(['a.entry_id', 'a.work_file_id', 'a.amount', 'a.created_at', 'a.created_by', 'a.released_at', 'a.released_by', 'e.created_at as entry_created']);

        $events = [];

        foreach ($rows as $row) {
            if ($row->released_at) {
                $events[$row->entry_id][] = ['at' => (string) $row->released_at, 'by' => $row->released_by];
            }

            /*
             * Written with the payment, or later. A payment's own lines carry
             * its moment exactly; the two seconds' grace is for those written
             * before they did, a call apart. Found in review: a minute's grace
             * missed a payment typed on account and put on a file straight
             * after. A payment older than the table was never adjusted when it
             * was typed.
             */
            $entryCreated = $row->entry_created ? strtotime((string) $row->entry_created) : null;

            if ($row->created_at && ($entryCreated === null || strtotime((string) $row->created_at) > $entryCreated + 2)) {
                $events[$row->entry_id][] = ['at' => (string) $row->created_at, 'by' => $row->created_by];
            }
        }

        if (! $events) {
            return [];
        }

        $latest = array_map(function ($list) {
            usort($list, fn ($a, $b) => strcmp($b['at'], $a['at']));

            return $list[0];
        }, $events);

        $names = DB::table('users')->whereIn('id', array_filter(array_column($latest, 'by')))->pluck('name', 'id');

        // Every file any of them was on, read once for the whole statement.
        $files = DB::table('work_file')
            ->whereIn('id', $rows->whereNotNull('released_at')->pluck('work_file_id')->unique()->all())
            ->get(['id', 'file_no', 'registration_no'])
            ->keyBy('id');

        $out = [];

        foreach ($latest as $entryId => $event) {
            // What was let go at that moment is what it was for before.
            $was = [];

            foreach ($rows as $row) {
                if ((int) $row->entry_id === (int) $entryId && (string) $row->released_at === $event['at']) {
                    $was[(int) $row->work_file_id] = $row->amount;
                }
            }

            $who = $event['by'] && isset($names[$event['by']]) ? ' by '.$names[$event['by']] : '';

            $out[(int) $entryId] = 'Files changed '.date('d-m-Y', strtotime($event['at'])).$who
                .' — was '.($was ? self::linesText($was, $files) : 'on account');
        }

        return $out;
    }

    /**
     * What the Adjust screen says about one of a payment's own lines, or null
     * when there is nothing to say: its file is open to it and the line
     * settles all of itself.
     *
     * Found over two reviews: said from the view with the payment left out,
     * a file whose charge was given back was said to be kept, and a file
     * re-priced below an older payment's line was said to be held by "other
     * payments" when that payment settled all of it and the others nothing.
     * What the line settles, and what the others do, are read from the ledger
     * as it is; the view with the payment left out says only whether the file
     * is still open to it.
     *
     * @param  array<string, float>|null  $view  the file with this payment left out
     * @param  array<string, float>|null  $real  the file in the ledger as it is
     * @param  float  $took  what this line settles now
     */
    private static function keptWhy(?array $view, ?array $real, float $amount, float $took, string $type): ?string
    {
        if (! $view || $view['charged'] <= 0.005) {
            return $type === 'vendor'
                ? "No longer this vendor's — cancelled, or given to another vendor. Kept for when it is theirs again; until then it counts as on account."
                : 'Not charged to this customer now — cancelled, or given to another customer. Kept for when it is charged again; until then it counts as on account.';
        }

        $settlesAll = $took >= $amount - 0.005;

        if ($settlesAll && $view['open'] > 0.005) {
            return null;
        }

        $money = fn ($value) => number_format($value, 2, '.', ',');
        $charged = ($real['charged'] ?? $view['charged']) - ($real['returned'] ?? $view['returned']);
        $others = ($real['adjusted'] ?? 0) - $took;

        $cause = match (true) {
            $charged <= 0.005 => 'Its charge was given back.',
            $others > 0.005 => $took > 0.005 ? 'Other payments are adjusted against the rest of it.' : 'Other payments are adjusted against it.',
            $took >= $charged - 0.005 => 'It is charged only '.$money($charged).' now.',
            default => 'Nothing more is open on it.',
        };

        if ($settlesAll) {
            return $cause.' This payment keeps its '.$money($amount).' on it.';
        }

        return $took <= 0.005
            ? $cause.' This payment\'s '.$money($amount).' settles nothing on it now, and counts as on account.'
            : $cause.' Only '.$money($took).' of this payment\'s '.$money($amount).' settles it now; the rest counts as on account.';
    }

    /**
     * The period a statement was filtered to, carried to the Adjust screen and
     * back so every way back lands where the office was. Only well-formed
     * dates, and quietly dropped otherwise: a leftover in a link is no reason
     * to refuse the page.
     *
     * @return array{from?: string, to?: string}
     */
    private static function period(Request $req): array
    {
        $out = [];

        foreach (['from', 'to'] as $key) {
            // from[]=… is not a date either, and is no reason for an error page.
            $value = $req->query($key);

            if (! is_string($value)) {
                continue;
            }

            $date = \DateTime::createFromFormat('!Y-m-d', $value);

            if ($date && $date->format('Y-m-d') === $value) {
                $out[$key] = $value;
            }
        }

        return isset($out['from'], $out['to']) && $out['from'] > $out['to'] ? [] : $out;
    }

    /**
     * Set or change the files a payment already saved is for.
     *
     * A payment typed before payments could be adjusted, or adjusted against
     * the wrong file, is put right here. No money moves: the entry stays as it
     * is and only what it is adjusted against changes. What it was adjusted
     * against before is released rather than deleted, with who and when, so
     * the record of it stays, and the statement says so; what it is adjusted
     * against now is written anew.
     *
     * Checked as the Entry screen checks a new payment, against the files as
     * they stood when it came in (PartyLedgerModel::bills()) — except that what
     * it is adjusted against already may stay as it is or be lowered.
     */
    public function adjust(Request $req, $id)
    {
        abort_unless(PartyLedgerModel::adjustable(), 404);

        $entry = PartyLedgerModel::findOrFail($id);
        $party = PartyModel::findOrFail($entry->party_id);
        $type = $party->party_type;

        $period = self::period($req);
        $statementUrl = route('party.statement', ['id' => $party->id] + $period);
        $selfUrl = route('party.adjust', ['id' => $entry->id] + $period);

        if ($req->isMethod('POST')) {
            $req->validate([
                'alloc' => 'nullable|array',
                'alloc.*.work_file_id' => 'required|integer',
                'alloc.*.amount' => 'nullable|numeric|min:0|max:99999999',
                'drawn' => 'nullable|string|max:64',
            ], [
                'alloc.*.amount.numeric' => 'An amount against a file must be a number.',
            ]);

            $lines = self::allocLines($req);

            if ($refused = self::allocRefusal($lines, (float) $entry->amount)) {
                return back()->withInput()->withErrors(['alloc' => $refused]);
            }

            [$outcome, $posted] = DB::transaction(function () use ($req, $entry, $type, $lines) {
                // In the order reverse() takes them, so the two never wait on
                // each other the wrong way round.
                $entry = PartyLedgerModel::whereKey($entry->id)->lockForUpdate()->firstOrFail();
                PartyModel::whereKey($entry->party_id)->lockForUpdate()->first();

                if ($refused = self::adjustRefusal($entry, $type)) {
                    throw ValidationException::withMessages(['alloc' => $refused]);
                }

                $live = self::liveLines($entry->id);
                $posted = self::plainLines($lines);

                // Already so — pressed twice, or a colleague made the same change.
                if ($posted === $live) {
                    return ['unchanged', $posted];
                }

                /*
                 * Changed since this page was drawn. Found in review: a page
                 * left open, saved, quietly let go of what a colleague had
                 * just put the payment against — the set posted replaces the
                 * set there is, and the page had never shown it.
                 */
                if (! hash_equals(self::linesPrint($live), (string) $req->input('drawn'))) {
                    return ['stale', $posted];
                }

                self::checkAgainstLedger((int) $entry->party_id, $type, $lines, (int) $entry->id, $live);

                /*
                 * One moment for the whole save. Found in review: stamped a
                 * call at a time, a save that crossed a second let its old
                 * lines go at one second and wrote the new ones at the next,
                 * and the office note read "was on account" for a payment
                 * that had been on a file.
                 */
                $at = now();

                DB::table('party_ledger_allocation')
                    ->where('entry_id', $entry->id)
                    ->whereNull('released_at')
                    ->update(['released_at' => $at, 'released_by' => Auth::id(), 'updated_at' => $at]);

                self::allocate((int) $entry->id, (int) $entry->party_id, $lines, $at);

                return ['saved', $posted];
            });

            $now = self::liveLines($entry->id);
            $nowText = $now ? 'adjusted against '.self::linesText($now) : 'on account, settling the oldest files first';

            if ($outcome === 'stale') {
                return redirect($selfUrl)->withErrors(['alloc' => 'Someone changed what entry #'.$entry->id
                    .' is adjusted against since this page was opened. It is now '.$nowText.'. Nothing was saved'
                    .($posted ? ' — you had '.self::linesText($posted) : '').'. Make the change again below if it is still wanted.']);
            }

            if ($outcome === 'unchanged') {
                return redirect($statementUrl)->with('success', 'Nothing changed: entry #'.$entry->id.' is '.$nowText.'.');
            }

            return redirect($statementUrl)->with('success', 'Entry #'.$entry->id.' is now '.$nowText.'.');
        }

        if ($refused = self::adjustRefusal($entry, $type)) {
            return redirect($statementUrl)->withErrors(['alloc' => $refused]);
        }

        $live = self::liveLines($entry->id);
        $label = PartyModel::label($type);

        /*
         * Each line it has now, and whether the list the screen fetches will
         * carry its file. One that will not — the file cancelled or moved since,
         * or all of it taken by other payments' adjustments — is still drawn,
         * from here, to be kept, lowered or let go by the office and never by
         * the screen on its own.
         */
        $side = $type === 'customer' ? 'debit' : 'credit';
        $bills = PartyLedgerModel::bills($party->id, $side, $entry->id)['files'];
        $files = DB::table('work_file')->whereIn('id', array_keys($live))->get(['id', 'file_no', 'registration_no'])->keyBy('id');

        /*
         * What each of its lines settles now. Found in review: a line on a file
         * whose charge was given back was said to keep what it had, when it
         * settled nothing and its money was on account.
         */
        $ledger = PartyLedgerModel::bills($party->id, $side);
        $took = $ledger['took'][$entry->id] ?? [];

        $currentLines = collect($live)->map(function ($amount, $fileId) use ($bills, $ledger, $files, $type, $took) {
            return [
                'id' => (int) $fileId,
                'fileNo' => (string) ($files[$fileId]->file_no ?? 'File #'.$fileId),
                'vehicle' => (string) ($files[$fileId]->registration_no ?? ''),
                'amount' => (float) $amount,
                // What it settles now: Full goes no further, and the page says
                // what of it counts as on account.
                'settles' => (float) ($took[$fileId] ?? 0),
                'why' => self::keptWhy($bills[$fileId] ?? null, $ledger['files'][$fileId] ?? null, (float) $amount, (float) ($took[$fileId] ?? 0), $type),
            ];
        })->values()->all();

        // A refused save's amounts come back rather than what is saved.
        $refusedAlloc = $req->session()->hasOldInput()
            ? collect((array) old('alloc', []))
                ->filter(fn ($line) => is_array($line) && ($line['amount'] ?? '') !== '')
                ->mapWithKeys(fn ($line) => [(int) ($line['work_file_id'] ?? 0) => (string) $line['amount']])
                ->all()
            : null;

        $props = [
            'action' => $selfUrl,
            'csrf' => csrf_token(),
            'label' => $label,
            'statementUrl' => $statementUrl,
            'billsUrl' => route('party.bills', ['id' => '__ID__']),
            'party' => ['id' => (int) $party->id, 'name' => $party->name, 'mobile' => (string) $party->mobile],
            'entry' => [
                'id' => (int) $entry->id,
                'date' => date('d-m-Y', strtotime($entry->txn_date)),
                'side' => $entry->entry_type === 'debit' ? 'Dr' : 'Cr',
                'amount' => (float) $entry->amount,
                'mode' => (string) $entry->payment_mode,
                'reference' => (string) $entry->ref_no,
                'particular' => (string) $entry->particular,
            ],
            'current' => (object) $live,
            'currentLines' => $currentLines,
            'initialAlloc' => (object) ($refusedAlloc ?? $live),
            // What the page was drawn from; a save is refused if it has moved.
            // A refused save keeps the one its page had, or it would pass.
            'drawn' => (string) old('drawn', self::linesPrint($live)),
            'history' => self::adjustHistory([$entry->id])[$entry->id] ?? null,
        ];

        return Screen::make('admin.party.adjust', 'vue-party-adjust', $props, [
            'type' => $type,
            'label' => $label,
            'partyName' => $party->name,
            'entryId' => (int) $entry->id,
            'statementUrl' => $statementUrl,
        ])->toResponse($req);
    }

    /**
     * A party's files that a payment can still be adjusted against.
     *
     * For the Entry screen, fetched when a party is picked. What each file was
     * charged, what came back on it, what payments are already adjusted
     * against it, and what is open — the most a new payment can take. Oldest
     * first, the order a payment nobody adjusts would settle them in.
     *
     * A vendor sees only their own share of a folder split between vendors,
     * and only the works they were given.
     */
    public function bills(Request $req, $id)
    {
        $party = PartyModel::findOrFail($id);

        if (! PartyLedgerModel::adjustable()) {
            return response()->json(['bills' => [], 'covered' => 0]);
        }

        $isCustomer = $party->party_type === 'customer';

        /*
         * A payment already saved, being adjusted again: the files as if it had
         * never been typed. Only one of this party's own — any other id is
         * ignored, and the list is the plain one.
         */
        $except = $req->filled('except')
            ? PartyLedgerModel::whereKey((int) $req->query('except'))->where('party_id', $party->id)->value('id')
            : null;

        $ledger = PartyLedgerModel::bills($party->id, $isCustomer ? 'debit' : 'credit', $except ? (int) $except : null);
        $settled = $ledger['files'];

        /*
         * What is still owed, unless asked for more. Found in review: offered
         * every file with anything not adjusted against it, a dealer's list was
         * every file they had ever been charged for — years of it, paid long
         * ago by money nobody adjusted. Those are "covered" and offered only
         * when asked, for moving money already on account onto one file.
         */
        $all = $req->boolean('all');
        $covered = count(array_filter($settled, fn ($bill) => $bill['open'] > 0.005 && $bill['due'] <= 0.005));

        $open = array_filter($settled, fn ($bill) => $all ? $bill['open'] > 0.005 : $bill['due'] > 0.005);

        // In the order unadjusted money reaches them — the ledger's, by the
        // day each was charged — so "fill oldest first" does what it says.
        uasort($open, fn ($a, $b) => $a['seq'] <=> $b['seq']);

        $files = WorkFileModel::with('items.workType')
            ->whereIn('id', array_keys($open))
            ->get()
            ->keyBy('id');

        $ordered = collect(array_keys($open))->map(fn ($fileId) => $files[$fileId] ?? null)->filter();

        return response()->json(['covered' => $covered, 'bills' => $ordered->map(function ($file) use ($open, $isCustomer, $party, $ledger) {
            // Never a cancelled work; for a vendor, only the works they were given.
            $works = $file->worksFor($isCustomer ? null : (int) $party->id);

            /*
             * What is still owed on bills belonging to no file that the queue
             * reaches before this one. Found in review: without it, Fill oldest
             * first put the payment on the file and left an older bill typed
             * into the ledger unpaid — not what the payment does if nobody says.
             */
            $seq = $open[$file->id]['seq'];
            $ahead = round(array_sum(array_map(
                fn ($loose) => $loose['seq'] < $seq ? $loose['due'] : 0,
                $ledger['loose']
            )), 2);

            return [
                'id' => (int) $file->id,
                'ahead' => $ahead,
                'fileNo' => (string) $file->file_no,
                'vehicle' => (string) $file->registration_no,
                'works' => $works,
                'received' => date('d-m-Y', strtotime($file->received_date)),
                'charged' => $open[$file->id]['charged'],
                'returned' => $open[$file->id]['returned'],
                'adjusted' => $open[$file->id]['adjusted'],
                'open' => $open[$file->id]['open'],
                'due' => $open[$file->id]['due'],
                'editUrl' => route('workfile.edit', $file->id),
            ];
        })->values()]);
    }

    /**
     * Take back an entry typed by mistake.
     *
     * Not by deleting or editing it — a statement already sent says it — but
     * with a reversal: a new row on the other side for the same amount, dated
     * today, saying which entry it takes back. Balances net to what they would
     * have been, a statement already sent still says what it said, and the
     * record of both stays. Why is required and kept for the office; the
     * customer sees only that the entry was reversed.
     *
     * Only an entry typed on the Entry screen, once, and never a reversal
     * itself. A file's own rows are rewritten every time the file is saved, so
     * a reversal of one would be undone by the next save: those are put right
     * on the file.
     *
     * "Correct" is the same, and then the Entry screen with the entry filled
     * in — files it was adjusted against included — to be typed again as it
     * should have been.
     */
    public function reverse(Request $req, $id)
    {
        abort_unless(PartyLedgerModel::reversible(), 404);

        $req->validate([
            'reason' => 'required|string|max:255',
            'correct' => 'nullable|boolean',
        ], [
            'reason.required' => 'Say why this entry is being taken back — it is kept for the office.',
        ]);

        /*
         * A set-off is two halves on two accounts, and goes back as two or not
         * at all: one alone would leave the customer owing again while the
         * office still owed the vendor nothing, or the other way round. Its
         * partner is fixed when it is made, so it is read here, before the
         * transaction — and inside it both are locked, halves then accounts,
         * each the lower id first, the order adjust() and a set-off take
         * theirs in.
         *
         * Not read inside it. Found in review: a plain read first in the
         * transaction fixes what every later read sees at that moment, before
         * the locks were waited for — so a reversal a colleague had just
         * committed went unseen, and the second one was a 500 on the unique
         * index rather than "already reversed"; Correct refilled the files as
         * they were before a colleague's change, and saving undid it.
         */
        $first = PartyLedgerModel::findOrFail($id);
        $partnerId = PartyLedgerModel::canSetOff() && $first->entry_kind === PartyLedgerModel::SETOFF
            ? (int) $first->setoff_with_id
            : 0;

        [$entry, $lines, $partner, $partnerLines] = DB::transaction(function () use ($req, $first, $partnerId) {
            $ids = array_values(array_filter([(int) $first->id, $partnerId]));
            sort($ids);
            $locked = [];

            foreach ($ids as $one) {
                $locked[$one] = PartyLedgerModel::whereKey($one)->lockForUpdate()->first();
            }

            $entry = $locked[(int) $first->id];
            $partner = $partnerId ? ($locked[$partnerId] ?? null) : null;

            $parties = array_unique(array_filter([(int) $entry->party_id, (int) $partner?->party_id]));
            sort($parties);

            foreach ($parties as $one) {
                PartyModel::whereKey($one)->lockForUpdate()->first();
            }

            if ($entry->work_file_id) {
                $fileNo = DB::table('work_file')->where('id', $entry->work_file_id)->value('file_no');

                throw ValidationException::withMessages([
                    'reason' => 'This entry comes from file '.$fileNo.' and is rewritten whenever the file is saved. Correct it on the file.',
                ]);
            }

            if ($entry->reverses_id || $entry->entry_kind === PartyLedgerModel::REVERSAL) {
                throw ValidationException::withMessages([
                    'reason' => 'A reversal cannot itself be reversed. Enter the entry again instead.',
                ]);
            }

            if (PartyLedgerModel::where('reverses_id', $entry->id)->exists()) {
                throw ValidationException::withMessages(['reason' => 'Entry #'.$entry->id.' has already been reversed.']);
            }

            if ($partnerId) {
                // Nothing on a screen can part them; a hand in the database
                // might, and then it is put right by hand, not guessed at here.
                if (! $partner || $partner->entry_kind !== PartyLedgerModel::SETOFF || (int) $partner->setoff_with_id !== (int) $entry->id) {
                    throw ValidationException::withMessages(['reason' => 'The other half of this set-off, entry #'.$partnerId
                        .', is missing or no longer names it back, so the two cannot be reversed together. files:audit names it; it is put right by hand.']);
                }

                if (PartyLedgerModel::where('reverses_id', $partner->id)->exists()) {
                    throw ValidationException::withMessages(['reason' => 'Entry #'.$partner->id
                        .', the other half of this set-off, has already been reversed on its own. files:audit names it; it is put right by hand.']);
                }

                /*
                 * Entered again, it is a set-off again — between the same two
                 * accounts, still linked and both active, or it could not be
                 * saved. Refused before anything is reversed, so Correct never
                 * leaves the office with a reversal and nothing to type.
                 */
                if ($req->boolean('correct')) {
                    [$customerId, $vendorId] = PartyModel::whereKey($entry->party_id)->value('party_type') === 'customer'
                        ? [(int) $entry->party_id, (int) $partner->party_id]
                        : [(int) $partner->party_id, (int) $entry->party_id];

                    $customer = PartyModel::find($customerId);
                    $vendor = PartyModel::find($vendorId);

                    if ((int) $customer?->linked_vendor_id !== $vendorId || ! $customer->is_active || ! $vendor?->is_active) {
                        throw ValidationException::withMessages(['reason' => 'These two accounts are no longer linked, or one is inactive, '
                            .'so this cannot be entered again as a set-off. Reverse it instead.']);
                    }
                }
            }

            $halves = array_filter([$entry, $partner]);

            // What they were adjusted against, released: a reversed payment
            // settles nothing, and the lines go with it to be typed again.
            // One moment for the whole save, both halves.
            $at = now();
            $released = [];

            foreach ($halves as $half) {
                $released[$half->id] = DB::table('party_ledger_allocation')
                    ->where('entry_id', $half->id)
                    ->whereNull('released_at')
                    ->orderBy('id')
                    ->get(['work_file_id', 'amount']);

                DB::table('party_ledger_allocation')
                    ->where('entry_id', $half->id)
                    ->whereNull('released_at')
                    ->update(['released_at' => $at, 'released_by' => Auth::id(), 'updated_at' => $at]);
            }

            /*
             * Today, so a statement already sent is not changed after the fact.
             * Never before the entry it takes back, though. Found in review: a
             * post-dated entry reversed today put the reversal first, and every
             * period statement until that date showed money owed that was not.
             * Nothing dated ahead can have been sent yet, so the later date
             * keeps the rule's reason. One date for both halves of a set-off,
             * so the two reversals are a pair as well.
             */
            $dated = max(array_merge(
                [now()->toDateString()],
                array_map(fn ($half) => date('Y-m-d', strtotime($half->txn_date)), $halves)
            ));

            foreach ($halves as $half) {
                $reversal = new PartyLedgerModel;
                $reversal->party_id = $half->party_id;
                $reversal->txn_date = $dated;
                $reversal->entry_type = $half->entry_type === 'debit' ? 'credit' : 'debit';
                $reversal->amount = $half->amount;
                $reversal->payment_mode = PartyLedgerModel::REVERSAL_MODE;
                $reversal->ref_no = $half->ref_no;
                // What the customer reads: which entry, by its date and amount.
                $reversal->particular = 'Reversal of entry #'.$half->id.' of '.date('d-m-Y', strtotime($half->txn_date));
                $reversal->entry_kind = PartyLedgerModel::REVERSAL;
                $reversal->note = trim($req->reason);
                $reversal->reverses_id = $half->id;
                $reversal->created_by = Auth::id();
                $reversal->save();
            }

            return [$entry, $released[$entry->id], $partner, $partner ? $released[$partner->id] : collect()];
        });

        $type = PartyModel::whereKey($entry->party_id)->value('party_type');

        /*
         * The customer is told their balance moved, as they are of any
         * adjustment: the half that was on a customer's account, whichever
         * statement it was taken back from. Offered where the office lands —
         * the statement, or the Entry screen on Correct — and only pre-filled.
         * A vendor is never sent one.
         */
        $customerHalf = collect([$entry, $partner])->filter()
            ->first(fn ($half) => PartyModel::whereKey($half->party_id)->value('party_type') === 'customer');

        $told = fn ($response) => $customerHalf
            ? $response->with('receipt', self::customerMessage(
                PartyModel::find($customerHalf->party_id), $customerHalf, 'reversal', ['entryNo' => (int) $customerHalf->id]
            ))
            : $response;

        $asInput = fn ($lines) => $lines->mapWithKeys(fn ($line) => [(int) $line->work_file_id => [
            'work_file_id' => (int) $line->work_file_id,
            'amount' => (string) (float) $line->amount,
        ]])->all();

        if ($req->boolean('correct')) {
            /*
             * A set-off comes back as one, from whichever account it was
             * changed on: ticked, against the same other account, each side's
             * files where they were. Its words are the server's and are left
             * behind, as a write-off's are.
             */
            $setOff = $partner !== null;

            return $told(redirect()->route('party.entry', $type))
                ->withInput([
                    'party_id' => (string) $entry->party_id,
                    'entry_type' => $entry->entry_type,
                    'txn_date' => date('Y-m-d', strtotime($entry->txn_date)),
                    'amount' => (string) (float) $entry->amount,
                    'payment_mode' => $setOff ? '' : (string) $entry->payment_mode,
                    'ref_no' => (string) $entry->ref_no,
                    /*
                     * A write-off comes back as one. Found in review: only the
                     * money fields were carried, so the tick came back off with
                     * "Discount" typed in Particulars — and saving it wrote an
                     * ordinary credit that read as a discount to the customer,
                     * under no limit and in no report. Its own label is left
                     * behind with it: the server writes that word, nobody types it.
                     */
                    'particular' => in_array($entry->entry_kind, [PartyLedgerModel::WRITEOFF, PartyLedgerModel::SETOFF], true) ? '' : (string) $entry->particular,
                    'entry_kind' => (string) $entry->entry_kind,
                    'reason' => (string) $entry->note,
                    'counterpart_id' => $setOff ? (string) $partner->party_id : '',
                    'alloc' => $asInput($lines),
                    'counter_alloc' => $asInput($partnerLines),
                ])
                ->with('success', $setOff
                    ? 'Set-off entries #'.$entry->id.' and #'.$partner->id.' have been reversed, on both accounts. Enter it again correctly below.'
                    : 'Entry #'.$entry->id.' has been reversed. Enter it again correctly below.');
        }

        // Said as it is: today, unless the entry was dated ahead of today.
        $dated = PartyLedgerModel::where('reverses_id', $entry->id)->value('txn_date');
        $when = date('Y-m-d', strtotime($dated)) === now()->toDateString() ? 'today' : date('d-m-Y', strtotime($dated));

        if ($partner) {
            return $told(back())->with('success', 'Set-off entries #'.$entry->id.' and #'.$partner->id.' have been reversed, on both accounts. '
                .'The reversals are dated '.$when.'.');
        }

        return $told(back())->with('success', 'Entry #'.$entry->id.' has been reversed. The reversal is dated '.$when.'.');
    }

    /**
     * What a statement row says about taking it back: whether it can be (a
     * manual entry, not a reversal, not already reversed), and, for the
     * office only, what happened to it.
     *
     * And whether it can be adjusted against files: a payment, standing, typed
     * by hand. The Change dialog offers that when adjust_url is set.
     *
     * And, for a half of a set-off, which entry is its other half: the dialog
     * says the two go back together.
     *
     * @return array{change: ?string, row_state: ?string, office_note: ?string, adjust_url: ?string, setoff_with: ?int}
     */
    private static function changeFields($entry, $reversedBy, bool $reversible, string $paymentSide, array $history = [], array $period = []): array
    {
        if (! $reversible) {
            return ['change' => null, 'row_state' => null, 'office_note' => null, 'adjust_url' => null, 'setoff_with' => null];
        }

        $reversal = $reversedBy[$entry->id] ?? null;

        /*
         * The other half of a set-off, for the office only: on the vendor's
         * account for a customer's statement, and the other way round. By its
         * number and not its name — the note is the office's, but the page
         * is a customer's.
         */
        $partner = $entry->entry_kind === PartyLedgerModel::SETOFF && $entry->setoff_with_id ? (int) $entry->setoff_with_id : null;
        $partnerSays = $partner
            ? ($paymentSide === 'credit' ? 'vendor' : 'customer').' entry #'.$partner
            : null;

        if ($reversal) {
            return [
                'change' => null,
                'row_state' => 'is-reversed',
                'office_note' => 'Reversed by #'.$reversal->id.' on '.date('d-m-Y', strtotime($reversal->txn_date))
                    .($partnerSays ? ', with its other half, '.$partnerSays : ''),
                'adjust_url' => null,
                'setoff_with' => null,
            ];
        }

        if ($entry->reverses_id) {
            return [
                'change' => null,
                'row_state' => 'is-reversal',
                'office_note' => $entry->note ? 'Why: '.$entry->note : null,
                'adjust_url' => null,
                'setoff_with' => null,
            ];
        }

        // Typed by hand: an ordinary payment, or a set-off's half, whose files
        // can change as a receipt's can.
        $byHand = ! $entry->work_file_id && (! $entry->entry_kind || $partner);

        $note = match (true) {
            // Why a difference was given up. The customer's own statement
            // does not say.
            $entry->entry_kind === PartyLedgerModel::WRITEOFF && (bool) $entry->note => 'Why: '.$entry->note,
            (bool) $partner => implode(' · ', array_filter([
                'Set off against '.$partnerSays,
                $entry->note ? 'Remark: '.$entry->note : null,
                $history[$entry->id] ?? null,
            ])),
            // When its files were last changed.
            default => $history[$entry->id] ?? null,
        };

        return [
            'change' => $entry->work_file_id ? null : 'Change',
            'row_state' => null,
            'office_note' => $note,
            'adjust_url' => $byHand && $entry->entry_type === $paymentSide
                ? route('party.adjust', ['id' => $entry->id] + $period)
                : null,
            'setoff_with' => $partner,
        ];
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

        // The note on the file each entry came from, for the whole page at once.
        $remarks = PartyLedgerModel::fileRemarks($data['getRecords']);

        // And the files each payment was adjusted against, the same way.
        $against = PartyLedgerModel::againstFor($data['getRecords']->pluck('id')->all());

        /*
         * Which entries have been taken back, and by which reversal — across
         * the whole account, since a reversal is dated the day it was made and
         * may fall outside the period on screen.
         */
        $reversible = PartyLedgerModel::reversible();
        $reversedBy = $reversible
            ? DB::table('party_ledger')->where('party_id', $party->id)->whereNotNull('reverses_id')
                ->get(['id', 'reverses_id', 'txn_date'])->keyBy('reverses_id')
            : collect();

        // When each standing payment's files were last changed, all at once.
        $history = $reversible
            ? self::adjustHistory($data['getRecords']->pluck('id')->reject(fn ($id) => isset($reversedBy[$id]))->values()->all())
            : [];

        // Carried to the Adjust screen, so its way back keeps the period.
        $period = array_filter(['from' => $from, 'to' => $to]);

        /*
         * A customer's statement is the page most often printed, exported and
         * sent to them, so what was typed on it is read for vendors here too.
         *
         * And a vendor's for customers, the other way round: it is printed and
         * sent to the vendor, and a vendor is never told a customer's name.
         * Found in the vendor-side sweep: it was "left as typed", and what the
         * office types on a vendor's payment — "paid for Rakesh ji's TR" — and
         * the file details on older lines went out word for word. A vendor's
         * own name stays: the statement is theirs.
         */
        $forCustomer = $party->party_type === 'customer';

        if ($forCustomer) {
            // Every vendor but the one that is this customer themself.
            $marks = WorkFileModel::vendorMarksFor((int) $party->id);
            $said = fn (?string $text) => WorkFileModel::redactVendors($text, $marks);
        } else {
            /*
             * Not the vendor themself, though: their own numbers, their own
             * name, and the customer account that is the same person (linked
             * for set-off). Found in review cut from their own statement —
             * whenever any customer shared a first name, and always for a
             * linked person. A first name typed on its own is still cut when
             * a customer has it: it may be the customer who is meant.
             */
            $self = $party->counterpartId();
            $own = collect([$party->mobile, $party->whatsapp])
                ->map(fn ($number) => substr(preg_replace('/\D/', '', (string) $number), -10))
                ->filter(fn ($digits) => strlen($digits) === 10)
                ->all();

            $marks = array_values(array_filter(
                WorkFileModel::customerMarks(array_filter([$self])),
                fn ($mark) => ! ($mark[0] === 'digits' && in_array($mark[1], $own, true))
            ));

            $said = WorkFileModel::customerRedactor($marks, array_filter([
                $party->name,
                $self ? PartyModel::whereKey($self)->value('name') : null,
            ]));
        }

        foreach ($data['getRecords'] as $entry) {
            $running += $entry->signedAmount();
            $isDebit = $entry->entry_type === 'debit';

            $entries[] = [
                'id' => $entry->id,
                'txn_date' => date('d-m-Y', strtotime($entry->txn_date)),
                'particular' => $said($entry->particular),
                'payment_mode' => $entry->payment_mode,
                'ref_no' => $said($entry->ref_no),
                // Entries a work file generated link back to it; entries typed
                // straight into the ledger just carry whatever reference was given.
                'ref_url' => $entry->work_file_id ? route('workfile.edit', $entry->work_file_id) : null,
                // The side an entry does not fall on stays null, so it exports as
                // a blank cell the way the old table did rather than as 0.00.
                'debit' => $isDebit ? (float) $entry->amount : null,
                'credit' => $isDebit ? null : (float) $entry->amount,
                'balance' => round($running, 2),
                /*
                 * The note on the file, for a customer — never for a vendor.
                 * It is the counter's note on the customer's file: an advance,
                 * a balance, a name. Found in the vendor-side sweep printed
                 * on the vendor's statement as typed; money cannot be told
                 * apart from anything else in free text, so it is not shown.
                 */
                'remarks' => $forCustomer
                    ? WorkFileModel::withoutVendors($remarks[$entry->work_file_id] ?? null, $marks)
                    : null,
                // The files a payment was adjusted against, when it was.
                'against' => PartyLedgerModel::againstText($against[$entry->id] ?? []),
            ] + self::changeFields($entry, $reversedBy, $reversible, self::paymentSide($party->party_type), $history, $period);
        }

        /*
         * The figure the Balance column counts up from, and the figure it
         * arrives at. Both were drawn above the table and neither reached the
         * exports, so a printed statement began mid-air — its first Balance
         * explained by nothing — and ended without saying where it got to.
         *
         * Handed to the grid as framing rows rather than as entries: they are
         * not transactions, and must not be searched, sorted or counted as any.
         */
        $opening = [[
            'id' => null,
            'txn_date' => $fromText === 'Beginning' ? '' : $fromText,
            'particular' => 'Opening Balance',
            'payment_mode' => null,
            'ref_no' => null,
            'ref_url' => null,
            'debit' => null,
            'credit' => null,
            'balance' => round((float) $data['opening'], 2),
            'remarks' => null,
            'against' => null,
            'change' => null,
            'row_state' => null,
            'office_note' => null,
            'adjust_url' => null,
            'setoff_with' => null,
        ]];

        $closing = [[
            'id' => null,
            'txn_date' => $toText === 'Till date' ? '' : $toText,
            'particular' => 'Closing Balance',
            'payment_mode' => null,
            'ref_no' => null,
            'ref_url' => null,
            'debit' => (float) $data['debits'],
            'credit' => (float) $data['credits'],
            'balance' => round((float) $data['closing'], 2),
            'remarks' => null,
            'against' => null,
            'change' => null,
            'row_state' => null,
            'office_note' => null,
            'adjust_url' => null,
            'setoff_with' => null,
        ]];

        // Only when there is one to show. A column of empty cells is clutter on
        // screen and a column of commas in the spreadsheet.
        $hasRemarks = $forCustomer && (bool) $remarks;
        $hasAgainst = (bool) $against;

        $props = [
            // Also the export filename and the heading on the PDF and the printout.
            'title' => $party->name.' Statement '.$periodText,
            'columns' => [
                ['key' => 'id', 'label' => '#'],
                ['key' => 'txn_date', 'label' => 'Txn Date'],
                // Under it, for the office only: what happened to a reversed
                // entry, and why a reversal was made.
                ['key' => 'particular', 'label' => 'Particulars', 'width' => '14rem', 'note' => 'office_note'],
                ['key' => 'payment_mode', 'label' => 'Mode'],
                // The work file opens in a new tab because a statement is read
                // through rather than clicked out of: following the reference in
                // this tab costs the reader their place in the run and the period
                // they filtered to, both of which have to be set up again.
                ['key' => 'ref_no', 'label' => 'Ref No.', 'type' => 'link', 'linkTo' => 'ref_url'],
                // The column colour says which side of the ledger it is; the cell
                // dims itself on the side an entry did not fall on.
                ['key' => 'debit', 'label' => 'Debit', 'type' => 'money', 'class' => 'ui-money--dr'],
                ['key' => 'credit', 'label' => 'Credit', 'type' => 'money', 'class' => 'ui-money--cr'],
                ['key' => 'balance', 'label' => 'Balance', 'type' => 'balance', 'class' => 'ui-money--strong'],

                // Which files a payment was adjusted against, when any was.
                ...($hasAgainst ? [['key' => 'against', 'label' => 'Against', 'width' => '14rem']] : []),

                // The note on the file the entry came from, when any entry has
                // one. Last, so it never pushes the figures off a narrow screen.
                ...($hasRemarks ? [['key' => 'remarks', 'label' => 'Remarks', 'width' => '12rem']] : []),

                /*
                 * Taking an entry back, on the entries that can be: typed on
                 * the Entry screen, not a reversal, not already reversed. Kept
                 * out of the exports and the search, as every action column is.
                 */
                ...($reversible ? [[
                    'key' => 'change',
                    'label' => 'Change',
                    'type' => 'action',
                    'onlyIf' => 'change',
                    'icon' => 'bi-arrow-counterclockwise',
                    'sortable' => false,
                    'searchable' => false,
                    'exportable' => false,
                ]] : []),
            ],
            'rowClass' => 'row_state',
            // Where the Change dialog posts; see reverse().
            'action' => route('party.reverse', ['id' => '__ID__']),
            'csrf' => csrf_token(),
            'rows' => $entries,
            'lead' => $opening,
            'tail' => $closing,
            'perPage' => 50,
            /*
             * Never sortable. Balance is a running total carried forward from the
             * opening balance, so re-ordering the rows detaches every figure from
             * the row it belongs to and the statement is quietly wrong.
             */
            'sortable' => false,
            'totals' => ['debit' => 'sum', 'credit' => 'sum'],
            'emptyText' => ($from || $to)
                ? 'No transactions in this period. Try a wider range, or All.'
                : 'No transactions yet. Use New Entry to record the first one.',
        ];

        $today = now();

        // The Indian financial year starts in April, so "this year" on a
        // statement means April to March, not January to December.
        $fyStart = $today->month >= 4
            ? $today->copy()->startOfYear()->addMonths(3)
            : $today->copy()->subYear()->startOfYear()->addMonths(3);

        return Screen::make('admin.party.statement', 'vue-party-statement', $props, [
            // The customer's message after a reversal made from here; see reverse().
            'receipt' => session('receipt'),
            'type' => $party->party_type,
            'label' => PartyModel::label($party->party_type),
            'partyName' => $party->name,
            'partyMobile' => $party->mobile,
            'partyAddress' => $party->address,
            // Blank means "no separate WhatsApp number", so it falls back to the
            // mobile — the same number in most cases.
            'wa' => $party->whatsapp ?: $party->mobile,
            'waUrl' => WhatsApp::url($party->whatsapp ?: $party->mobile),
            /*
             * A reminder of the balance, for a customer who owes one.
             *
             * The balance today across their whole account, not the closing
             * figure above, which is only as of the end of whatever period
             * the statement has been narrowed to. Never for a vendor: what the
             * office owes a vendor is not something to remind them of.
             */
            'reminder' => $party->party_type === 'customer'
                ? (function () use ($party) {
                    $owing = round(PartyLedgerModel::currentBalance($party->id), 2);

                    return $owing > 0 ? [
                        'name' => $party->name,
                        'mobile' => (string) ($party->whatsapp ?: $party->mobile),
                        'balance' => $owing,
                        'todayLabel' => now()->format('d-m-Y'),
                    ] : null;
                })()
                : null,
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
     * The link between a customer and the vendor account that is the same
     * person, as the edit form shows it. Chosen on the customer's form; read
     * only on the vendor's, with the way to the customer's.
     *
     * The same keys whatever is shown, so the screen's props keep one shape.
     */
    private static function linkProps(?PartyModel $party): array
    {
        $link = ['shown' => false, 'side' => '', 'value' => '', 'options' => [], 'linkedName' => '', 'linkedUrl' => '', 'suggestName' => '', 'suggestUrl' => ''];

        if (! $party || ! PartyLedgerModel::canSetOff()) {
            return $link;
        }

        if ($party->party_type === 'customer') {
            $current = $party->linked_vendor_id ? (int) $party->linked_vendor_id : null;

            // Active vendors not linked to someone else, and the one it is
            // linked to now even if inactive — or a save would drop it.
            $taken = DB::table('party')->where('party_type', 'customer')->where('id', '!=', $party->id)
                ->whereNotNull('linked_vendor_id')->pluck('linked_vendor_id')->map(fn ($id) => (int) $id)->flip();

            return [
                'shown' => true,
                'side' => 'customer',
                'value' => (string) old('linked_vendor_id', $current ? (string) $current : ''),
                'options' => PartyModel::selectList('vendor', $current)
                    ->reject(fn ($vendor) => isset($taken[(int) $vendor->id]))
                    ->map(fn ($vendor) => ['id' => (int) $vendor->id, 'name' => (string) $vendor->name, 'mobile' => (string) $vendor->mobile])
                    ->values()->all(),
            ] + $link;
        }

        $customer = PartyModel::where('party_type', 'customer')->where('linked_vendor_id', $party->id)->first();

        /*
         * Not linked, and a customer has this vendor's own mobile and is not
         * linked to anybody: they may be one person, and the office is asked —
         * never told. The link is made on the customer's screen, which owns it.
         * Asked for by the owner on 2026-09-23.
         *
         * Not for an inactive vendor. Found in review: the customer's screen
         * offers active vendors only, so the note sent the office to a link
         * it could not make there.
         */
        $same = ($customer || ! $party->is_active) ? null : PartyModel::where('party_type', 'customer')
            ->where('mobile', $party->mobile)
            ->whereNull('linked_vendor_id')
            ->first();

        return [
            'shown' => true,
            'side' => 'vendor',
            'linkedName' => $customer ? $customer->name.' ('.$customer->mobile.')' : '',
            'linkedUrl' => $customer ? route('party.edit', $customer->id) : '',
            'suggestName' => $same ? (string) $same->name : '',
            'suggestUrl' => $same ? route('party.edit', $same->id) : '',
        ] + $link;
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
            'link' => self::linkProps($party),
        ];

        return Screen::make('admin.party.form', 'vue-party-form', $props, [
            'type' => $type,
            'label' => PartyModel::label($type),
            'isEdit' => $isEdit,
        ]);
    }

}
