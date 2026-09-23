<?php

namespace App\Http\Controllers;

use App\Models\OfficeSettingModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The figures the office sets for itself.
 *
 * One so far: the most that may be written off at one time, and against one
 * file. It is the owner's to change — on a host with no editor and no developer
 * — so it lives in the database with the record of who set it, and this screen
 * is where it is set.
 *
 * Server-rendered, like the expense types beside it: a page of one field and a
 * short history has nothing for a component to do.
 */
class OfficeSettingController extends Controller
{
    public function index(Request $req)
    {
        if ($req->isMethod('POST')) {
            $req->validate([
                'writeoff_cap' => 'required|numeric|gte:0|max:99999999',
            ], [
                'writeoff_cap.required' => 'Say the most that may be written off at one time. Nought turns write-offs off.',
            ]);

            $cap = round((float) $req->input('writeoff_cap'), 2);

            if ($cap === OfficeSettingModel::amount(OfficeSettingModel::WRITEOFF_CAP)) {
                return back()->with('success', 'The write-off limit is already '.number_format($cap, 2, '.', ',').'.');
            }

            OfficeSettingModel::put(OfficeSettingModel::WRITEOFF_CAP, number_format($cap, 2, '.', ''), Auth::id());

            return back()->with('success', $cap > 0
                ? 'The most that may be written off at one time is now '.number_format($cap, 2, '.', ',').'.'
                : 'Write-offs are off: nothing can be written off until a limit above nought is set.');
        }

        return view('admin.setup.limits', [
            'cap' => OfficeSettingModel::amount(OfficeSettingModel::WRITEOFF_CAP),
            'history' => OfficeSettingModel::history(OfficeSettingModel::WRITEOFF_CAP),
        ]);
    }
}
