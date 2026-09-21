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

                // The files this payment is adjusted against, keyed by file;
                // see below. An empty box is no line at all.
                // No count here: the limit is on lines with an amount, below.
                'alloc' => 'nullable|array',
                'alloc.*.work_file_id' => 'required|integer',
                'alloc.*.amount' => 'nullable|numeric|min:0|max:99999999',
            ], [
                'alloc.*.amount.numeric' => 'An amount against a file must be a number.',
            ]);

            /*
             * Adjusted against files: which of this party's files the payment
             * is for. What it does not cover settles the oldest charges, as
             * every payment did before; see PartyLedgerModel::settle().
             */
            $lines = self::allocLines($req);

            // A customer pays with a credit; the office pays a vendor with a debit.
            $paymentSide = self::paymentSide($type);

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

            $entry = DB::transaction(function () use ($req, $lines, $type) {
                /*
                 * One payment for a party at a time. Two typed at once would
                 * otherwise both be checked against the same open amount on a
                 * file, and both adjusted against it.
                 */
                PartyModel::whereKey($req->party_id)->lockForUpdate()->first();

                // Checked here, under the lock, against what the ledger says —
                // never against what the page showed.
                self::checkAgainstLedger((int) $req->party_id, $type, $lines);

                $entry = new PartyLedgerModel;
                $entry->party_id = $req->party_id;
                $entry->txn_date = $req->txn_date;
                $entry->entry_type = $req->entry_type;
                $entry->amount = (float) $req->amount;
                $entry->payment_mode = $req->payment_mode;
                $entry->ref_no = $req->ref_no;
                $entry->particular = $req->particular;

                // Who typed it in, from the day it could be recorded.
                if (PartyLedgerModel::adjustable()) {
                    $entry->created_by = Auth::id();
                }

                $entry->save();

                self::allocate($entry->id, (int) $req->party_id, $lines);

                return $entry;
            });

            $saved = back()->with('success', ucfirst($entry->entry_type).' entry saved successfully. Transaction ID: '.$entry->id);

            /*
             * Money in from a customer: offer them a receipt on WhatsApp.
             *
             * Carried to the next page in the session, because the form posts
             * and comes back; it is there once, beside the "saved" message, and
             * gone on the next load. What it says is built from the figures
             * named here and nothing else — never the particular, which is
             * the office's own description of the entry.
             */
            if ($type === 'customer' && $entry->entry_type === 'credit'
                && in_array($entry->payment_mode, PartyLedgerModel::MONEY_MODES, true)) {
                $party = PartyModel::find($entry->party_id);

                $saved->with('receipt', [
                    'name' => $party->name,
                    // Their WhatsApp number when one is saved, as everywhere.
                    'mobile' => (string) ($party->whatsapp ?: $party->mobile),
                    'amount' => (float) $entry->amount,
                    'dateLabel' => date('d-m-Y', strtotime($entry->txn_date)),
                    'mode' => $entry->payment_mode,
                    'reference' => (string) $entry->ref_no,
                    // Today's, after this payment — not as of the day it is
                    // dated, which can be earlier.
                    'balance' => round(PartyLedgerModel::currentBalance($party->id), 2),
                    // So the message can say so: a payment dated last month
                    // beside an undated balance reads as last month's balance.
                    'todayLabel' => now()->format('d-m-Y'),
                    // The files it was adjusted against, as the customer knows
                    // them: the vehicle and the work, never a vendor.
                    'against' => PartyLedgerModel::againstFor([$entry->id])[$entry->id] ?? [],
                ]);
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
            ],

            /*
             * Adjusting a payment against files. Offered on the payment side
             * only — a customer's Credit, a vendor's Debit — and only once the
             * database has the table for it.
             */
            'adjustable' => PartyLedgerModel::adjustable(),
            'paymentSide' => $type === 'customer' ? 'credit' : 'debit',
            'billsUrl' => route('party.bills', ['id' => '__ID__']),
            // A refused save's amounts, file by file, to be put back.
            'initialAlloc' => (object) collect((array) old('alloc', []))
                ->filter(fn ($line) => is_array($line) && ($line['amount'] ?? '') !== '')
                ->mapWithKeys(fn ($line) => [(int) ($line['work_file_id'] ?? 0) => (string) $line['amount']])
                ->all(),
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
     * The files a payment is posted against, as lines.
     *
     * Rounded before anything is judged, so an amount too small to be a paisa
     * is no line at all rather than a line of 0.00; an empty box is no line.
     *
     * @return Collection<int, array{work_file_id: int, amount: float}>
     */
    private static function allocLines(Request $req): Collection
    {
        return collect((array) $req->input('alloc', []))
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
     */
    private static function checkAgainstLedger(int $partyId, string $type, Collection $lines, ?int $except = null, array $keep = []): void
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
            throw ValidationException::withMessages(['alloc' => implode(' ', $problems)]);
        }
    }

    /** The lines, written against the payment. */
    private static function allocate(int $entryId, int $partyId, Collection $lines): void
    {
        foreach ($lines as $line) {
            DB::table('party_ledger_allocation')->insert([
                'entry_id' => $entryId,
                'party_id' => $partyId,
                'work_file_id' => $line['work_file_id'],
                'amount' => $line['amount'],
                'created_by' => Auth::id(),
                'created_at' => now(),
                'updated_at' => now(),
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
            if ($entry->reverses_id || $entry->entry_kind) {
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
     */
    private static function linesText(array $lines): string
    {
        $files = DB::table('work_file')->whereIn('id', array_keys($lines))->get(['id', 'file_no', 'registration_no'])->keyBy('id');

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

            // Written with the payment, or later: a minute's grace for the
            // same save, and a payment older than the table was never adjusted
            // when it was typed.
            $entryCreated = $row->entry_created ? strtotime((string) $row->entry_created) : null;

            if ($row->created_at && ($entryCreated === null || strtotime((string) $row->created_at) > $entryCreated + 60)) {
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
                .' — was '.($was ? self::linesText($was) : 'on account');
        }

        return $out;
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
            $value = (string) $req->query($key, '');
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

                DB::table('party_ledger_allocation')
                    ->where('entry_id', $entry->id)
                    ->whereNull('released_at')
                    ->update(['released_at' => now(), 'released_by' => Auth::id(), 'updated_at' => now()]);

                self::allocate((int) $entry->id, (int) $entry->party_id, $lines);

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
        $bills = PartyLedgerModel::bills($party->id, $type === 'customer' ? 'debit' : 'credit', $entry->id)['files'];
        $files = DB::table('work_file')->whereIn('id', array_keys($live))->get(['id', 'file_no', 'registration_no'])->keyBy('id');

        $currentLines = collect($live)->map(function ($amount, $fileId) use ($bills, $files, $label) {
            $bill = $bills[$fileId] ?? null;

            return [
                'id' => (int) $fileId,
                'fileNo' => (string) ($files[$fileId]->file_no ?? 'File #'.$fileId),
                'vehicle' => (string) ($files[$fileId]->registration_no ?? ''),
                'amount' => (float) $amount,
                'why' => ! $bill || $bill['charged'] <= 0.005
                    ? 'Not charged to this '.strtolower($label).' now — cancelled, or moved. Kept for when it is charged again; until then it counts as on account.'
                    : ($bill['open'] <= 0.005 ? 'The rest of this file is taken by other payments. This payment keeps what it has on it.' : null),
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

        [$entry, $lines] = DB::transaction(function () use ($req, $id) {
            $entry = PartyLedgerModel::whereKey($id)->lockForUpdate()->firstOrFail();
            PartyModel::whereKey($entry->party_id)->lockForUpdate()->first();

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

            // What it was adjusted against, released: a reversed payment
            // settles nothing, and the lines go with it to be typed again.
            $lines = DB::table('party_ledger_allocation')
                ->where('entry_id', $entry->id)
                ->whereNull('released_at')
                ->orderBy('id')
                ->get(['work_file_id', 'amount']);

            DB::table('party_ledger_allocation')
                ->where('entry_id', $entry->id)
                ->whereNull('released_at')
                ->update(['released_at' => now(), 'released_by' => Auth::id(), 'updated_at' => now()]);

            $reversal = new PartyLedgerModel;
            $reversal->party_id = $entry->party_id;
            /*
             * Today, so a statement already sent is not changed after the fact.
             * Never before the entry it takes back, though. Found in review: a
             * post-dated entry reversed today put the reversal first, and every
             * period statement until that date showed money owed that was not.
             * Nothing dated ahead can have been sent yet, so the later date
             * keeps the rule's reason.
             */
            $reversal->txn_date = max(now()->toDateString(), date('Y-m-d', strtotime($entry->txn_date)));
            $reversal->entry_type = $entry->entry_type === 'debit' ? 'credit' : 'debit';
            $reversal->amount = $entry->amount;
            $reversal->payment_mode = PartyLedgerModel::REVERSAL_MODE;
            $reversal->ref_no = $entry->ref_no;
            // What the customer reads: which entry, by its date and amount.
            $reversal->particular = 'Reversal of entry #'.$entry->id.' of '.date('d-m-Y', strtotime($entry->txn_date));
            $reversal->entry_kind = PartyLedgerModel::REVERSAL;
            $reversal->note = trim($req->reason);
            $reversal->reverses_id = $entry->id;
            $reversal->created_by = Auth::id();
            $reversal->save();

            return [$entry, $lines];
        });

        $type = PartyModel::whereKey($entry->party_id)->value('party_type');

        if ($req->boolean('correct')) {
            return redirect()->route('party.entry', $type)
                ->withInput([
                    'party_id' => (string) $entry->party_id,
                    'entry_type' => $entry->entry_type,
                    'txn_date' => date('Y-m-d', strtotime($entry->txn_date)),
                    'amount' => (string) (float) $entry->amount,
                    'payment_mode' => (string) $entry->payment_mode,
                    'ref_no' => (string) $entry->ref_no,
                    'particular' => (string) $entry->particular,
                    'alloc' => $lines->mapWithKeys(fn ($line) => [(int) $line->work_file_id => [
                        'work_file_id' => (int) $line->work_file_id,
                        'amount' => (string) (float) $line->amount,
                    ]])->all(),
                ])
                ->with('success', 'Entry #'.$entry->id.' has been reversed. Enter it again correctly below.');
        }

        // Said as it is: today, unless the entry was dated ahead of today.
        $dated = PartyLedgerModel::where('reverses_id', $entry->id)->value('txn_date');

        return back()->with('success', 'Entry #'.$entry->id.' has been reversed. The reversal is dated '
            .(date('Y-m-d', strtotime($dated)) === now()->toDateString() ? 'today' : date('d-m-Y', strtotime($dated))).'.');
    }

    /**
     * What a statement row says about taking it back: whether it can be (a
     * manual entry, not a reversal, not already reversed), and, for the
     * office only, what happened to it.
     *
     * And whether it can be adjusted against files: a payment, standing, typed
     * by hand. The Change dialog offers that when adjust_url is set.
     *
     * @return array{change: ?string, row_state: ?string, office_note: ?string, adjust_url: ?string}
     */
    private static function changeFields($entry, $reversedBy, bool $reversible, string $paymentSide, array $history = [], array $period = []): array
    {
        if (! $reversible) {
            return ['change' => null, 'row_state' => null, 'office_note' => null, 'adjust_url' => null];
        }

        $reversal = $reversedBy[$entry->id] ?? null;

        if ($reversal) {
            return [
                'change' => null,
                'row_state' => 'is-reversed',
                'office_note' => 'Reversed by #'.$reversal->id.' on '.date('d-m-Y', strtotime($reversal->txn_date)),
                'adjust_url' => null,
            ];
        }

        if ($entry->reverses_id) {
            return [
                'change' => null,
                'row_state' => 'is-reversal',
                'office_note' => $entry->note ? 'Why: '.$entry->note : null,
                'adjust_url' => null,
            ];
        }

        $byHand = ! $entry->work_file_id && ! $entry->entry_kind;

        return [
            'change' => $entry->work_file_id ? null : 'Change',
            'row_state' => null,
            // When its files were last changed, and what it was for before.
            'office_note' => $history[$entry->id] ?? null,
            'adjust_url' => $byHand && $entry->entry_type === $paymentSide
                ? route('party.adjust', ['id' => $entry->id] + $period)
                : null,
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
                'remarks' => $remarks[$entry->work_file_id] ?? null,
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
        ]];

        // Only when there is one to show. A column of empty cells is clutter on
        // screen and a column of commas in the spreadsheet.
        $hasRemarks = (bool) $remarks;
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
