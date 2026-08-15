<?php

namespace App\Http\Controllers;

use App\Models\PartyModel;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Work files.
 *
 * The three things that happen to a file happen at different moments and often
 * to different files at once, so each has its own screen rather than one form
 * that does everything:
 *
 *   receive()  a customer hands over one or more files  -> debits the customer
 *   assign()   files are handed to a vendor             -> credits the vendor
 *   status()   work moves along                         -> no ledger effect
 *
 * edit() remains for correcting a single file after the fact.
 *
 * Every write runs in a transaction with syncLedger(), because a file and the
 * ledger entries it causes are one fact. A file saved whose entries did not
 * follow would leave a statement that disagrees with the job list.
 */
class WorkFileController extends Controller
{
    /**
     * A return amount worth storing, or null for "all of it".
     *
     * The return forms pre-fill the full figure so the operator can see what is
     * going back and edit it down. Storing that pre-filled full amount would be
     * a change of state where nothing actually changed, so it collapses to null —
     * exactly what a blank box has always meant.
     */
    private static function partialOrNull($value, $whole): ?float
    {
        if ($value === null || $value === '' || (float) $value >= (float) $whole) {
            return null;
        }

        return (float) $value;
    }

    public function index(Request $req)
    {
        $req->validate([
            // 'open' is a view of several statuses rather than one of them, so it
            // cannot be validated against the stored list.
            'status' => ['nullable', Rule::in(array_merge(array_keys(WorkFileModel::STATUSES), ['open']))],
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
        ]);

        return view('admin.work.files', [
            'files' => WorkFileModel::listing($req->query('status'), $req->query('from'), $req->query('to')),
            'status' => $req->query('status'),
            'from' => $req->query('from'),
            'to' => $req->query('to'),
        ]);
    }

    /**
     * Receive one or more files from a single customer in one go.
     *
     * The customer and the date are shared; everything else repeats per file.
     * Each row becomes its own work_file with its own number and its own ledger
     * entry, so a statement line still traces back to exactly one job — a single
     * merged line for the batch would lose that as soon as one file was queried.
     */
    public function receive(Request $req)
    {
        if ($req->isMethod('POST')) {
            $req->validate([
                'received_date' => 'required|date_format:Y-m-d',
                'customer_id' => ['required', 'integer', Rule::exists('party', 'id')->where('party_type', 'customer')],
                'rows' => 'required|array|min:1|max:50',
                'rows.*.registration_no' => 'nullable|string|max:20',
                'rows.*.work_type_id' => 'required|integer|exists:work_type,id',
                'rows.*.amount' => 'required|numeric|gte:0|max:99999999',
                'rows.*.description' => 'nullable|string|max:255',
                'remarks' => 'nullable|string|max:255',
            ], [
                'rows.required' => 'Add at least one file.',
                'rows.*.work_type_id.required' => 'Every row needs a type of work.',
                'rows.*.amount.required' => 'Every row needs an amount.',
            ]);

            $saved = DB::transaction(function () use ($req) {
                $files = [];

                foreach ($req->input('rows') as $row) {
                    $file = new WorkFileModel;
                    $file->received_date = $req->received_date;
                    $file->work_type_id = $row['work_type_id'];
                    // Stored one way however it was typed, so the same vehicle
                    // is always found by the history lookup.
                    $file->registration_no = WorkFileModel::normaliseRegistration($row['registration_no'] ?? null) ?: null;
                    $file->customer_id = $req->customer_id;
                    $file->customer_amount = (float) $row['amount'];
                    // A file that has only just arrived is sitting in the office;
                    // moving it along is what the status screen is for.
                    $file->status = WorkFileModel::IN_OFFICE;
                    $file->description = $row['description'] ?? null;
                    $file->remarks = $req->remarks;
                    $file->save();

                    // Derived from the id the insert just produced, still inside the
                    // transaction, so no one ever sees a file without its number.
                    $file->file_no = $file->generateFileNo();
                    $file->save();

                    $file->syncLedger();

                    // Opens the timeline where the file itself opens.
                    $file->logStatus(null, $req->remarks ?: 'Received from customer');

                    $files[] = $file;
                }

                return $files;
            });

            $numbers = implode(', ', array_map(fn ($file) => $file->file_no, $saved));

            return redirect()->route('workfile.index')
                ->with('success', count($saved).' '.Str::plural('file', count($saved)).' received: '.$numbers);
        }

        return view('admin.work.receive', [
            'workTypes' => WorkTypeModel::selectList(),
            'customers' => PartyModel::selectList('customer'),
        ]);
    }

