<?php

namespace App\Http\Controllers;

use App\Models\ExpenseTypeModel;
use App\Models\WorkFileExpenseModel;
use App\Support\Screen;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The master list of what the office pays out on a file.
 *
 * A transfer challan, an affidavit, a notary's fee. Reference data, and the same
 * shape as work types: added, corrected, and switched off rather than deleted
 * once anything has been booked against them.
 *
 * The form here is server-rendered rather than a component of its own. Work
 * types have one that is eight hundred lines, and this list needs a name, a
 * usual amount and a switch — copying that to change three fields would leave
 * two screens to keep in step for no gain.
 */
class ExpenseTypeController extends Controller
{
    /**
     * Remove a kind outright.
     *
     * Only one nothing has ever been spent under. A kind with money behind it is
     * what those expenses say they were for: delete it and the file, the report
     * and the margin lose the name of the thing that was paid. Switching it off
     * does the useful half — it stops being offered — and keeps the record.
     */
    public function destroy(Request $req, $id)
    {
        $type = ExpenseTypeModel::findOrFail($id);

        $spent = WorkFileExpenseModel::where('expense_type_id', $type->id)->count();

        if ($spent) {
            return back()->with('error', 'Expense type "'.$type->name.'" cannot be deleted: '
                .$spent.' '.Str::plural('expense', $spent).' '.($spent === 1 ? 'is' : 'are').' recorded against it. '
                .'Switch it off instead — it stops being offered on new expenses and the old ones still read correctly.');
        }

        $name = $type->name;
        $type->delete();

        return redirect()->route('expensetype.index')
            ->with('success', 'Expense type "'.$name.'" deleted.');
    }

    public function index(Request $req, $id = null)
    {
        $editing = $id ? ExpenseTypeModel::findOrFail($id) : null;

        if ($req->isMethod('POST')) {
            $req->validate([
                'name' => ['required', 'string', 'max:255', Rule::unique('expense_type', 'name')->ignore($editing?->id)],
                'default_amount' => 'nullable|numeric|gte:0|max:99999999',
            ]);

            $type = $editing ?: new ExpenseTypeModel;
            $type->name = $req->name;
            /*
             * Blank stays null. A challan is not the same at every office, so a
             * kind with no usual figure is an ordinary thing to have — and a
             * stored zero would fill the box with 0.00 and read as a decision.
             */
            $type->default_amount = $req->filled('default_amount') ? (float) $req->default_amount : null;
            $type->is_active = $editing ? $req->boolean('is_active') : true;
            $type->save();

            return redirect()->route('expensetype.index')
                ->with('success', 'Expense type "'.$type->name.'" '.($editing ? 'updated' : 'added').' successfully.');
        }

        $isEdit = (bool) $editing;

        /*
         * What has actually been spent under each kind. A list of names says
         * nothing about which of them matter; this is the difference between a
         * kind used twice last year and the one that takes the margin.
         */
        $types = ExpenseTypeModel::query()
            ->leftJoin('work_file_expense', 'work_file_expense.expense_type_id', '=', 'expense_type.id')
            ->groupBy('expense_type.id', 'expense_type.name', 'expense_type.default_amount', 'expense_type.is_active')
            ->orderBy('expense_type.name')
            ->select('expense_type.id', 'expense_type.name', 'expense_type.default_amount', 'expense_type.is_active')
            ->selectRaw('COUNT(work_file_expense.id) as used')
            ->selectRaw('COALESCE(SUM(work_file_expense.amount), 0) as spent')
            ->get();

        // After a failed submission the switch is drawn from what was sent, not
        // from what is stored, or a change the user made is silently undone.
        $bag = session('errors');
        $activeChecked = $isEdit ? (($bag && $bag->any()) ? old('is_active') : $editing->is_active) : true;

        $props = [
            'title' => 'Expense Types',
            'perPage' => 100,
            'sortable' => false,
            'emptyText' => 'No expense types yet. Add the first one on the left.',
            'columns' => [
                ['key' => 'name', 'label' => 'Kind', 'sub' => 'retired'],
                ['key' => 'default_amount', 'label' => 'Usual Amount', 'type' => 'money'],
                ['key' => 'used', 'label' => 'Times Used', 'type' => 'count'],
                ['key' => 'spent', 'label' => 'Paid Out', 'type' => 'money'],
                [
                    'key' => 'action',
                    'label' => 'Edit',
                    'type' => 'link',
                    'linkTo' => 'edit_url',
                    'sortable' => false,
                    'searchable' => false,
                    'exportable' => false,
                ],
            ],
            'totals' => ['used' => 'sum', 'spent' => 'sum'],
            'rows' => $types->map(fn ($type) => [
                'id' => (int) $type->id,
                'name' => $type->name,
                // A switched-off kind is still on the list, because the money
                // spent under it is still in the accounts.
                'retired' => $type->is_active ? null : 'Retired',
                'default_amount' => $type->default_amount === null ? null : (float) $type->default_amount,
                'used' => (int) $type->used,
                'spent' => (float) $type->spent,
                'action' => 'Edit',
                'edit_url' => route('expensetype.edit', $type->id),
            ])->values(),
        ];

        return Screen::make('admin.expense-types', 'vue-expense-types', $props, [
            'isEdit' => $isEdit,
            'editingId' => $isEdit ? (int) $editing->id : null,
            'formAction' => $isEdit ? route('expensetype.edit', $editing->id) : route('expensetype.index'),
            'cancelUrl' => route('expensetype.index'),
            'deleteUrl' => $isEdit ? route('expensetype.delete', $editing->id) : null,
            'nameValue' => old('name', $isEdit ? $editing->name : ''),
            'amountValue' => old('default_amount', $isEdit ? $editing->default_amount : ''),
            'activeChecked' => (bool) $activeChecked,
        ])->toResponse($req);
    }
}
