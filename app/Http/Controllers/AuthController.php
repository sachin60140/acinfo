<?php

namespace App\Http\Controllers;

use App\Models\ClientLedgerModel;
use App\Models\ClientModel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;

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

    public function dashboard()
    {
        $data['totaldues'] = DB::table('client_ledger')->sum('amount');
        $data['clientcount'] = DB::table('client')->count();
        $data['monthnet'] = DB::table('client_ledger')
            ->whereYear('txn_date', now()->year)
            ->whereMonth('txn_date', now()->month)
            ->sum('amount');

        return view('admin.dashboard', $data);
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

    public function viewclient()
    {
        $data = $this->clientsWithBalance();

        return view('admin.viewclient', compact('data'));
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
        return $this->ledgerEntry($req, 1, 'admin.payment-reciept', 'Receipt');
    }

    public function payment(Request $req)
    {
        // A payment debits the client: amount is stored negative.
        return $this->ledgerEntry($req, -1, 'admin.payment', 'Payment');
    }

    /**
     * Shared handler for the receipt and payment screens. They differ only in the
     * sign applied to the amount, so keeping one implementation stops the two
     * halves of the ledger drifting apart when validation or the form changes.
     *
     * @param  int  $sign  1 to credit the client, -1 to debit
     */
    private function ledgerEntry(Request $req, int $sign, string $view, string $label)
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

        $data['pay_mode'] = DB::table('payment_type')
            ->select('id', 'payment_mode')
            ->orderBy('payment_mode', 'asc')
            ->get();

        return view($view, $data);
    }

    public function clientstatement(Request $req, $id)
    {
        $req->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
        ]);

        $client = ClientModel::find($id);

        $data = ClientLedgerModel::statement($id, $req->query('from'), $req->query('to'));
        $data['clientName'] = $client ? $client->name : 'Unknown Client';
        $data['clientId'] = $id;

        return view('admin.client-statement', $data);
    }
}