    /**
     * Hand a batch of files to one vendor.
     *
     * Only files that have no vendor yet are offered: moving a file that is
     * already with someone is a correction, and belongs on the edit screen where
     * the consequence for the first vendor's balance is visible.
     */
    public function assign(Request $req)
    {
        if ($req->isMethod('POST')) {
            $req->validate([
                'vendor_id' => ['required', 'integer', Rule::exists('party', 'id')->where('party_type', 'vendor')],
                'vendor_date' => 'required|date_format:Y-m-d',
                'files' => 'required|array|min:1',
                'files.*' => 'integer',
                'amounts' => 'nullable|array',
                'amounts.*' => 'nullable|numeric|gte:0|max:99999999',
                'remark' => 'nullable|string|max:200',
            ], [
                'files.required' => 'Tick at least one file to give to the vendor.',
            ]);

            $amounts = $req->input('amounts', []);

            $assigned = DB::transaction(function () use ($req, $amounts) {
                // Re-read under the same rules the form was built with, so a stale
                // page cannot assign a file that has since been given away or
                // cancelled, and unknown ids simply do not come back.
                $files = WorkFileModel::whereIn('id', $req->input('files'))
                    ->whereNull('vendor_id')
                    ->where('status', '!=', WorkFileModel::CANCELLED)
                    ->get();

                $vendorName = PartyModel::whereKey($req->vendor_id)->value('name');

                foreach ($files as $file) {
                    $amount = $amounts[$file->id] ?? null;
                    $from = $file->status;

                    $file->vendor_id = $req->vendor_id;
                    $file->vendor_amount = ($amount === null || $amount === '') ? null : (float) $amount;
                    $file->vendor_date = $req->vendor_date;

                    // Handing a file over is the moment it leaves the office.
                    if ($file->status === WorkFileModel::IN_OFFICE) {
                        $file->status = WorkFileModel::DISPATCHED;
                    }

                    $file->save();
                    $file->syncLedger();
                    $file->logStatus($from, trim(($req->remark ? $req->remark.' — ' : '').'Given to '.$vendorName));
                }

                return $files;
            });

            if ($assigned->isEmpty()) {
                return back()->with('error', 'Those files are no longer available to give out — they may have been assigned or cancelled already.');
            }

            return redirect()->route('workfile.index')
                ->with('success', $assigned->count().' '.Str::plural('file', $assigned->count()).' given to the vendor: '.$assigned->pluck('file_no')->implode(', '));
        }

        return view('admin.work.assign', [
            'files' => WorkFileModel::unassigned(),
            'vendors' => PartyModel::selectList('vendor'),
        ]);
    }

