<?php

namespace App\Http\Controllers;

use App\Models\PartyModel;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkTypeModel;
use App\Support\Screen;
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
            'pending' => ['nullable', Rule::in(array_keys(WorkFileModel::PENDING))],
        ]);

        $files = WorkFileModel::listing(
            $req->query('status'),
            $req->query('from'),
            $req->query('to'),
            $req->query('pending')
        );

        return $this->filesScreen($files, $req)->toResponse($req);
    }

    /**
     * What the files list shows, and what the grid is handed to show it.
     *
     * Built here rather than in the view because the same payload has to be
     * available as JSON: a route component fetches it, and there is no Blade in
     * that path. Describing it once is what keeps the page and the data from
     * drifting into disagreement.
     */
    private function filesScreen($files, Request $req): Screen
    {
        // Whether anything is narrowing the list, which decides what an empty
        // one means and therefore what it should say.
        $filtered = (bool) ($req->query('status') || $req->query('from') || $req->query('to') || $req->query('pending'));

        /*
         * Totals follow what each file actually earned and cost once its status
         * is taken into account — cancelled charged nobody, returned charged and
         * gave it straight back. Counting the face figures would make this page
         * disagree with the statements it is supposed to summarise.
         */
        $billed = 0.0;
        $cost = 0.0;
        $closedCount = 0;
        $rows = [];

        foreach ($files as $f) {
            // Decided once and read twice: a closed file is kept out of the
            // count above and greyed in the list below.
            $isClosed = in_array($f->status, [WorkFileModel::CANCELLED, WorkFileModel::RETURNED], true);

            if ($isClosed) {
                $closedCount++;
            }

            // Part refunds and vendor returns both change what a file really
            // earned and cost. Leaving those out made this page disagree with
            // the statements and the dashboard it is meant to summarise.
            $netCustomer = WorkFileModel::netCustomer($f->status, $f->customer_amount, $f->returned_amount);
            $netVendor = WorkFileModel::netVendor($f->status, $f->vendor_amount, $f->vendor_returned_on !== null, $f->vendor_returned_amount);

            $billed += $netCustomer;
            $cost += $netVendor;

            $rows[] = [
                'id' => $f->id,
                'file_no' => $f->file_no,
                'edit_url' => route('workfile.edit', $f->id),
                'registration_no' => $f->registration_no,
                'received' => date('d-m-Y', strtotime($f->received_date)),
                // Sorted on rather than shown: dd-mm-yyyy compared as text orders
                // by day of the month, putting 02-03 above 01-12.
                'received_raw' => $f->received_date,
                'work_type' => $f->work_type,
                'description' => $f->description,
                'customer' => $f->customer_name,
                'customer_url' => route('party.statement', $f->customer_id),

                /*
                 * The figures that count, not the ones the file was entered with,
                 * so the column and the totals under it agree with the summary
                 * above and with the party statements. Where the two differ the
                 * original is kept on a second line: a part refund or a
                 * cancellation is easier to trust when the row shows its working.
                 *
                 * number_format writes what the grid's money() writes — groups of
                 * three, two decimals, a full stop between them — so the two
                 * figures in one cell cannot disagree about how a figure is written.
                 */
                'charged' => $netCustomer,
                'charged_was' => abs($netCustomer - (float) $f->customer_amount) > 0.005
                    ? 'was '.number_format((float) $f->customer_amount, 2, '.', ',')
                    : null,

                'vendor' => $f->vendor_name ?? 'In-house',
                'vendor_url' => $f->vendor_id ? route('party.statement', $f->vendor_id) : null,
                // An in-house file has no vendor and so has no cost; 0.00 states
                // one that was never incurred, and netVendor() returns 0.0 for a
                // null amount. The old cell was left blank for exactly this row.
                'cost' => $f->vendor_amount === null ? null : $netVendor,
                'cost_was' => abs($netVendor - (float) $f->vendor_amount) > 0.005
                    ? 'was '.number_format((float) $f->vendor_amount, 2, '.', ',')
                    : null,

                'margin' => $netCustomer - $netVendor,

                'status' => WorkFileModel::STATUSES[$f->status] ?? $f->status,
                // The badge colours itself from the raw key, not the label.
                'status_key' => $f->status,
                'screenshot' => $f->approval_screenshot ? 'Approval screenshot on file' : null,
                // The evidence itself. Approval is the one status that has to be
                // evidenced, so the screenshot has to be reachable from the list
                // as it was from the paperclip — a statement of it is not evidence.
                'screenshot_url' => $f->approval_screenshot ? url($f->approval_screenshot) : null,

                'action' => 'Edit',

                // Greys the whole row. A closed file is still worth seeing but is
                // no longer in play, and should not read like live work.
                'row_class' => $isClosed ? 'is-closed' : '',
            ];
        }

        $props = [
            // Names the export file and heads the PDF and the print sheet.
            'title' => 'Work Files',
            'perPage' => 50,
            /*
             * Two different situations, and until now one sentence.
             *
             * An empty list because nothing has ever been received reads as a
             * broken screen — the first person to open this one on a live
             * install reported it as unavailable. An empty list because a filter
             * excluded everything is not broken at all, and needs the opposite
             * advice. So each says what is actually true and what to do next.
             */
            'emptyText' => $filtered
                ? 'No files match these filters. Try widening the dates, or clearing the status.'
                : 'No files received yet. Use Receive Files above to add the first one.',
            'totals' => ['charged' => 'sum', 'cost' => 'sum', 'margin' => 'sum'],
            'rowClass' => 'row_class',
            'columns' => [
                ['key' => 'file_no', 'label' => 'File No.', 'type' => 'link', 'linkTo' => 'edit_url'],
                ['key' => 'registration_no', 'label' => 'Vehicle'],
                // Shown dd-mm-yyyy, sorted on the raw Y-m-d each row also carries,
                // so oldest-first and newest-first both mean what they say.
                ['key' => 'received', 'label' => 'Received', 'sortBy' => 'received_raw'],
                ['key' => 'work_type', 'label' => 'Work Type'],
                ['key' => 'description', 'label' => 'Details'],
                // A party statement is opened to be read against this list, and
                // this list is behind a status and date filter — taking the tab
                // with it means setting the filter again to come back.
                ['key' => 'customer', 'label' => 'Customer', 'type' => 'link', 'linkTo' => 'customer_url', 'newTab' => true],
                // Debit green, credit red — the same two directions the rest of
                // the ledger uses, carried by the class the sheet already defines.
                ['key' => 'charged', 'label' => 'Charged', 'type' => 'money', 'class' => 'dr', 'sub' => 'charged_was'],
                ['key' => 'vendor', 'label' => 'Vendor', 'type' => 'link', 'linkTo' => 'vendor_url', 'newTab' => true],
                ['key' => 'cost', 'label' => 'Cost', 'type' => 'money', 'class' => 'cr', 'sub' => 'cost_was'],
                // A margin has a side: earned reads Dr, lost reads Cr, and neither
                // needs a minus sign to be read correctly.
                ['key' => 'margin', 'label' => 'Margin', 'type' => 'balance', 'class' => 'fw-bold'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'badge',
                    'sub' => 'screenshot', 'subLinkTo' => 'screenshot_url'],
                // A column of the word "Edit" is noise in a spreadsheet, and in the
                // search box it is worse: every row matches anyone typing "edit".
                ['key' => 'action', 'label' => 'Action', 'type' => 'link', 'linkTo' => 'edit_url',
                    'sortable' => false, 'searchable' => false, 'exportable' => false],
            ],
            'rows' => $rows,
        ];

        return Screen::make('admin.work.files', 'vue-files-list', $props, [
            'status' => $req->query('status'),
            'from' => $req->query('from'),
            'to' => $req->query('to'),
            'billed' => $billed,
            'cost' => $cost,
            'closedCount' => $closedCount,
            'fileCount' => count($rows),
            // 'open' is a view of several statuses rather than one of them, so
            // it has no entry in the stored list to look up.
            'statusLabel' => match (true) {
                ! $req->query('status') => null,
                $req->query('status') === 'open' => 'Work in hand',
                default => WorkFileModel::STATUSES[$req->query('status')] ?? $req->query('status'),
            },
            'base' => route('workfile.index'),
            'maxDate' => now()->toDateString(),

            /*
             * Files still waiting on a price.
             *
             * A file can be taken in and given to a vendor before either figure
             * is agreed, and the ledgers stay quiet until there is something to
             * post — correct, and exactly why an unpriced file is invisible
             * until someone looks for it. Each chip lands on the set it counted.
             */
            'pending' => $req->query('pending'),
            'pendingLabel' => WorkFileModel::PENDING[$req->query('pending')] ?? null,
            'pendingCounts' => WorkFileModel::pendingCounts(),
            'pendingLabels' => WorkFileModel::PENDING,
            'pendingUrls' => collect(WorkFileModel::PENDING)
                ->map(fn ($label, $key) => route('workfile.index', ['pending' => $key]))
                ->all(),
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
                'rows.*.description' => 'nullable|string|max:255',
                // Papers for one vehicle can be for several jobs at once, and
                // each is charged for separately.
                'rows.*.works' => 'required|array|min:1|max:10',
                'rows.*.works.*.work_type_id' => 'required|integer|exists:work_type,id',
                'rows.*.works.*.amount' => 'required|numeric|gte:0|max:99999999',
                'remarks' => 'nullable|string|max:255',
            ], [
                'rows.required' => 'Add at least one file.',
                'rows.*.works.required' => 'Every file needs at least one work.',
                'rows.*.works.*.work_type_id.required' => 'Every work needs a type.',
                'rows.*.works.*.amount.required' => 'Every work needs an amount.',
            ]);

            $saved = DB::transaction(function () use ($req) {
                $files = [];

                foreach ($req->input('rows') as $row) {
                    $works = $row['works'];

                    $file = new WorkFileModel;
                    $file->received_date = $req->received_date;
                    // The first job, for the columns that still read a single type.
                    $file->work_type_id = $works[0]['work_type_id'];
                    // Stored one way however it was typed, so the same vehicle
                    // is always found by the history lookup.
                    $file->registration_no = WorkFileModel::normaliseRegistration($row['registration_no'] ?? null) ?: null;
                    $file->customer_id = $req->customer_id;
                    // Replaced by the roll-up below once the jobs exist; set here
                    // so the row is never written without a figure at all.
                    $file->customer_amount = 0;
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

                    /*
                     * The jobs the papers are for. Written after the file so they
                     * have its id, and before syncLedger so the figure that
                     * reaches the customer's statement is the sum of them.
                     */
                    foreach ($works as $work) {
                        $item = new WorkFileItemModel;
                        $item->work_file_id = $file->id;
                        $item->work_type_id = $work['work_type_id'];
                        $item->customer_amount = (float) $work['amount'];
                        $item->status = WorkFileModel::IN_OFFICE;
                        $item->save();
                    }

                    $file->load('items');
                    $file->rollUp();
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

        $workTypes = WorkTypeModel::selectList();
        $customers = PartyModel::selectList('customer');

        // On a validation failure the user gets their rows back, not a blank form.
        // A bounced submission gets its rows back, works and all.
        $oldRows = old('rows', [['registration_no' => '', 'description' => '', 'works' => [['work_type_id' => '', 'amount' => '']]]]);

        $props = [
            'workTypes' => $workTypes->map(fn ($type) => [
                'id' => $type->id,
                'name' => $type->name,
                'default_rate' => $type->default_rate,
            ])->values(),
            'historyUrl' => route('api.workfile.history'),
            'oldRows' => collect($oldRows)->map(fn ($row) => [
                'registration_no' => $row['registration_no'] ?? '',
                'description' => $row['description'] ?? '',
                'works' => collect($row['works'] ?? [[]])->map(fn ($work) => [
                    'work_type_id' => $work['work_type_id'] ?? '',
                    'amount' => $work['amount'] ?? '',
                ])->values(),
            ])->values(),
        ];

        return Screen::make('admin.work.receive', 'vue-receive-rows', $props, [
            'workTypes' => $workTypes,
            'customers' => $customers,
            // Nothing can be received without both a work type and a customer.
            'blocked' => $workTypes->isEmpty() || $customers->isEmpty(),
        ])->toResponse($req);
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

        $files = WorkFileModel::unassigned();
        $vendors = PartyModel::selectList('vendor');

        // A bounced batch comes back with the date the user chose, not today's.
        $vendorDate = old('vendor_date', date('Y-m-d'));
        $vendorDateDisplay = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $vendorDate)
            ? date('d-m-Y', strtotime($vendorDate))
            : (string) $vendorDate;

        $props = [
            'action' => route('workfile.assign'),
            'csrf' => csrf_token(),
            'cancelUrl' => route('workfile.index'),
            'vendorId' => old('vendor_id') ? (int) old('vendor_id') : '',
            'vendorDate' => $vendorDate,
            'vendorDateDisplay' => $vendorDateDisplay,
            'remark' => (string) old('remark'),
            'pickedFiles' => array_map('intval', (array) old('files', [])),
            'oldAmounts' => (object) (array) old('amounts', []),
            'vendors' => $vendors->map(fn ($vendor) => [
                'id' => (int) $vendor->id,
                'name' => $vendor->name,
                'mobile' => $vendor->mobile,
                'current_balance' => (float) $vendor->current_balance,
            ])->values(),
            'files' => $files->map(fn ($file) => [
                'id' => (int) $file->id,
                'file_no' => $file->file_no,
                'received_date' => date('d-m-Y', strtotime($file->received_date)),
                'work_type' => $file->workType?->name,
                // What this kind of work usually costs to have done, so
                // ticking the file fills the box in. Never the customer
                // charge: the gap between the two is the margin.
                'vendor_rate' => $file->workType?->default_vendor_rate === null
                    ? null
                    : (float) $file->workType->default_vendor_rate,
                'description' => $file->description,
                'customer' => $file->customer?->name,
                'customer_amount' => (float) $file->customer_amount,
            ])->values(),
        ];

        return Screen::make('admin.work.assign', 'vue-give-to-vendor', $props, [
            'fileCount' => $files->count(),
            // Whether any file has ever been received. An empty screen means two
            // different things and needs two different sentences.
            'anyFiles' => WorkFileModel::exists(),
            'vendorCount' => $vendors->count(),
        ])->toResponse($req);
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

        $files = WorkFileModel::returnableToCustomer();

        $props = [
            'action' => route('workfile.customerreturn'),
            'csrf' => csrf_token(),
            'cancelUrl' => route('workfile.index'),
            'returnedOn' => old('returned_on', date('Y-m-d')),
            'oldRemark' => old('remark', ''),
            'oldFiles' => array_values((array) old('files', [])),
            'oldAmounts' => (object) old('amounts', []),
            'files' => $files->map(fn ($file) => [
                'id' => $file->id,
                'file_no' => $file->file_no,
                'received_date' => date('d-m-Y', strtotime($file->received_date)),
                'work_type' => $file->workType?->name,
                'description' => $file->description,
                'customer' => $file->customer?->name,
                'status' => $file->status,
                'status_label' => $file->statusLabel(),
                'customer_amount' => (float) $file->customer_amount,
            ])->values(),
        ];

        return Screen::make('admin.work.customer-return', 'vue-customer-return', $props, [
            'fileCount' => $files->count(),
            'anyFiles' => WorkFileModel::exists(),
        ])->toResponse($req);
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

        $files = WorkFileModel::withVendor();

        $returnedOn = old('returned_on', date('Y-m-d'));
        $returnedOnDisplay = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $returnedOn)
            ? date('d-m-Y', strtotime($returnedOn))
            : (string) $returnedOn;

        $props = [
            'action' => route('workfile.vendorreturn'),
            'csrf' => csrf_token(),
            'cancelUrl' => route('workfile.index'),
            'returnedOn' => $returnedOn,
            'returnedOnDisplay' => $returnedOnDisplay,
            'remark' => old('remark', ''),
            // A bounced batch comes back ticked and filled in as it was sent.
            'pickedIds' => array_map('intval', (array) old('files', [])),
            'oldAmounts' => (object) (array) old('amounts', []),
            'files' => $files->map(fn ($file) => [
                'id' => $file->id,
                'file_no' => $file->file_no,
                'vendor' => $file->vendor?->name,
                'vendor_date' => $file->vendor_date ? date('d-m-Y', strtotime($file->vendor_date)) : null,
                'work_type' => $file->workType?->name,
                'description' => $file->description,
                'customer' => $file->customer?->name,
                'vendor_amount' => $file->vendor_amount === null ? null : (float) $file->vendor_amount,
            ])->values(),
        ];

        return Screen::make('admin.work.vendor-return', 'vue-vendor-return', $props, [
            'fileCount' => $files->count(),
            'anyFiles' => WorkFileModel::exists(),
        ])->toResponse($req);
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

        // Fetched for the whole board in one query rather than per row.
        $lastRemarks = WorkFileModel::latestRemarks($files->pluck('id')->all());

        /*
         * The field names are the ones status() already validates, so the form
         * still posts normally and the server still checks every value — which is
         * what made converting this screen on a live ledger safe: only the
         * rendering moved, the money logic did not.
         */
        $props = [
            'action' => route('workfile.status'),
            'csrf' => csrf_token(),
            'resetUrl' => route('workfile.status', array_filter(['status' => $filter, 'work_type' => $workTypeId])),
            'statuses' => WorkFileModel::STATUSES,
            'returnedKey' => WorkFileModel::RETURNED,
            'approvedKey' => WorkFileModel::APPROVED,
            'cancelledKey' => WorkFileModel::CANCELLED,
            'files' => $files->map(fn ($file) => [
                'id' => $file->id,
                'file_no' => $file->file_no,
                'received_date' => date('d-m-Y', strtotime($file->received_date)),
                'registration_no' => $file->registration_no,
                'work_type' => $file->workType?->name,
                'description' => $file->description,
                'customer' => $file->customer?->name,
                'vendor' => $file->vendor?->name,
                'customer_amount' => (float) $file->customer_amount,
                'returned_amount' => $file->returned_amount === null ? null : (float) $file->returned_amount,
                'status' => $file->status,
                'has_screenshot' => (bool) $file->approval_screenshot,
                'screenshot_url' => $file->screenshotUrl(),
                'edit_url' => route('workfile.edit', $file->id),
                'last_remark' => $lastRemarks[$file->id] ?? null,
            ])->values(),
        ];

        $statuses = WorkFileModel::STATUSES;

        return Screen::make('admin.work.status', 'vue-status-board', $props, [
            'filter' => $filter,
            'workTypeId' => $workTypeId ? (int) $workTypeId : null,
            'statuses' => $statuses,
            'statusCounts' => WorkFileModel::statusCounts($workTypeId),
            'workTypeCounts' => WorkFileModel::workTypeCounts($filter),
            'fileCount' => $files->count(),
            'anyFiles' => WorkFileModel::exists(),
            // 'open' and 'all' are tabs rather than stored statuses, so the tab
            // strip is assembled here rather than in the template.
            'tabs' => ['open' => 'In Hand'] + $statuses + ['all' => 'All'],
        ])->toResponse($req);
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

        $isEdit = (bool) $file;
        $timeline = $file->statusLog()->with('user')->get();
        // Whatever this file already points at stays selectable even if it
        // has since been deactivated, so an edit cannot silently reassign it.
        $workTypes = WorkTypeModel::selectList($file->work_type_id);
        $customers = PartyModel::selectList('customer', $file->customer_id);
        $vendors = PartyModel::selectList('vendor', $file->vendor_id);
        $statuses = WorkFileModel::STATUSES;
        $bag = session('errors');

        // Only ever reached for an existing file — receiving is its own screen.
        $action = $isEdit ? route('workfile.edit', $file->id) : route('workfile.receive');

        $props = [
            'action' => $action,
            'csrf' => csrf_token(),
            'indexUrl' => route('workfile.index'),
            'isEdit' => $isEdit,
            'statuses' => $statuses,

            /*
             * Option text is built here rather than in the component: what a
             * work type or a party is called on screen is the server's to
             * decide, and the rate and balance travel beside the label because
             * the panel needs them as numbers.
             */
            'workTypes' => $workTypes->map(fn ($wt) => [
                'id' => $wt->id,
                'label' => $wt->name
                    .($wt->default_rate !== null ? ' — '.number_format((float) $wt->default_rate, 2, '.', ',') : '')
                    .($wt->is_active ? '' : ' (retired)'),
                'rate' => $wt->default_rate,
            ])->values(),
            'customers' => $customers->map(fn ($c) => [
                'id' => $c->id,
                'label' => $c->name.' ('.$c->mobile.')'.($c->is_active ? '' : ' — inactive'),
                'balance' => (float) $c->current_balance,
            ])->values(),
            'vendors' => $vendors->map(fn ($v) => [
                'id' => $v->id,
                'label' => $v->name.' ('.$v->mobile.')'.($v->is_active ? '' : ' — inactive'),
                'balance' => (float) $v->current_balance,
            ])->values(),

            'values' => [
                'file_no' => old('file_no', $isEdit ? $file->file_no : ''),
                'status' => old('status', $isEdit ? $file->status : 'pending'),
                'returned_amount' => old('returned_amount', $isEdit ? $file->returned_amount : ''),
                'work_type_id' => old('work_type_id', $isEdit ? $file->work_type_id : ''),
                'registration_no' => old('registration_no', $isEdit ? $file->registration_no : ''),
                'description' => old('description', $isEdit ? $file->description : ''),
                'customer_id' => old('customer_id', $isEdit ? $file->customer_id : ''),
                'customer_amount' => old('customer_amount', $isEdit ? $file->customer_amount : ''),
                'vendor_id' => old('vendor_id', $isEdit ? $file->vendor_id : ''),
                'vendor_amount' => old('vendor_amount', $isEdit ? $file->vendor_amount : ''),
                'remarks' => old('remarks', $isEdit ? $file->remarks : ''),
            ],

            // Rendered here rather than rebuilt in the component: both date
            // boxes keep one markup contract, the one assets/js/datepicker.js
            // binds by class.
            'receivedDateField' => view('partials._datefield', [
                'name' => 'received_date',
                'value' => old('received_date', $isEdit ? $file->received_date : date('Y-m-d')),
                'required' => true,
            ])->render(),
            'vendorDateField' => view('partials._datefield', [
                'name' => 'vendor_date',
                'value' => old('vendor_date', $isEdit ? $file->vendor_date : ''),
            ])->render(),

            // A blank refund gives the whole charge back, so the box shows what
            // that would be rather than a bare zero.
            'refundPlaceholder' => $isEdit
                ? number_format((float) $file->customer_amount, 2, '.', '')
                : '0.00',
            'screenshotUrl' => $isEdit && $file->approval_screenshot ? $file->screenshotUrl() : '',

            /*
             * What this file already contributes to each party's balance.
             *
             * The balance carried on each option is the party's current one,
             * which for a file being edited already includes that file's own
             * entries. Adding the amount on top counted it twice, so the panel
             * promised a balance the statement would never show. Discount the
             * existing effect first — but only while the party is still the one
             * those entries were posted against.
             */
            'alreadyPosted' => [
                'customerId' => $isEdit ? $file->customer_id : null,
                'vendorId' => $isEdit ? $file->vendor_id : null,
                'customer' => $isEdit ? WorkFileModel::netCustomer($file->status, $file->customer_amount, $file->returned_amount) : 0,
                'vendor' => $isEdit ? WorkFileModel::netVendor($file->status, $file->vendor_amount, $file->vendor_returned_on !== null, $file->vendor_returned_amount) : 0,
            ],

            // Flattened here because the component cannot call a model's
            // methods; the labels and the date formatting stay the server's.
            'timeline' => $isEdit
                ? collect($timeline)->map(fn ($entry) => [
                    'id' => $entry->id,
                    'kind' => $entry->isOpening() ? 'opening' : ($entry->isNoteOnly() ? 'note' : 'move'),
                    'from' => $entry->fromLabel(),
                    'to' => $entry->toLabel(),
                    'remark' => $entry->remark,
                    'date' => date('d-m-Y', strtotime($entry->created_at)),
                    'time' => date('h:i A', strtotime($entry->created_at)),
                    'user' => $entry->user?->name,
                ])->values()
                : [],

            // The three statuses the form changes shape for, named by the model
            // rather than spelled out again here.
            'returnedKey' => WorkFileModel::RETURNED,
            'approvedKey' => WorkFileModel::APPROVED,
            'cancelledKey' => WorkFileModel::CANCELLED,

            'errors' => (object) array_map(fn ($messages) => $messages[0], ($bag ? $bag->messages() : [])),
            ];

        return Screen::make('admin.work.file-form', 'vue-file-form', $props, [
            'isEdit' => $isEdit,
            'fileNo' => $isEdit ? $file->file_no : null,
            // Nothing can be received without both a work type and a customer,
            // and the warning names whichever is missing.
            'blocked' => $workTypes->isEmpty() || $customers->isEmpty(),
            'noWorkTypes' => $workTypes->isEmpty(),
            'noCustomers' => $customers->isEmpty(),
        ])->toResponse($req);
    }
}
