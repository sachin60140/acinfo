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
