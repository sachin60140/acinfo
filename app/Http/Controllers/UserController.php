<?php

namespace App\Http\Controllers;

use App\Models\ClientLedgerModel;
use App\Models\ClientModel;
use Illuminate\Http\RedirectResponse;
use App\Support\Screen;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;

class UserController extends Controller
{
    public function userlogin()
    {
        if (session()->has('userid')) {
            return redirect('user/dashboard');
        }

        return view('user.userlogin');
    }

    public function logout(Request $request): RedirectResponse
    {
        Session::flush();
        session()->flush();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/user');
    }

    public function authuserlogin(Request $req)
    {
        $req->validate([
            'username' => 'required',
            'password' => 'required|min:5|max:255',
        ]);

        $username = $req->post('username');
        $userpass = $req->post('password');

        $result = ClientModel::where([
            ['mobile', '=', $username],
        ])->first();

        // One message for every failure mode — an unknown mobile, a mobile with no
        // password set, and a wrong password must be indistinguishable, otherwise
        // the form can be used to enumerate which numbers are registered clients.
        if (! $result || ! filled($result->password) || ! Hash::check($userpass, $result->password)) {
            return back()->with('error', 'Mobile number or password is incorrect.');
        }

        $req->session()->regenerate();
        session(['username' => $result->name]);
        session(['userid' => $result->id]);

        return redirect('user/dashboard');
    }

    public function userdashboard(Request $req)
    {
        $clientid = session('userid');

        $totalamount = DB::table('client_ledger')
            ->where('client_id', $clientid)
            ->sum('amount');

        /*
         * The sum, unchanged. Positive is money the office is holding for this
         * client, negative is money the client owes.
         *
         * Passed with that sign rather than negated. The office statements flip
         * it before handing it to balance(), because in their books money held
         * for a client is a credit — this screen is read by the client, for whom
         * it is simply theirs, so the component keeps the client's sign and says
         * which way it falls in words.
         */
        $props = [
            'available' => round((float) $totalamount, 2),
            'asOn' => now()->format('d-m-Y'),
            'statementUrl' => route('userstatement'),
        ];

        return Screen::make('user.user-dashboard', 'vue-user-dashboard', $props)->toResponse($req);
    }

    public function userstatement(Request $req)
    {
        $req->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
        ]);

        // Always the signed-in client's own id — never taken from the request.
        $clientid = session('userid');
        $client = ClientModel::find($clientid);

        $data = ClientLedgerModel::statement($clientid, $req->query('from'), $req->query('to'));

        /*
         * The running balance is accumulated here, in the order the query
         * returned. It is carried into the row rather than recomputed in the
         * browser, because a balance only means anything in sequence.
         */
        $bal = (float) $data['opening'];
        $rows = [];

        foreach ($data['getRecords'] as $item) {
            $bal += $item->amount;

            $rows[] = [
                'id' => $item->id,
                'txn_date' => date('d-m-Y', strtotime($item->txn_date)),
                // Sorting is off on this screen, but the raw date is what any
                // future sort has to use: dd-mm-yyyy compared as text orders by
                // day of the month.
                'txn_date_raw' => $item->txn_date,
                'particular' => $item->particular,
                'payment_type' => $item->payment_type,
                'receipt' => $item->amount > 0 ? (float) $item->amount : null,
                'payment' => $item->amount < 0 ? (float) abs($item->amount) : null,
                'balance' => (float) $bal,
            ];
        }

        $props = [
            'title' => 'My Statement',
            'emptyText' => 'No transactions in this period.',
            'perPage' => 50,
            /*
             * Never sortable. Every balance above is the sum of the rows before
             * it, so re-ordering leaves each figure sitting against a row it does
             * not belong to — a statement that looks entirely normal and is wrong
             * from the first line.
             */
            'sortable' => false,
            'totals' => ['receipt' => 'sum', 'payment' => 'sum'],
            'columns' => [
                ['key' => 'id', 'label' => '#'],
                ['key' => 'txn_date', 'label' => 'Txn Date', 'sortBy' => 'txn_date_raw'],
                ['key' => 'particular', 'label' => 'Details', 'width' => '14rem'],
                ['key' => 'payment_type', 'label' => 'Mode'],
                ['key' => 'receipt', 'label' => 'Receipt', 'type' => 'money'],
                ['key' => 'payment', 'label' => 'Payment', 'type' => 'money'],
                ['key' => 'balance', 'label' => 'Balance', 'type' => 'money', 'class' => 'ui-money--strong'],
            ],
            'rows' => $rows,
        ];

        $today = now();

        // April to March, the Indian financial year.
        $fyStart = $today->month >= 4
            ? $today->copy()->startOfYear()->addMonths(3)
            : $today->copy()->subYear()->startOfYear()->addMonths(3);

        return Screen::make('user.user-statement', 'vue-user-statement', $props, [
            'clientName' => $client ? $client->name : session('username', 'Client'),
            'from' => $req->query('from'),
            'to' => $req->query('to'),
            'entryCount' => count($rows),
            'opening' => (float) $data['opening'],
            'receipts' => (float) $data['receipts'],
            'payments' => (float) $data['payments'],
            'closing' => (float) $data['closing'],
            'base' => route('userstatement'),
            'maxDate' => $today->toDateString(),
            'monthStart' => $today->copy()->startOfMonth()->toDateString(),
            'monthEnd' => $today->copy()->endOfMonth()->toDateString(),
            'fyStart' => $fyStart->toDateString(),
            'fyEnd' => $fyStart->copy()->addYear()->subDay()->toDateString(),
        ])->toResponse($req);
    }
}
