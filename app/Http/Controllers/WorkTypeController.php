<?php

namespace App\Http\Controllers;

use App\Models\WorkTypeModel;
use App\Support\Screen;
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
                'default_vendor_rate' => 'nullable|numeric|gte:0|max:99999999',
            ]);

            $type = $editing ?: new WorkTypeModel;
            $type->name = $req->name;
            $type->default_rate = $req->filled('default_rate') ? (float) $req->default_rate : null;
            // Blank stays null: a rate that varies by vendor or by job has no
            // default worth storing, and blank has always meant "not agreed".
            $type->default_vendor_rate = $req->filled('default_vendor_rate') ? (float) $req->default_vendor_rate : null;
            $type->is_active = $editing ? $req->boolean('is_active') : true;
            $type->save();

            return redirect()->route('worktype.index')
                ->with('success', 'Work type "'.$type->name.'" '.($editing ? 'updated' : 'added').' successfully.');
        }

        $types = WorkTypeModel::withUsage();
        $isEdit = (bool) $editing;

        // After a failed submission the switch is drawn from what was sent, not
        // from what is stored, or a change the user made is silently undone.
        $bag = session('errors');
        $activeChecked = $isEdit ? (($bag && $bag->any()) ? old('is_active') : $editing->is_active) : true;

        $props = [
            'action' => $isEdit ? route('worktype.edit', $editing->id) : route('worktype.index'),
            'csrf' => csrf_token(),
            'cancelUrl' => route('worktype.index'),
            'filesUrl' => route('workfile.index'),
            'editingId' => $isEdit ? (int) $editing->id : null,
            'initial' => [
                'name' => old('name', $isEdit ? $editing->name : ''),
                'default_rate' => old('default_rate', $isEdit ? $editing->default_rate : ''),
                'default_vendor_rate' => old('default_vendor_rate', $isEdit ? $editing->default_vendor_rate : ''),
                'is_active' => (bool) $activeChecked,
            ],
            'types' => $types->map(fn ($type) => [
                'id' => (int) $type->id,
                'name' => $type->name,
                'default_rate' => $type->default_rate === null ? null : (float) $type->default_rate,
                'default_vendor_rate' => $type->default_vendor_rate === null ? null : (float) $type->default_vendor_rate,
                'is_active' => (bool) $type->is_active,
                'file_count' => (int) $type->file_count,
                'billed_total' => (float) $type->billed_total,
                'edit_url' => route('worktype.edit', $type->id),
            ])->values(),
        ];

        return Screen::make('admin.work.types', 'vue-work-types', $props, [
            'isEdit' => $isEdit,
        ])->toResponse($req);
    }
}
