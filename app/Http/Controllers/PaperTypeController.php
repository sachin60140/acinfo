<?php

namespace App\Http\Controllers;

use App\Models\PaperTypeModel;
use App\Models\WorkTypeModel;
use App\Support\Screen;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The papers the office deals in, and which works need each of them.
 *
 * The list every file's checklist is built from. Edited from the paper's side
 * rather than the work type's: "which works need a bank NOC" is one question
 * with one answer per work type, and the work types form is eight hundred
 * lines that has no business growing a grid of checkboxes.
 *
 * A change here reaches files audited from now on. A file already checked
 * keeps the list it was checked against — see work_file_paper_item.
 */
class PaperTypeController extends Controller
{
    public function index(Request $req, $id = null)
    {
        $editing = $id ? PaperTypeModel::findOrFail($id) : null;

        $workTypes = WorkTypeModel::query()->where('is_active', 1)->orderBy('name')->get(['id', 'name']);

        if ($req->isMethod('POST')) {
            $req->validate([
                'name' => ['required', 'string', 'max:120', Rule::unique('paper_type', 'name')->ignore($editing?->id)],
                'sort' => 'nullable|integer|min:0|max:9999',
                'needs' => 'nullable|array',
                'needs.*' => ['nullable', Rule::in([PaperTypeModel::REQUIRED, PaperTypeModel::OPTIONAL, ''])],
            ]);

            DB::transaction(function () use ($req, $editing, $workTypes, &$paper) {
                $paper = $editing ?: new PaperTypeModel;
                $paper->name = trim($req->input('name'));
                // A new paper goes to the foot of the checklist unless told otherwise.
                $paper->sort = $req->filled('sort')
                    ? (int) $req->input('sort')
                    : ($editing ? $editing->sort : (int) PaperTypeModel::max('sort') + 10);
                $paper->is_active = $editing ? $req->boolean('is_active') : true;
                $paper->save();

                // Only the work types the form showed. A retired one keeps its list.
                $sent = (array) $req->input('needs', []);
                $paper->setNeeds($workTypes->mapWithKeys(fn ($type) => [$type->id => (string) ($sent[$type->id] ?? '')])->all());
            });

            return redirect()->route('papertype.index')
                ->with('success', 'Paper "'.$paper->name.'" '.($editing ? 'updated' : 'added').'. Files audited from now on use the new list.');
        }

        $isEdit = (bool) $editing;

        // Every work type's needs in one query, for the "Needed for" column.
        $needs = DB::table('work_type_paper')
            ->join('work_type', 'work_type.id', '=', 'work_type_paper.work_type_id')
            ->orderBy('work_type.name')
            ->get(['work_type_paper.paper_type_id', 'work_type.name', 'work_type_paper.required'])
            ->groupBy('paper_type_id');

        $papers = PaperTypeModel::query()
            ->leftJoin('work_file_paper', 'work_file_paper.paper_type_id', '=', 'paper_type.id')
            ->groupBy('paper_type.id', 'paper_type.name', 'paper_type.is_active', 'paper_type.sort')
            ->orderBy('paper_type.sort')
            ->orderBy('paper_type.name')
            ->select('paper_type.id', 'paper_type.name', 'paper_type.is_active', 'paper_type.sort')
            ->selectRaw('COUNT(work_file_paper.id) as used')
            ->get();

        $describe = function ($rows) {
            if (! $rows || $rows->isEmpty()) {
                return null;
            }

            $required = $rows->where('required', 1)->pluck('name');
            $optional = $rows->where('required', 0)->pluck('name');

            return implode(' · ', array_filter([
                $required->implode(', '),
                $optional->isNotEmpty() ? 'if applicable: '.$optional->implode(', ') : null,
            ]));
        };

        $bag = session('errors');
        $failed = $bag && $bag->any();
        $currentNeeds = $editing ? $editing->needs() : [];

        $props = [
            'title' => 'Paper Types',
            'perPage' => 100,
            'sortable' => false,
            'emptyText' => 'No papers yet. Add the first one on the left.',
            'columns' => [
                ['key' => 'name', 'label' => 'Paper', 'sub' => 'retired'],
                ['key' => 'needed_for', 'label' => 'Needed For'],
                ['key' => 'used', 'label' => 'On Files', 'type' => 'count'],
                ['key' => 'sort', 'label' => 'Order', 'type' => 'count'],
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
            'rows' => $papers->map(fn ($paper) => [
                'id' => (int) $paper->id,
                'name' => $paper->name,
                'retired' => $paper->is_active ? null : 'Retired',
                // Said outright when nothing needs it, rather than left blank: a
                // paper no work asks for never appears on a checklist.
                'needed_for' => $describe($needs->get($paper->id)) ?? 'No work type — never asked for',
                'used' => (int) $paper->used,
                'sort' => (int) $paper->sort,
                'action' => 'Edit',
                'edit_url' => route('papertype.edit', $paper->id),
            ])->values(),
        ];

        return Screen::make('admin.work.paper-types', 'vue-paper-types', $props, [
            'isEdit' => $isEdit,
            'formAction' => $isEdit ? route('papertype.edit', $editing->id) : route('papertype.index'),
            'cancelUrl' => route('papertype.index'),
            'deleteUrl' => $isEdit ? route('papertype.delete', $editing->id) : null,
            'nameValue' => old('name', $isEdit ? $editing->name : ''),
            'sortValue' => old('sort', $isEdit ? $editing->sort : ''),
            'activeChecked' => (bool) ($isEdit ? ($failed ? old('is_active') : $editing->is_active) : true),
            'workTypes' => $workTypes->map(fn ($type) => [
                'id' => (int) $type->id,
                'name' => $type->name,
                // What was sent, after a bounced save; what is stored, otherwise.
                'need' => $failed ? (string) old('needs.'.$type->id, '') : ($currentNeeds[$type->id] ?? ''),
            ])->all(),
            'needLabels' => PaperTypeModel::NEED_LABELS,
        ])->toResponse($req);
    }

    /**
     * Remove a paper outright — only one no file has ever been checked against.
     * One in use is what those files say they received; retiring it stops it
     * being asked for and keeps the record.
     */
    public function destroy(Request $req, $id)
    {
        $paper = PaperTypeModel::findOrFail($id);
        $used = $paper->timesUsed();

        if ($used) {
            return back()->with('error', 'Paper "'.$paper->name.'" cannot be deleted: it is on '
                .$used.' '.Str::plural('file', $used).'\' checklists. Switch it off instead — it stops being asked for, and those files still read correctly.');
        }

        $name = $paper->name;

        DB::transaction(function () use ($paper) {
            $paper->setNeeds(DB::table('work_type_paper')->where('paper_type_id', $paper->id)->pluck('work_type_id')
                ->mapWithKeys(fn ($id) => [$id => ''])->all());
            $paper->delete();
        });

        return redirect()->route('papertype.index')->with('success', 'Paper "'.$name.'" deleted.');
    }
}
