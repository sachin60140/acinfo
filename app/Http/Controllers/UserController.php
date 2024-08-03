<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\ClientModel;
use App\Models\ClientLedgerModel;
use Illuminate\Support\Facades\Session;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;


class UserController extends Controller
{
    public function userlogin()
    {
        if (!empty(Auth::check())) {
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
            'password' => 'required|min:5|max:12'
        ]);

        $username = $req->post('username');
        $userpass = $req->post('password');

        $result = ClientModel::where([
            ['mobile', '=', $username],
        ])->first();
        
        if($result)
        {
            if(Hash::check($userpass, $result->password))
            {
                session(['username' => $result->name]);
                session(['userid' => $result->id]);
                return redirect('user/dashboard');
            }
            else
            {
                return back()->with('error','Please Enter Valid Password Details ');
            }

        }
        else{
            $req->session()->flash('error', 'Please Enter Valid userid Details');
            return redirect('/user'); 
        }

    }

    function userdashboard()
    {
       $clientid =session('userid');

        $totalamount = DB::table("client_ledger")
        ->where('client_id',$clientid)
        ->sum('amount');

       return view('user.user-dashboard',compact('totalamount'));
    }

    public function userstatement()
    {
        $clientid =session('userid');
        $data['getRecords'] = ClientLedgerModel::getRecord($clientid);

        return view('user.user-statement', $data);
    }

    


}
