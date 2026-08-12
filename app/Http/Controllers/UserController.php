<?php

namespace App\Http\Controllers;

use App\Models\ClientLedgerModel;
use App\Models\ClientModel;
use Illuminate\Http\RedirectResponse;
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

        if ($result && filled($result->password)) {
            if (Hash::check($userpass, $result->password)) {
                $req->session()->regenerate();
                session(['username' => $result->name]);
                session(['userid' => $result->id]);

                return redirect('user/dashboard');
            } else {
                return back()->with('error', 'Please Enter Valid Password Details ');
            }

        } else {
            $req->session()->flash('error', 'Please Enter Valid userid Details');

            return redirect('/user');
        }

    }

    public function userdashboard()
    {
        $clientid = session('userid');

        $totalamount = DB::table('client_ledger')
            ->where('client_id', $clientid)
            ->sum('amount');

        return view('user.user-dashboard', compact('totalamount'));
    }

    public function userstatement()
    {
        $clientid = session('userid');
        $client = ClientModel::find($clientid);
        $data['clientName'] = $client ? $client->name : session('username', 'Client');
        $data['getRecords'] = ClientLedgerModel::getRecord($clientid);

        return view('user.user-statement', $data);
    }
}
