<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Hash;

use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\ClientModel;
use App\Models\ClientLedgerModel;

class AuthController extends Controller
{
    function pass()
    {
        dd(Hash::make('ALTPR4242F@99014'));
    }

    public function login()
    {       
        if (!empty(Auth::check())) {
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

        return view('admin.dashboard',$data);
    }

    public function client(Request $req)
    {
        if ($req->isMethod('POST'))
        {
            $req->validate([
                
                'name' => 'required',
                'mobile_number' => 'required|numeric',
                'address' => 'required',
            ]);

            $ClientModel = new ClientModel;
            $ClientModel->name = $req->name;
            $ClientModel->mobile = $req->mobile_number;
            $ClientModel->address = $req->address;
            
            $ClientModel->save();
            $lastid = $ClientModel->id;
  
        return back()->with('success', ' Client Ledger Created Successfully: ' .$lastid);
        }

        return view('admin.client');
    }
    public function viewclient()
    {
        $data = DB::table('client')->get();
       
        return view('admin.viewclient',compact('data'));
    }

    public function paymentreceipt(Request $req)
    {
        if ($req->isMethod('POST'))
        {
            $req->validate([
                
                'client_name' => 'required',
                'paymentMode' => 'required',
                'txn_date' => 'required',
                'amount' => 'required',
                'remarks' => 'required',
            ]);

            $ClientLedgerModel = new ClientLedgerModel;
            $ClientLedgerModel->client_id = $req->client_name;
            $ClientLedgerModel->payment_by = $req->paymentMode;
            $ClientLedgerModel->txn_date = $req->txn_date;
            $ClientLedgerModel->amount = $req->amount;
            $ClientLedgerModel->particular = $req->remarks;
            
            $ClientLedgerModel->save();
            $lastid = $ClientLedgerModel->id;
  
        return back()->with('success', ' Payment Reciept Successfully txn id is :  ' .$lastid);
        }

        $data['clientlist'] = DB::table('client')
        ->select('id', 'name')
        ->orderBy('name', 'asc')
        ->get();

        $data['pay_mode'] = DB::table('payment_type')
        ->select('id', 'payment_mode')
        ->orderBy('payment_mode', 'asc')
        ->get();

        return view('admin.payment-reciept',$data);
    }
    public function payment(Request $req)
    {
        if ($req->isMethod('POST'))
        {
            $req->validate([
                
                'client_name' => 'required',
                'paymentMode' => 'required',
                'txn_date' => 'required',
                'amount' => 'required',
                'remarks' => 'required',
            ]);

            $ClientLedgerModel = new ClientLedgerModel;
            $ClientLedgerModel->client_id = $req->client_name;
            $ClientLedgerModel->payment_by = $req->paymentMode;
            $ClientLedgerModel->txn_date = $req->txn_date;
            $ClientLedgerModel->amount = -$req->amount;
            $ClientLedgerModel->particular = $req->remarks;
            
            $ClientLedgerModel->save();
            $lastid = $ClientLedgerModel->id;
  
        return back()->with('success', ' Payment Reciept Successfully txn id is :  ' .$lastid);
        }

        $data['clientlist'] = DB::table('client')
        ->select('id', 'name')
        ->orderBy('name', 'asc')
        ->get();

        $data['pay_mode'] = DB::table('payment_type')
        ->select('id', 'payment_mode')
        ->orderBy('payment_mode', 'asc')
        ->get();

        return view('admin.payment',$data);
    }

    public function clientstatement($id)
    {
        $data['getRecords'] = ClientLedgerModel::getRecord($id);

        return view('admin.client-statement', $data);
    }
}
