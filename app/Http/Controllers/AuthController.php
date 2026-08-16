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
        $data['totaldues'] = DB::table('client_ledger')->sum('amount');
        $data['clientcount'] = DB::table('client')->count();
        $data['monthnet'] = DB::table('client_ledger')
            ->whereYear('txn_date', now()->year)
            ->whereMonth('txn_date', now()->month)
            ->sum('amount');

        // Vendor & customer ledgers and work files. Read-only, and kept in their
        // own models so this method stays a list of figures rather than queries.
        $data['outstanding'] = PartyModel::outstanding();
        $data['work'] = WorkFileModel::summary();

        // Named locally so the tile descriptions below read as they did in the
        // view they came from.
        $totaldues = $data['totaldues'];
        $monthnet = $data['monthnet'];
        $clientcount = $data['clientcount'];
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
             * Both are sums over client_ledger, which stores a receipt positive —
             * so they are negated and written as balances, exactly as the client
             * list and each client's own statement write the same figures. Left
             * raw they printed "-446,722.91" here while the list one click away
             * printed the same money as "446,722.91 Dr".
             */
            [
                'group' => 'Client ledger',
                'label' => 'Net Outstanding',
                'value' => round(-(float) $totaldues, 2),
                'type' => 'balance',
                'note' => 'Across every client',
            ],
            [
                'group' => 'Client ledger',
                'label' => 'Net Movement',
                'value' => round(-(float) $monthnet, 2),
                'type' => 'balance',
                'note' => now()->format('F Y'),
            ],
            [
                'group' => 'Client ledger',
                'label' => 'Clients',
                'value' => (int) $clientcount,
                'type' => 'count',
                'note' => 'On the books',
            ],
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
                'note' => 'on '.number_format($work['month_billed'], 2, '.', ',').' billed · '.now()->format('F Y'),
            ],
        ];

        return Screen::make('admin.dashboard', 'vue-dashboard', ['tiles' => $tiles])->toResponse($req);
    }

    public function client(Request $req)
    {
        if ($req->isMethod('POST')) {
            $req->validate([

                'name' => 'required',
                'mobile_number' => 'required|digits:10|unique:client,mobile',
                'password' => 'required|min:8|max:255|confirmed',
                'address' => 'required',
            ]);

            $ClientModel = new ClientModel;
            $ClientModel->name = $req->name;
            $ClientModel->mobile = $req->mobile_number;
            $ClientModel->password = Hash::make($req->password);
            $ClientModel->address = $req->address;

            $ClientModel->save();
            $lastid = $ClientModel->id;

            return back()->with('success', 'Client created successfully. Client ID: '.$lastid);
        }

        return view('admin.client');
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
                    // A statement is read beside the list it was opened from,
                    // which is what the old link did and what anyone reconciling
                    // accounts needs.
                    'newTab' => true,
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

        return view('admin.client-password', compact('client'));
    }

    public function paymentreceipt(Request $req)
    {
        // A receipt credits the client: amount is stored positive.
        return $this->ledgerEntry($req, 1, 'admin.payment-reciept', 'Receipt', false);
    }

    public function payment(Request $req)
    {
        // A payment debits the client: amount is stored negative.
        return $this->ledgerEntry($req, -1, 'admin.payment', 'Payment', true);
    }

    /**
     * Shared handler for the receipt and payment screens. They differ only in the
     * sign applied to the amount, so keeping one implementation stops the two
     * halves of the ledger drifting apart when validation or the form changes.
     *
     * @param  int  $sign  1 to credit the client, -1 to debit
     */
    private function ledgerEntry(Request $req, int $sign, string $view, string $label, bool $isPayment)
    {
        if ($req->isMethod('POST')) {
            $req->validate([
                'client_name' => 'required|integer|exists:client,id',
                'paymentMode' => 'required|integer|exists:payment_type,id',
                'txn_date' => 'required|date_format:Y-m-d',
                'amount' => 'required|numeric|gt:0|max:500000',
                'remarks' => 'required|string|max:255',
            ]);

            $ClientLedgerModel = new ClientLedgerModel;
            $ClientLedgerModel->client_id = $req->client_name;
            $ClientLedgerModel->payment_by = $req->paymentMode;
            $ClientLedgerModel->txn_date = $req->txn_date;
            $ClientLedgerModel->amount = $sign * (float) $req->amount;
            $ClientLedgerModel->particular = $req->remarks;

            $ClientLedgerModel->save();

            return back()->with('success', $label.' saved successfully. Transaction ID: '.$ClientLedgerModel->id);
        }

        $data['clientlist'] = DB::table('client')
            ->leftJoin('client_ledger', 'client_ledger.client_id', '=', 'client.id')
            ->select('client.id', 'client.name', DB::raw('COALESCE(SUM(client_ledger.amount), 0) as current_balance'))
            ->groupBy('client.id', 'client.name')
            ->orderBy('client.name', 'asc')
            ->get();

        $payModes = DB::table('payment_type')
            ->select('id', 'payment_mode')
            ->orderBy('payment_mode', 'asc')
            ->get();

        /*
         * The balance is the one summed above, sent as a plain number so the
         * component can work out where the entry lands. client_ledger stores a
         * receipt positive — the opposite of the party tables — and the component
         * negates it before printing a side, the same way the client statement
         * does.
         */
        $clients = $data['clientlist']->map(fn ($client) => [
            'id' => $client->id,
            'name' => $client->name,
            'current_balance' => (float) $client->current_balance,
        ])->values();

        /*
         * The date box stays the shared partial rather than being rebuilt in
         * Vue: assets/js/datepicker.js owns that markup, and dd-mm-yyyy for
         * everyone is the whole reason it exists.
         */
        $dateField = view('partials._datefield', [
            'name' => 'txn_date',
            'value' => old('txn_date', date('Y-m-d')),
            'required' => true,
        ])->render();

        // What Reset puts back, which is what the page loaded with — including a
        // rejected submission's own values.
        $initial = [
            'client_name' => (string) old('client_name'),
            'paymentMode' => (string) old('paymentMode'),
            'amount' => (string) old('amount'),
            'remarks' => (string) old('remarks'),
        ];

        /*
         * The two screens post to the same method but are drawn by different
         * components, and their prop contracts differ — the payment form names a
         * mode 'payment_mode' and takes per-field errors, the receipt names it
         * 'name' and does not. Each is built as its own component expects rather
         * than normalised into one shape, because changing a component's contract
         * is a separate decision from moving where its props are built.
         */
        $props = $isPayment
            ? [
                'action' => route('payment'),
                'csrf' => csrf_token(),
                'clientsUrl' => route('viewclient'),
                'clients' => $clients,
                'paymentModes' => $payModes->map(fn ($mode) => [
                    'id' => $mode->id,
                    'payment_mode' => $mode->payment_mode,
                ])->values(),
                'dateField' => $dateField,
                'initial' => $initial,
                // The summary list stays as it is; this puts the same message
                // against the field it came from. Cast so an empty bag still
                // arrives as an object rather than as an array.
                'errors' => (object) array_map(
                    fn ($messages) => $messages[0],
                    session('errors') ? session('errors')->messages() : []
                ),
            ]
            : [
                'action' => route('receipt'),
                'csrf' => csrf_token(),
                'clientsUrl' => route('viewclient'),
                'clients' => $clients,
                'paymentModes' => $payModes->map(fn ($mode) => [
                    'id' => $mode->id,
                    'name' => $mode->payment_mode,
                ])->values(),
                'dateField' => $dateField,
                'initial' => $initial,
            ];

        return Screen::make(
            $view,
            $isPayment ? 'vue-payment-form' : 'vue-payment-receipt',
            $props
        )->toResponse($req);
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

        foreach ($data['getRecords'] as $item) {
            $amount = (float) $item->amount;
            $bal += $amount;

            $rows[] = [
                'id' => (int) $item->id,
                'txn_date' => date('d-m-Y', strtotime($item->txn_date)),
                'particular' => $item->particular,
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
            'emptyText' => 'No transactions in this period.',
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
