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
        if (Auth::attempt(['email' => $req->email, 'password' => $req->password], true)) {
            return redirect('admin/dashboard');
        } else {
            return redirect()->back()->with('error', 'Email or password is incorrect');
        }
    }

    public function dashboard()
    {
        $data['totaldues'] = DB::table('client_ledger')->sum('amount');

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

            return back()->with('success', ' Client Ledger Created Successfully: '.$lastid);
        }

        return view('admin.client');
    }

    public function viewclient()
    {
        $data = DB::table('client')->get();

        return view('admin.viewclient', compact('data'));
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
            $ClientLedgerModel->amount = $req->amount;
            $ClientLedgerModel->particular = $req->remarks;

            $ClientLedgerModel->save();
            $lastid = $ClientLedgerModel->id;

            return back()->with('success', ' Payment Reciept Successfully txn id is :  '.$lastid);
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

        return view('admin.payment-reciept', $data);
    }

    public function payment(Request $req)
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
            $ClientLedgerModel->amount = -$req->amount;
            $ClientLedgerModel->particular = $req->remarks;

            $ClientLedgerModel->save();
            $lastid = $ClientLedgerModel->id;

            return back()->with('success', ' Payment Reciept Successfully txn id is :  '.$lastid);
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

        return view('admin.payment', $data);
    }

    public function clientstatement($id)
    {
        $client = ClientModel::find($id);
        $data['clientName'] = $client ? $client->name : 'Unknown Client';
        $data['getRecords'] = ClientLedgerModel::getRecord($id);

        return view('admin.client-statement', $data);
    }
}
