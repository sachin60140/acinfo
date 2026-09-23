<?php

namespace App\Http\Controllers;

use App\Models\ClientLedgerModel;
use App\Models\ClientModel;
use App\Models\PartyModel;
use App\Models\WorkFileModel;
use Illuminate\Http\RedirectResponse;
use App\Support\Screen;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login()
    {
        if (! empty(Auth::check())) {
            return redirect('admin/dashboard');
        }

        return view('admin.login');
    }

    public function logout(Request $request): RedirectResponse
    {
        Session::flush();

        Auth::logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/admin');
    }

    public function authlogin(Request $req)
    {
        $credentials = $req->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (Auth::attempt($credentials, $req->boolean('remember'))) {
            $req->session()->regenerate();

            return redirect('admin/dashboard');
        }

        return redirect()->back()->with('error', 'Email or password is incorrect');
    }

    public function dashboard(Request $req)
    {
        // The old Client Ledger, closed: only how many clients it still holds
        // a balance for, until those are carried to Customers.
        $oldBook = count(ClientLedgerModel::openBalances());

        // Vendor & customer ledgers and work files. Read-only, and kept in their
        // own models so this method stays a list of figures rather than queries.
        $data['outstanding'] = PartyModel::outstanding();
        $data['work'] = WorkFileModel::summary();

        // Named locally so the tile descriptions below read as they did in the
        // view they came from.
        $outstanding = $data['outstanding'];
        $work = $data['work'];

/*
         * Every figure here is one the controller already computed. The tiles are
         * described rather than drawn so the component decides how a figure reads
         * — grouped, two decimals, Dr/Cr where it is a balance — in one place
         * instead of six.
         *
         * Receivable and payable stay separate and adjacent. Netting them would
         * report a business owed 10,000 and owing 7,000 as one owed 3,000, which
         * is a different and much calmer statement than the truth.
         */
        $tiles = [
            /*
             * The old Client Ledger, closed by the owner on 2026-09-23. Its own
             * figures — what it holds, how it moved this month — would read the
             * carrying-over as movement, so they are gone. What is left to carry
             * is said instead, and only while there is any.
             */
            ...($oldBook ? [[
                'group' => 'Client ledger',
                'label' => 'Left in Old Book',
                'value' => $oldBook,
                'type' => 'count',
                'note' => Str::plural('client', $oldBook).' to carry to Customers',
                'href' => route('client.closebook'),
            ]] : []),
            [
                'group' => 'Parties',
                'label' => 'Receivable',
                'value' => (float) $outstanding['receivable'],
                'type' => 'money',
                'tone' => 'dr',
                'note' => $outstanding['customers'].' '.Str::plural('customer', $outstanding['customers']),
                // Lands on the customers this figure was summed from.
                'href' => route('party.index', 'customer'),
            ],
            [
                'group' => 'Parties',
                'label' => 'Payable',
                'value' => (float) $outstanding['payable'],
                'type' => 'money',
                'tone' => 'cr',
                'note' => $outstanding['vendors'].' '.Str::plural('vendor', $outstanding['vendors']),
                'href' => route('party.index', 'vendor'),
            ],
            [
                'group' => 'Work',
                'label' => 'Open Files',
                'value' => (int) $work['open'],
                'type' => 'count',
                'note' => 'Work in hand',
                // The filtered list, not every file ever received — the count and
                // the screen it opens have to be the same set.
                'href' => route('workfile.index', ['status' => 'open']),
            ],
            [
                'group' => 'Work',
                'label' => 'File Margin',
                'value' => (float) $work['month_margin'],
                'type' => 'money',
                'note' => 'on '.number_format($work['month_billed'], 2, '.', ',').' billed · '.now()->format('F Y')
                    .($work['month_unpriced']
                        ? ' · '.$work['month_unpriced'].' of '.$work['month_files'].' awaiting a price'
                        : ''),
            ],
        ];

        /*
         * Who is holding the papers, and for how long.
         *
         * Counted off the works rather than the folders: a folder split between
         * two agents is out with both and belongs to neither. The oldest is
         * named because a vendor with something three days old and one with
         * something five weeks old are the same count and not the same problem.
         */
        $holding = WorkFileModel::vendorsHolding();

        if ($holding['files']) {
            $tiles[] = [
                'group' => 'Work',
                'label' => 'With Vendors',
                'value' => $holding['files'],
                'type' => 'count',
                'note' => trim(implode(' · ', array_filter([
                    $holding['vendors'].' '.Str::plural('vendor', $holding['vendors']),
                    match (true) {
                        $holding['oldest_days'] === null => null,
                        $holding['oldest_days'] === 0 => 'all sent today',
                        default => 'oldest '.$holding['oldest_days'].' '
                            .Str::plural('day', $holding['oldest_days'])
                            .($holding['oldest_vendor'] ? ' with '.$holding['oldest_vendor'] : ''),
                    },
                ]))),
                'href' => route('workfile.vendorreturn'),
            ];
        }

        /*
         * Work the office is doing itself.
         *
         * Beside With Vendors because it is the other half of the same
         * question — who is doing this work, and how long has it waited — and
         * without it the office's own jobs were the one pile of work the
         * dashboard never mentioned.
         */
        $inHouse = WorkFileModel::inHouseWork();

        if ($inHouse->isNotEmpty()) {
            $oldest = (int) $inHouse->max('days');

            $tiles[] = [
                'group' => 'Work',
                'label' => 'In-house Work',
                'value' => $inHouse->count(),
                'type' => 'count',
                'note' => $oldest === 0
                    ? 'all received today'
                    : 'oldest waiting '.$oldest.' '.Str::plural('day', $oldest),
                'href' => route('workfile.inhouse'),
            ];
        }

        /*
         * Work that is through and still on the shelf.
         *
         * Finished, charged for, and not yet collected — money the office has
         * earned and a customer has not come back for. It is the one figure
         * here that is nobody's fault and still somebody's problem.
         */
        $waiting = WorkFileModel::uncollected();

        if ($waiting->isNotEmpty()) {
            $tiles[] = [
                'group' => 'Work',
                'label' => 'Not Yet Collected',
                'value' => round((float) $waiting->sum('outstanding'), 2),
                'type' => 'money',
                'tone' => 'dr',
                'note' => $waiting->count().' '.Str::plural('file', $waiting->count()).' finished and still here',
                'href' => route('report.uncollected'),
            ];
        }

        /*
         * Files nobody can finish, because a paper has not arrived.
         *
         * Aged from the day the papers came in rather than from the day
         * somebody noticed the gap: a file has been stuck for as long as it has
         * been here, whatever the office knew about it.
         */
        $papers = WorkFileModel::papersPending();

        if ($papers['files']) {
            $tiles[] = [
                'group' => 'Work',
                'label' => 'Papers Pending',
                'value' => $papers['files'],
                'type' => 'count',
                'note' => $papers['oldest_days']
                    ? 'oldest here '.$papers['oldest_days'].' '.Str::plural('day', $papers['oldest_days'])
                    : 'waiting on a document',
                'href' => route('workfile.index', ['status' => WorkFileModel::PAPERS_PENDING]),
            ];
        }

        /*
         * The debt that has been sitting longest.
         *
         * Not the largest — the oldest. 5,000 from March is a different
         * conversation from 50,000 from last week, and only one of them is a
         * conversation nobody has had.
         */
        $overdue = PartyModel::oldestUnpaid('customer');

        if ($overdue) {
            $tiles[] = [
                'group' => 'Parties',
                'label' => 'Owing Longest',
                'value' => $overdue['amount'],
                'type' => 'money',
                'tone' => 'dr',
                'note' => $overdue['name'].' · since '.$overdue['since']
                    .' ('.$overdue['days'].' '.Str::plural('day', $overdue['days']).')',
                // Their statement, where the reminder is: one click from the
                // name to the message, rather than a search through everybody.
                'href' => route('party.statement', $overdue['id']),
            ];
        }

        /*
         * Files taken in, or given to a vendor, with the money not yet agreed.
         *
         * Shown only when there are any. A file waiting on a price posts nothing
         * to either ledger — which is correct, and means it is missing from every
         * other figure on this screen without any of them looking wrong. A tile
         * reading zero every day is one nobody reads; one that appears only when
         * something is waiting is one that gets noticed.
         */
        $pending = WorkFileModel::pendingCounts();

        if ($pending['any']) {
            $tiles[] = [
                'group' => 'Work',
                'label' => 'Awaiting Price',
                'value' => $pending['any'],
                'type' => 'count',
                /*
                 * The age matters more than the count. Three files priced
                 * tomorrow and three nobody has looked at since last month are
                 * the same number and not the same situation, and only one of
                 * them is worth interrupting the day for.
                 */
                'note' => trim(implode(' · ', array_filter([
                    $pending['customer'] ? $pending['customer'].' not billed' : null,
                    $pending['vendor'] ? $pending['vendor'].' no vendor rate' : null,
                    match (true) {
                        ($waiting = WorkFileModel::longestWaitingDays()) === null => null,
                        $waiting === 0 => 'all received today',
                        $waiting === 1 => 'oldest 1 day',
                        default => 'oldest '.$waiting.' days',
                    },
                ]))),
                // Lands on exactly the files it counted.
                'href' => route('workfile.index', ['pending' => 'any']),
            ];
        }

        /*
         * Gathered into their groups before they are handed over.
         *
         * The tiles are built in the order they are worked out, and the
         * component starts a new heading whenever the group changes — so
         * Owing Longest, which is a Parties figure and is only known after the
         * work has been counted, put a second "Parties" heading underneath
         * "Work". Sorting is stable in PHP 8, so the order inside each group is
         * the order they were written above.
         */
        $order = ['Client ledger' => 0, 'Parties' => 1, 'Work' => 2];

        usort($tiles, fn ($a, $b) => ($order[$a['group']] ?? 99) <=> ($order[$b['group']] ?? 99));

        /*
         * And the same figures over time.
         *
         * A tile says what this month is; these say which way it has been
         * going, which is the question somebody opens this screen with. Each is
         * described rather than drawn — what to plot and what it is called —
         * so MiniChart decides how a bar looks in one place for all four.
         *
         * Twelve months, because a year is the shortest window in which this
         * business repeats itself: the month the RTO is slow, the month nobody
         * buys a vehicle.
         */
        $byMonth = WorkFileModel::monthlyMoney(12);
        $flow = WorkFileModel::monthlyFlow(12);
        $statuses = WorkFileModel::statusCounts();
        // Already a collection: vendorPerformance() ends in ->get().
        $vendors = WorkFileModel::vendorPerformance();

        $charts = [
            [
                'title' => 'Money by month',
                // Three bars a month over a year: the one chart here that cannot
                // be read in a third of the width.
                'wide' => true,
                'hint' => 'Billed, what it cost, and what was left — by the month the papers came in.',
                'kind' => 'columns',
                'format' => 'money',
                'series' => [
                    ['key' => 'billed', 'label' => 'Billed', 'tone' => 'in'],
                    ['key' => 'cost', 'label' => 'Cost', 'tone' => 'out'],
                    ['key' => 'margin', 'label' => 'Margin', 'tone' => 'net'],
                ],
                'rows' => array_map(fn ($m) => [
                    'label' => $m['label'],
                    'values' => ['billed' => $m['billed'], 'cost' => $m['cost'], 'margin' => $m['margin']],
                ], $byMonth),
                'href' => route('report.profit'),
            ],
            [
                'title' => 'Files in and out',
                'hint' => 'Taken in against finished with. The two lines apart is the office falling behind.',
                'kind' => 'columns',
                'format' => 'count',
                'series' => [
                    ['key' => 'received', 'label' => 'Received', 'tone' => 'in'],
                    ['key' => 'finished', 'label' => 'Finished', 'tone' => 'net'],
                ],
                'rows' => array_map(fn ($m) => [
                    'label' => $m['label'],
                    'values' => ['received' => $m['received'], 'finished' => $m['finished']],
                ], $flow),
                'href' => route('report.files'),
            ],
        ];

        /*
         * Where the open work is sitting. Only the statuses that have anything
         * in them: a row reading zero is a row nobody reads, and the board
         * itself leaves empty tabs out for the same reason.
         */
        $open = [];

        foreach (WorkFileModel::OPEN_STATUSES as $status) {
            if (($statuses[$status] ?? 0) > 0) {
                $open[] = [
                    'label' => WorkFileModel::STATUSES[$status] ?? $status,
                    'value' => $statuses[$status],
                ];
            }
        }

        if ($open) {
            usort($open, fn ($a, $b) => $b['value'] <=> $a['value']);

            $charts[] = [
                'title' => 'Where the open work is',
                'hint' => 'The '.$statuses['open'].' files still in hand, by the stage they have reached.',
                'kind' => 'bars',
                'format' => 'count',
                'rows' => $open,
                'href' => route('workfile.status'),
            ];
        }

        /*
         * How long each vendor takes, once they have finished enough for the
         * figure to mean anything. One file returned in two days is not a
         * two-day vendor, and ranking somebody top on a single job is how a
         * number like this starts being distrusted.
         */
        $turnaround = $vendors
            ->filter(fn ($v) => $v->finished >= 2 && $v->average_days !== null)
            ->sortBy('average_days')
            ->take(8)
            ->map(fn ($v) => [
                'label' => $v->vendor_name,
                'value' => (int) $v->average_days,
                'note' => 'over '.$v->finished.' files',
            ])->values()->all();

        if ($turnaround) {
            $charts[] = [
                'title' => 'Vendor turnaround',
                'hint' => 'Average days to return work, quickest first. Vendors with at least two finished files.',
                'kind' => 'bars',
                'format' => 'count',
                'rows' => $turnaround,
                'href' => route('report.vendors'),
            ];
        }

        return Screen::make('admin.dashboard', 'vue-dashboard', [
            'tiles' => $tiles,
            'charts' => $charts,
        ])->toResponse($req);
    }

    /*
     * Adding a client to the old book, closed with it on 2026-09-23: a client
     * now is a customer. Existing clients, their statements and their portal
     * logins stay.
     */
    public function client(Request $req)
    {
        return $this->bookClosed($req, 'Add Client',
            'Add them as a customer instead, on the Customer Ledger.',
            ['Add Customer' => route('party.create', 'customer')]);
    }

    public function viewclient(Request $req)
    {
        /*
         * Largest amount first, which is the order DataTables applied on load and
         * the reason anyone opens this screen. The order is applied here and then
         * declared to the grid through sortedBy below, so the heading shows which
         * way the list already runs and the first click on it reverses that
         * rather than starting again from smallest.
         */
        $rows = $this->clientsWithBalance()
            ->sortByDesc(fn ($client) => (float) $client->current_balance)
            ->map(fn ($client) => [
                'id' => $client->id,
                'name' => $client->name,
                'mobile' => $client->mobile,
                /*
                 * Negated for the same reason the client statement negates it: a
                 * receipt credits the client and is stored positive, so a positive
                 * balance is money held for them — a credit — while balance()
                 * prints a negative as Cr. Left raw, this list printed
                 * "-1,234.00" for the client whose own statement, one click away,
                 * printed "1,234.00 Dr" for the very same money.
                 */
                'amount' => round(-(float) $client->current_balance, 2),
                'statement' => 'Statement',
                'statement_url' => url('admin/client/statement/'.$client->id),
                'password' => 'Set Password',
                'password_url' => route('clientpassword', $client->id),
            ])
            ->values();

        $props = [
            'columns' => [
                ['key' => 'id', 'label' => '#'],
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'mobile', 'label' => 'Mobile'],
                // Written the way the client's own statement writes it: magnitude
                // plus the side it falls on, never a minus sign.
                ['key' => 'amount', 'label' => 'Amount', 'type' => 'balance'],
                /*
                 * One fixed label on every row, so there is nothing in these two
                 * to sort by and nothing worth carrying into an export. They are
                 * kept out of the search text for the same reason: the words are
                 * on every row, so searching either would match the whole list.
                 */
                [
                    'key' => 'statement',
                    'label' => 'Statement',
                    'type' => 'link',
                    'linkTo' => 'statement_url',
                    'sortable' => false,
                    'searchable' => false,
                    'exportable' => false,
                ],
                [
                    'key' => 'password',
                    'label' => 'Password',
                    'type' => 'link',
                    'linkTo' => 'password_url',
                    // Stays in this tab: setting a password redirects back to this
                    // list, so a new tab would leave the reader with two of them.
                    'sortable' => false,
                    'searchable' => false,
                    'exportable' => false,
                ],
            ],
            'rows' => $rows,
            'title' => 'Client Ledger',
            'perPage' => 50,
            'emptyText' => 'No clients yet.',
            /*
             * Ascending, because the figure was negated above: the rows are in
             * exactly the order they have always been in, largest raw balance
             * first, and describing that order accurately is what lets the first
             * click reverse it instead of re-sorting from scratch.
             */
            'sortedBy' => 'amount',
            'sortedDesc' => false,
        ];

        return Screen::make('admin.viewclient', 'vue-client-list', $props)->toResponse($req);
    }

    /**
     * Every client with their current ledger balance, in one query.
     */
    private function clientsWithBalance()
    {
        return DB::table('client')
            ->leftJoin('client_ledger', 'client_ledger.client_id', '=', 'client.id')
            ->select('client.id', 'client.name', 'client.mobile', DB::raw('COALESCE(SUM(client_ledger.amount), 0) as current_balance'))
            ->groupBy('client.id', 'client.name', 'client.mobile')
            ->orderBy('client.id', 'asc')
            ->get();
    }

    /**
     * An admin changing their own password.
     *
     * The current one is required even though the session already says who this
     * is. A session proves the browser, not the person: an office machine left
     * signed in is the likeliest way this account changes hands, and the
     * current password is the one thing whoever sat down does not have.
     */
    public function password(Request $req)
    {
        $user = Auth::user();

        if ($req->isMethod('POST')) {
            $req->validate([
                'current_password' => 'required|string',
                'password' => 'required|min:8|max:255|confirmed|different:current_password',
            ], [
                'password.different' => 'The new password must be different from the current one.',
            ]);

            if (! Hash::check((string) $req->post('current_password'), (string) $user->password)) {
                return back()->withErrors([
                    'current_password' => 'That is not your current password.',
                ]);
            }

            /*
             * Hashed here as well as by the model's own 'hashed' cast, which
             * recognises an already-hashed value and leaves it alone. Belt and
             * braces on the one field where storing the plain text would be
             * silent and permanent: the cast is a line in a config array that a
             * future edit could drop without anything looking wrong.
             */
            $user->password = Hash::make($req->post('password'));
            $user->save();

            // A new session id for the person who just proved themselves.
            $req->session()->regenerate();

            return redirect('admin/dashboard')
                ->with('success', 'Your password has been changed. Use it the next time you sign in.');
        }

        $props = [
            'action' => route('adminpassword'),
            'csrf' => csrf_token(),
            'cancelUrl' => url('admin/dashboard'),
            // The component's props are named for the screen it was written
            // for. Reused rather than copied: it is the same form.
            'clientName' => $user->name,
            'clientMobile' => $user->email,
            'hasPassword' => true,
            'requireCurrent' => true,
            'intro' => 'You will use the new password the next time you sign in to the admin area.',
            'errors' => (object) array_map(fn ($messages) => $messages[0], session('errors') ? session('errors')->messages() : []),
        ];

        return Screen::make('admin.password', 'vue-admin-password', $props, [
            'userName' => $user->name,
        ])->toResponse($req);
    }

    public function clientpassword(Request $req, $id)
    {
        $client = ClientModel::findOrFail($id);

        if ($req->isMethod('POST')) {
            $req->validate([
                'password' => 'required|min:8|max:255|confirmed',
            ]);

            $client->password = Hash::make($req->password);
            $client->save();

            return redirect()->route('viewclient')->with('success', 'Client password set successfully.');
        }

        $props = [
            'action' => route('clientpassword', $client->id),
            'csrf' => csrf_token(),
            'cancelUrl' => route('viewclient'),
            'clientName' => $client->name,
            'clientMobile' => (string) $client->mobile,
            // Whether this replaces a working login or creates the first one.
            // The hash itself never leaves the server.
            'hasPassword' => filled($client->password),
            'errors' => (object) array_map(fn ($messages) => $messages[0], session('errors') ? session('errors')->messages() : []),
        ];

        return Screen::make('admin.client-password', 'vue-client-password', $props, [
            'clientName' => $client->name,
        ])->toResponse($req);
    }

    /*
     * The old Client Ledger's Receipt and Payment, closed by the owner on
     * 2026-09-23: money is recorded on the Customer/Vendor Ledger's Entry
     * screen only, and two books for the same money meant it could be in
     * either. Each now says where to go instead, and a post — a page left
     * open, or a bookmark — saves nothing. What the old book still holds is
     * carried to Customers by CloseClientLedgerController.
     */
    public function paymentreceipt(Request $req)
    {
        return $this->bookClosed($req, 'Receipt',
            "Record money received from a customer on the Customer Ledger's Entry screen, as a Credit.",
            ['Customer Entry' => route('party.entry', 'customer')]);
    }

    public function payment(Request $req)
    {
        return $this->bookClosed($req, 'Payment',
            "Record money paid out on the Entry screen: to a customer as a Debit on the Customer Ledger, to a vendor as a Debit on the Vendor Ledger.",
            ['Customer Entry' => route('party.entry', 'customer'), 'Vendor Entry' => route('party.entry', 'vendor')]);
    }

    /**
     * What a closed screen of the old book says, and what a post to one does:
     * nothing, but come back here and say so.
     *
     * @param  array<string, string>  $links  label => where to go instead
     */
    private function bookClosed(Request $req, string $what, string $instead, array $links)
    {
        if ($req->isMethod('POST')) {
            return redirect()->to($req->url())->with('error', 'Nothing was saved: the old Client Ledger is closed. '.$instead);
        }

        return response()->view('admin.client-book-closed', [
            'what' => $what,
            'instead' => $instead,
            'links' => $links,
            'openCount' => count(ClientLedgerModel::openBalances()),
        ]);
    }

    public function clientstatement(Request $req, $id)
    {
        $req->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
        ]);

        $client = ClientModel::find($id);

        $data = ClientLedgerModel::statement($id, $req->query('from'), $req->query('to'));
        $clientName = $client ? $client->name : 'Unknown Client';

        $from = $req->query('from');
        $to = $req->query('to');

        // Written once and used twice: on screen, and as the heading a printed or
        // exported statement carries, which is useless without its period.
        $fromText = $from ? date('d-m-Y', strtotime($from)) : 'Beginning';
        $toText = $to ? date('d-m-Y', strtotime($to)) : 'Till date';
        $periodText = $from || $to ? $fromText.' to '.$toText : 'All transactions';

        /*
         * The running balance is accumulated here, in the order the query
         * returned, carried forward from the opening balance. That order is the
         * reason the grid is handed sortable: false — re-ordering the rows would
         * leave every figure in the Balance column standing against the wrong
         * transaction.
         */
        $bal = (float) $data['opening'];
        $rows = [];

        /*
         * What was typed, read for vendors as the client's own portal reads it
         * (UserController::userstatement). This page is the one printed and
         * exported for the client, and a vendor's name never reaches a
         * customer. Found in the vendor-side sweep: the office's copy printed
         * "given to Shailendra" exactly as it was typed.
         */
        $marks = WorkFileModel::vendorMarks();

        foreach ($data['getRecords'] as $item) {
            $amount = (float) $item->amount;
            $bal += $amount;

            $rows[] = [
                'id' => (int) $item->id,
                'txn_date' => date('d-m-Y', strtotime($item->txn_date)),
                'particular' => WorkFileModel::redactVendors($item->particular, $marks),
                'payment_type' => $item->payment_type,
                // Null rather than zero on the side an entry does not fall on, so
                // the export leaves the cell empty the way the old one did.
                'receipt' => $amount > 0 ? $amount : null,
                'payment' => $amount < 0 ? abs($amount) : null,
                /*
                 * Negated deliberately. A receipt credits the client and is stored
                 * positive, so a positive balance is money held for the client — a
                 * credit — while balance() prints a negative as Cr. Passing the
                 * raw figure would name every side backwards.
                 */
                'balance' => round(-$bal, 2),
                'entry_date' => $item->created_at ? date('d-m-Y', strtotime($item->created_at)) : '',
            ];
        }

        $props = [
            // Also the export filename and the heading on the PDF and the printout.
            'title' => $clientName.' Statement '.$periodText,
            'columns' => [
                ['key' => 'id', 'label' => '#'],
                ['key' => 'txn_date', 'label' => 'Txn Date'],
                ['key' => 'particular', 'label' => 'Details', 'width' => '14rem'],
                ['key' => 'payment_type', 'label' => 'Mode'],
                // Coloured for money in and money out, which is how this screen
                // has always been read; the cell dims itself on the side an entry
                // did not fall on.
                ['key' => 'receipt', 'label' => 'Receipt', 'type' => 'money', 'class' => 'ui-money--dr'],
                ['key' => 'payment', 'label' => 'Payment', 'type' => 'money', 'class' => 'ui-money--cr'],
                ['key' => 'balance', 'label' => 'Balance', 'type' => 'balance', 'class' => 'ui-money--strong'],
                ['key' => 'entry_date', 'label' => 'Entry Date'],
            ],
            'rows' => $rows,
            'perPage' => 50,
            /*
             * Never sortable. Balance is a running total carried forward from the
             * opening balance, so re-ordering the rows detaches every figure from
             * the row it belongs to and the statement is quietly wrong.
             */
            'sortable' => false,
            // Not the balance: summing a running total produces a figure that
            // means nothing.
            'totals' => ['receipt' => 'sum', 'payment' => 'sum'],
            'emptyText' => ($from || $to)
                ? 'No transactions in this period. Try a wider range, or All.'
                : 'No transactions yet for this client.',
        ];

        $today = now();

        // The Indian financial year starts in April, so "this year" on a
        // statement means April to March.
        $fyStart = $today->month >= 4
            ? $today->copy()->startOfYear()->addMonths(3)
            : $today->copy()->subYear()->startOfYear()->addMonths(3);

        return Screen::make('admin.client-statement', 'vue-client-statement', $props, [
            'clientName' => $clientName,
            'clientId' => $id,
            'from' => $from,
            'to' => $to,
            'fromText' => $fromText,
            'toText' => $toText,
            'periodText' => $periodText,
            'entryCount' => count($rows),
            'opening' => (float) $data['opening'],
            'receipts' => (float) $data['receipts'],
            'payments' => (float) $data['payments'],
            'closing' => (float) $data['closing'],
            'base' => url('admin/client/statement/'.$id),
            'maxDate' => $today->toDateString(),
            'monthStart' => $today->copy()->startOfMonth()->toDateString(),
            'monthEnd' => $today->copy()->endOfMonth()->toDateString(),
            'fyStart' => $fyStart->toDateString(),
            'fyEnd' => $fyStart->copy()->addYear()->subDay()->toDateString(),
        ])->toResponse($req);
    }
}
