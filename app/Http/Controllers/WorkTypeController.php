<?php

namespace App\Http\Controllers;

use App\Models\WorkTypeModel;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The master list of work a file can be booked against.
 */
class WorkTypeController extends Controller
{
    public function index(Request $req, $id = null)
    {
        $editing = $id ? WorkTypeModel::findOrFail($id) : null;

        if ($req->isMethod('POST')) {
            $req->validate([
                'name' => ['required', 'string', 'max:255', Rule::unique('work_type', 'name')->ignore($editing?->id)],
                'default_rate' => 'nullable|numeric|gte:0|max:99999999',
            ]);

            $type = $editing ?: new WorkTypeModel;
            $type->name = $req->name;
            $type->default_rate = $req->filled('default_rate') ? (float) $req->default_rate : null;
            $type->is_active = $editing ? $req->boolean('is_active') : true;
            $type->save();

            return redirect()->route('worktype.index')
                ->with('success', 'Work type "'.$type->name.'" '.($editing ? 'updated' : 'added').' successfully.');
        }

        return view('admin.work.types', [
            'types' => WorkTypeModel::withUsage(),
            'editing' => $editing,
        ]);
    }
}