    /**
     * Give a batch of files back to their customers.
     *
     * The charge each customer already carries stays on their statement and a
     * credit is added beside it, so the pair reads as "charged, then returned"
     * rather than the charge quietly vanishing.
     */
    public function customerReturn(Request $req)
    {
        if ($req->isMethod('POST')) {
            $req->validate([
                'returned_on' => 'required|date_format:Y-m-d',
                'files' => 'required|array|min:1',
                'files.*' => 'integer',
                'amounts' => 'nullable|array',
                'amounts.*' => 'nullable|numeric|gt:0|max:99999999',
                // Money moves back, so it has to say why.
                'remark' => 'required|string|max:200',
            ], [
                'files.required' => 'Tick at least one file to return.',
                'remark.required' => 'Returning a file changes the customer\'s balance, so it needs a reason.',
            ]);

            $amounts = $req->input('amounts', []);

            $overRefunded = WorkFileModel::whereIn('id', $req->input('files'))->get()
                ->filter(function ($file) use ($amounts) {
                    $amount = $amounts[$file->id] ?? null;

                    return $amount !== null && $amount !== ''
                        && (float) $amount > (float) $file->customer_amount;
                })
                ->pluck('file_no');

            if ($overRefunded->isNotEmpty()) {
                return back()->withInput()->with(
                    'error',
                    'A refund cannot exceed what the customer was charged. Check: '.$overRefunded->implode(', ')
                );
            }

            $returned = DB::transaction(function () use ($req, $amounts) {
                $files = WorkFileModel::whereIn('id', $req->input('files'))
                    ->whereNotIn('status', [WorkFileModel::RETURNED, WorkFileModel::CANCELLED])
                    ->get();

                foreach ($files as $file) {
                    $from = $file->status;

                    $file->status = WorkFileModel::RETURNED;
                    $file->returned_on = $req->returned_on;
                    $file->returned_amount = self::partialOrNull($amounts[$file->id] ?? null, $file->customer_amount);
                    $file->save();
                    $file->syncLedger();

                    $file->logStatus($from, $req->remark);
                }

                return $files;
            });

            if ($returned->isEmpty()) {
                return back()->with('error', 'Those files are no longer returnable — they may have been returned or cancelled already.');
            }

            return redirect()->route('workfile.index')
                ->with('success', $returned->count().' '.Str::plural('file', $returned->count())
                    .' returned to the customer: '.$returned->pluck('file_no')->implode(', '));
        }

        return view('admin.work.customer-return', [
            'files' => WorkFileModel::returnableToCustomer(),
        ]);
    }

    /**
     * Take a batch of files back from a vendor.
     *
     * The mirror of assign(): what was booked to the vendor is reversed with a
     * debit beside the original credit, so their statement shows both and nets
     * to nothing owed. The files go back to In Office.
     */
    public function vendorReturn(Request $req)
    {
        if ($req->isMethod('POST')) {
            $req->validate([
                'returned_on' => 'required|date_format:Y-m-d',
                'files' => 'required|array|min:1',
                'files.*' => 'integer',
                'amounts' => 'nullable|array',
                'amounts.*' => 'nullable|numeric|gt:0|max:99999999',
                // Same rule as returning to a customer: a balance moves, so it
                // has to say why.
                'remark' => 'required|string|max:200',
            ], [
                'files.required' => 'Tick at least one file to take back.',
                'remark.required' => 'Taking a file back changes the vendor\'s balance, so it needs a reason.',
            ]);

            $amounts = $req->input('amounts', []);

            // You cannot reverse more than was booked to the vendor.
            $overReversed = WorkFileModel::whereIn('id', $req->input('files'))->get()
                ->filter(function ($file) use ($amounts) {
                    $amount = $amounts[$file->id] ?? null;

                    return $amount !== null && $amount !== ''
                        && (float) $amount > (float) $file->vendor_amount;
                })
                ->pluck('file_no');

            if ($overReversed->isNotEmpty()) {
                return back()->withInput()->with(
                    'error',
                    'A reversal cannot exceed what was booked to the vendor. Check: '.$overReversed->implode(', ')
                );
            }

            $returned = DB::transaction(function () use ($req, $amounts) {
                // Re-read under the same conditions the screen was built with, so
                // a stale page cannot return a file twice or return one that has
                // since been cancelled.
                $files = WorkFileModel::whereIn('id', $req->input('files'))
                    ->whereNotNull('vendor_id')
                    ->whereNull('vendor_returned_on')
                    // Same condition the screen was built with, so a stale page
                    // cannot act on a file that has since finished.
                    ->whereIn('status', WorkFileModel::OPEN_STATUSES)
                    ->with('vendor')
                    ->get();

                foreach ($files as $file) {
                    $from = $file->status;
                    $vendorName = $file->vendor?->name;

                    $amount = $amounts[$file->id] ?? null;

                    $file->vendor_returned_on = $req->returned_on;
                    // Blank, or the whole booking, both mean reverse it all.
                    $file->vendor_returned_amount = self::partialOrNull($amount, $file->vendor_amount);

                    /*
                     * Back on our desk — but only if it was still in play.
                     * Forcing In Office unconditionally rewrote the status of a
                     * file that had already been returned to its customer, and
                     * syncLedger then withdrew that customer's refund and
                     * silently re-charged them the full amount.
                     */
                    if (in_array($file->status, WorkFileModel::OPEN_STATUSES, true)) {
                        $file->status = WorkFileModel::IN_OFFICE;
                    }
                    $file->save();
                    $file->syncLedger();

                    $file->logStatus($from, trim(($req->remark ? $req->remark.' — ' : '').'Papers returned by '.$vendorName));
                }

                return $files;
            });

            if ($returned->isEmpty()) {
                return back()->with('error', 'Those files are no longer out with a vendor — they may have been returned or cancelled already.');
            }

            return redirect()->route('workfile.index')
                ->with('success', $returned->count().' '.Str::plural('file', $returned->count())
                    .' taken back from the vendor: '.$returned->pluck('file_no')->implode(', '));
        }

        return view('admin.work.vendor-return', [
            'files' => WorkFileModel::withVendor(),
        ]);
    }

    /**
     * Move work along. Statuses carry no money of their own, with one exception:
     * cancelling withdraws the file's ledger entries, which syncLedger() handles.
     */
    public function status(Request $req)
    {
        if ($req->isMethod('POST')) {
            $req->validate([
                'statuses' => 'required|array|min:1',
                'statuses.*' => ['required', Rule::in(array_keys(WorkFileModel::STATUSES))],
                'remarks' => 'nullable|array',
                'remarks.*' => 'nullable|string|max:255',
                'refunds' => 'nullable|array',
                'refunds.*' => 'nullable|numeric|gt:0|max:99999999',
                'screenshots' => 'nullable|array',
                'screenshots.*' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:4096',
            ]);

            $wanted = $req->input('statuses');
            $remarks = $req->input('remarks', []);
            $refunds = $req->input('refunds', []);
            $uploads = $req->file('screenshots', []);

            $files = WorkFileModel::whereIn('id', array_keys($wanted))->get();

            // You cannot hand back more than was charged.
            $overRefunded = $files
                ->filter(function ($file) use ($wanted, $refunds) {
                    $refund = $refunds[$file->id] ?? null;

                    return $wanted[$file->id] === WorkFileModel::RETURNED
                        && $refund !== null && $refund !== ''
                        && (float) $refund > (float) $file->customer_amount;
                })
                ->pluck('file_no');

            if ($overRefunded->isNotEmpty()) {
                return back()->withInput()->with(
                    'error',
                    'A refund cannot exceed what the customer was charged. Check: '.$overRefunded->implode(', ')
                );
            }

            // Approval has to be evidenced, so refuse the whole save and name the
            // files that are missing one rather than storing a bare claim.
            $missing = $files
                ->filter(fn ($file) => $wanted[$file->id] === WorkFileModel::APPROVED
                    && ! isset($uploads[$file->id])
                    && ! $file->approval_screenshot)
                ->pluck('file_no');

            if ($missing->isNotEmpty()) {
                return back()->withInput()->with(
                    'error',
                    'Approval Done needs a screenshot. Attach one for: '.$missing->implode(', ')
                );
            }

            // Cancelling and returning both move money, so they have to say why.
            // Everything else takes a remark but does not insist on one.
            $unexplained = $files
                ->filter(function ($file) use ($wanted, $remarks) {
                    $to = $wanted[$file->id];

                    return $file->status !== $to
                        && in_array($to, [WorkFileModel::CANCELLED, WorkFileModel::RETURNED], true)
                        && trim((string) ($remarks[$file->id] ?? '')) === '';
                })
                ->pluck('file_no');

            if ($unexplained->isNotEmpty()) {
                return back()->withInput()->with(
                    'error',
                    'Cancelling or returning a file changes the customer\'s balance, so it needs a reason. Add a remark for: '.$unexplained->implode(', ')
                );
            }

            $changed = DB::transaction(function () use ($files, $wanted, $remarks, $refunds, $uploads) {
                $touched = [];

                foreach ($files as $file) {
                    $upload = $uploads[$file->id] ?? null;
                    $remark = trim((string) ($remarks[$file->id] ?? '')) ?: null;
                    $from = $file->status;
                    $moved = $from !== $wanted[$file->id];

                    /*
                     * Only touch the refund when one was actually submitted.
                     *
                     * A blank box means "the whole charge" and stores null. A key
                     * that is not posted at all means something different: the
                     * refund box was not on screen, so the figure already stored
                     * must stand. Treating the two the same turned un-cancelling a
                     * part-returned file into a full refund.
                     */
                    if ($wanted[$file->id] === WorkFileModel::RETURNED && array_key_exists($file->id, $refunds)) {
                        // Blank and "the whole charge" are the same thing, and both
                        // store null. The form pre-fills the full amount so it can
                        // be seen and edited down; storing that back as a figure
                        // would mark an untouched row dirty and log a phantom change.
                        $file->returned_amount = self::partialOrNull($refunds[$file->id], $file->customer_amount);
                    }

                    // A remark on its own is worth saving: it records chasing a
                    // customer or an update from the RTO without the file moving.
                    // isDirty catches a refund figure edited on an already
                    // returned file, where nothing else about the row changed.
                    if (! $moved && ! $upload && ! $remark && ! $file->isDirty()) {
                        continue;
                    }

                    if ($upload) {
                        $file->storeScreenshot($upload);
                    }

                    $file->status = $wanted[$file->id];
                    $file->save();
                    $file->syncLedger();
                    $file->logStatus($from, $remark);

                    $touched[] = $file->file_no;
                }

                return $touched;
            });

            if (! $changed) {
                return back()->with('error', 'Nothing to update — no status was changed.');
            }

            return redirect()->route('workfile.status')
                ->with('success', count($changed).' '.Str::plural('file', count($changed)).' updated: '.implode(', ', $changed));
        }

        $req->validate([
            'status' => ['nullable', 'string'],
            'work_type' => 'nullable|integer|exists:work_type,id',
        ]);

        // Defaults to work still in hand, which is what this screen is for.
        $filter = $req->query('status', 'open');

        // 'open' and 'all' are tabs rather than stored statuses, so they cannot
        // be validated by Rule::in against the status list.
        if ($filter !== 'open' && $filter !== 'all' && ! array_key_exists($filter, WorkFileModel::STATUSES)) {
            $filter = 'open';
        }

        $workTypeId = $req->query('work_type');
        $files = WorkFileModel::forStatusBoard($filter, $workTypeId);

        return view('admin.work.status', [
            'files' => $files,
            'filter' => $filter,
            'workTypeId' => $workTypeId ? (int) $workTypeId : null,
            'statuses' => WorkFileModel::STATUSES,
            'statusCounts' => WorkFileModel::statusCounts($workTypeId),
            'workTypeCounts' => WorkFileModel::workTypeCounts($filter),
            // Fetched for the whole board in one query rather than per row.
            'lastRemarks' => WorkFileModel::latestRemarks($files->pluck('id')->all()),
        ]);
    }

    public function edit(Request $req, $id)
    {
        $file = WorkFileModel::findOrFail($id);

        if ($req->isMethod('POST')) {
            $req->validate([
                'file_no' => ['nullable', 'string', 'max:30', Rule::unique('work_file', 'file_no')->ignore($file->id)],
                'received_date' => 'required|date_format:Y-m-d',
                'work_type_id' => 'required|integer|exists:work_type,id',
                'registration_no' => 'nullable|string|max:20',

                // Scoped to the role as well as to an existing row, so a tampered
                // form cannot book a vendor into the customer field or the reverse.
                'customer_id' => ['required', 'integer', Rule::exists('party', 'id')->where('party_type', 'customer')],
                'customer_amount' => 'required|numeric|gte:0|max:99999999',

                'vendor_id' => ['nullable', 'integer', 'required_with:vendor_amount', Rule::exists('party', 'id')->where('party_type', 'vendor')],
                'vendor_amount' => 'nullable|numeric|gte:0|max:99999999',
                'vendor_date' => 'nullable|date_format:Y-m-d',

                'status' => ['required', Rule::in(array_keys(WorkFileModel::STATUSES))],
                'returned_amount' => 'nullable|numeric|gt:0|lte:customer_amount',
                'approval_screenshot' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:4096',
                'description' => 'nullable|string|max:255',
                'remarks' => 'nullable|string|max:255',
            ], [
                'vendor_id.required_with' => 'Select the vendor this file was given to before entering a vendor amount.',
            ]);

            // Both the credit and its reversal are tied to one vendor, so moving
            // the file elsewhere afterwards would drag that vendor's history onto
            // someone else's statement. Undo the return first.
            if ($file->isReturnedByVendor() && (int) $req->vendor_id !== (int) $file->vendor_id) {
                return back()->withInput()->with('error', 'This file was returned by '.($file->vendor?->name ?? 'its vendor')
                    .'. Clear the return date before giving it to a different vendor, so their statement keeps both entries.');
            }

            if ($req->status === WorkFileModel::APPROVED
                && ! $req->hasFile('approval_screenshot')
                && ! $file->approval_screenshot) {
                return back()->withInput()->with('error', 'Approval Done needs a screenshot of the approval. Attach one and save again.');
            }

            DB::transaction(function () use ($file, $req) {
                if ($req->hasFile('approval_screenshot')) {
                    $file->storeScreenshot($req->file('approval_screenshot'));
                }

                $from = $file->status;

                $file->file_no = $req->filled('file_no') ? $req->file_no : $file->file_no;
                $file->received_date = $req->received_date;
                $file->work_type_id = $req->work_type_id;
                $file->registration_no = WorkFileModel::normaliseRegistration($req->registration_no) ?: null;
                $file->customer_id = $req->customer_id;
                $file->customer_amount = (float) $req->customer_amount;
                $file->vendor_id = $req->filled('vendor_id') ? $req->vendor_id : null;
                $file->vendor_amount = $req->filled('vendor_amount') ? (float) $req->vendor_amount : null;
                $file->vendor_date = $req->filled('vendor_date') ? $req->vendor_date : null;
                $file->status = $req->status;
                // Blank, or the whole charge, both mean a full refund; the saving
                // hook clears it outright when the status is not a return.
                $file->returned_amount = self::partialOrNull($req->returned_amount, $req->customer_amount);
                $file->description = $req->description;
                $file->remarks = $req->remarks;
                $file->save();

                $file->syncLedger();

                // Only a real move earns a timeline entry here; the edit screen is
                // for corrections, and most of them leave the status alone.
                if ($from !== $file->status) {
                    $file->logStatus($from, 'Changed on the file edit screen');
                }
            });

            return redirect()->route('workfile.index')
                ->with('success', 'File '.$file->file_no.' updated successfully. Ledger entries adjusted to match.');
        }

        return view('admin.work.file-form', [
            'file' => $file,
            'timeline' => $file->statusLog()->with('user')->get(),
            // Whatever this file already points at stays selectable even if it has
            // since been deactivated, so an edit cannot silently reassign it.
            'workTypes' => WorkTypeModel::selectList($file->work_type_id),
            'customers' => PartyModel::selectList('customer', $file->customer_id),
            'vendors' => PartyModel::selectList('vendor', $file->vendor_id),
            'statuses' => WorkFileModel::STATUSES,
        ]);
    }
}
