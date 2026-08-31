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
     * Work that is through.
     *
     * Approved files are finished, and mixing them with work in hand means
     * reading past them every time. This is the same list filtered to them,
     * turned round: the day each work came through and the document it came
     * with, in place of a status column that would say "Approval Done" on
     * every row.
     *
     * All Work Files still holds everything — it is called All.
     */
    public function approved(Request $req)
    {
        $req->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'pending' => 'nullable|string',
        ]);

        $files = WorkFileModel::listing(
            WorkFileModel::APPROVED,
            $req->query('from'),
            $req->query('to'),
            $req->query('pending')
        );

        return $this->filesScreen($files, $req, [
            'base' => route('workfile.approved'),
            'heading' => 'Approved Files',
            'crumb' => 'Approved',
            'cardTitle' => 'Work Approved',
            'cardHint' => 'Every work here is through the RTO. The date is when it was approved, '
                .'and the link beside it is the document it came with.',
            'approvals' => true,
        ])->toResponse($req);
    }

    /**
     * What the files list shows, and what the grid is handed to show it.
     *
     * Built here rather than in the view because the same payload has to be
     * available as JSON: a route component fetches it, and there is no Blade in
     * that path. Describing it once is what keeps the page and the data from
     * drifting into disagreement.
     */
    private function filesScreen($files, Request $req, array $options = []): Screen
    {
        $base = $options['base'] ?? route('workfile.index');

        // Approved work is finished, so the day it came through and the
        // evidence are what a reader wants; on the list they are exported
        // rather than drawn, because there is no room and nothing pending
        // to weigh them against.
        $approvals = (bool) ($options['approvals'] ?? false);
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
        $unpricedCount = 0;
        $rows = [];

        /*
         * What each folder's works are doing. "Partly Approved" says they
         * disagree; the next question is always which, and this answers it
         * without opening the file. Fetched for the whole page in one query.
         */
        $breakdown = WorkFileModel::workBreakdown($files->pluck('id')->all());

        foreach ($files as $f) {
            // Decided once and read twice: a closed file is kept out of the
            // count above and greyed in the list below.
            $isClosed = in_array($f->status, [WorkFileModel::CANCELLED, WorkFileModel::RETURNED], true);

            if ($isClosed) {
                $closedCount++;
            }

            /*
             * Part refunds and vendor returns both change what a file really
             * earned and cost. Leaving those out made this page disagree with
             * the statements and the dashboard it is meant to summarise.
             *
             * Asked of rowTotals rather than worked out here, which is the
             * whole reason that method exists. This page did its own
             * subtraction and so answered differently: a file out with a
             * vendor at no agreed rate showed the entire charge as margin,
             * while the report beside it correctly left the cell empty.
             */
            $line = WorkFileModel::rowTotals($f);
            $netCustomer = $line['billed'];
            $netVendor = $line['cost'];

            $billed += $netCustomer;
            $cost += $netVendor;

            // Counted so the margin total can say what it is a total of.
            // Summing the rest as zero would quietly cover fewer files than
            // the two figures beside it.
            $unpricedCount += $line['margin'] === null ? 1 : 0;

            $split = WorkFileModel::workSplit($breakdown[$f->id] ?? []);
            $works = [
                'works_done' => $split['done'],
                'works_approved_on' => $split['approved_on'],
                'works_pending' => $split['pending'],
            ];

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

                // Null while a price is outstanding on any work: a difference
                // between a figure and a blank is not a margin.
                'margin' => $line['margin'],

                'status' => WorkFileModel::STATUSES[$f->status] ?? $f->status,
                // The badge colours itself from the raw key, not the label.
                'status_key' => $f->status,
                'works_note' => WorkFileModel::workNote($breakdown[$f->id] ?? []),

                /*
                 * The same answer as columns, for the export.
                 *
                 * A sentence in a status cell reads well and sorts and filters
                 * not at all. In a spreadsheet these are what someone wants:
                 * every file with a transfer still pending, everything approved
                 * last week. Kept off this screen, which has no room for three
                 * more columns and says it in the cell above instead.
                 */
                ...$works,
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
                ['key' => 'customer', 'label' => 'Customer', 'type' => 'link', 'linkTo' => 'customer_url'],
                // Debit green, credit red — the same two directions the rest of
                // the ledger uses, carried by the class the sheet already defines.
                ['key' => 'charged', 'label' => 'Charged', 'type' => 'money', 'class' => 'dr', 'sub' => 'charged_was'],
                ['key' => 'vendor', 'label' => 'Vendor', 'type' => 'link', 'linkTo' => 'vendor_url'],
                ['key' => 'cost', 'label' => 'Cost', 'type' => 'money', 'class' => 'cr', 'sub' => 'cost_was'],
                // A margin has a side: earned reads Dr, lost reads Cr, and neither
                // needs a minus sign to be read correctly.
                ['key' => 'margin', 'label' => 'Margin', 'type' => 'balance', 'class' => 'fw-bold'],
                /*
                 * Every row on the approved screen says the same status, so it
                 * is dropped there and the two columns that differ take its
                 * place: which works came through, and when.
                 */
                /*
                 * Every row on the approved screen says the same status, so it
                 * is dropped there and the works that came through take its
                 * place, carrying the evidence the status column carried.
                 * Which works, then when: the other way round reads backwards.
                 */
                $approvals
                    ? ['key' => 'works_done', 'label' => 'Approved Works',
                        'sub' => 'screenshot', 'subLinkTo' => 'screenshot_url', 'subPreview' => true]
                    : ['key' => 'status', 'label' => 'Status', 'type' => 'badge',
                        'note' => 'works_note', 'sub' => 'screenshot', 'subLinkTo' => 'screenshot_url',
                        // An image or a PDF, so it opens over the list.
                        'subPreview' => true],

                // Exported from both screens, drawn only where they answer the
                // question. See exportOnly in DataGrid, and workSplit().
                ['key' => 'works_done', 'label' => 'Approved Works', 'exportOnly' => true, 'hidden' => $approvals],
                ['key' => 'works_approved_on', 'label' => 'Approved On', 'exportOnly' => ! $approvals],
                ['key' => 'works_pending', 'label' => 'Pending Works', 'exportOnly' => true],
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
            'unpricedCount' => $unpricedCount,
            'fileCount' => count($rows),
            // 'open' is a view of several statuses rather than one of them, so
            // it has no entry in the stored list to look up.
            'statusLabel' => match (true) {
                ! $req->query('status') => null,
                $req->query('status') === 'open' => 'Work in hand',
                default => WorkFileModel::STATUSES[$req->query('status')] ?? $req->query('status'),
            },
            'base' => $base,
            'maxDate' => now()->toDateString(),

            // Both screens are this same list; only the words differ.
            'heading' => $options['heading'] ?? 'Work Files',
            'crumb' => $options['crumb'] ?? 'Work Files',
            'cardTitle' => $options['cardTitle'] ?? 'Files Received',
            'cardHint' => $options['cardHint'] ?? null,

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
                ->map(fn ($label, $key) => $base.'?pending='.$key)
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

            /*
             * One vehicle has one transfer and one hypothecation addition. A
             * file booked for the same work twice charges the customer twice
             * for one job, and no screen offers it — but a form can be sent by
             * hand, and this is where the money is decided.
             */
            $repeated = collect($req->input('rows'))
                ->map(function ($row, $index) {
                    $types = collect($row['works'] ?? [])->pluck('work_type_id')->filter();
                    $twice = $types->duplicates();

                    if ($twice->isEmpty()) {
                        return null;
                    }

                    $named = WorkTypeModel::whereIn('id', $twice->unique())->pluck('name')->implode(', ');

                    return 'file '.($index + 1).' ('.$named.')';
                })
                ->filter();

            if ($repeated->isNotEmpty()) {
                return back()->withInput()->with(
                    'error',
                    'A file cannot be received for the same work twice. Check: '.$repeated->implode(', ')
                );
            }

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

                    // Reloaded because the status above was written straight to
                    // the database; the copies in memory still say what they were.
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
            'cancelUrl' => route('workfile.index'),
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
                'amounts.*.numeric' => 'A vendor rate must be a number.',
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
                    $from = $file->status;

                    /*
                     * The rate is agreed per job, because the charge is. A folder
                     * holding a transfer and a hypothecation addition has two
                     * costs, and the folder's own figure is their sum — set here
                     * would be erased by the roll-up a moment later.
                     */
                    foreach ($file->items as $item) {
                        $amount = $amounts[$item->id] ?? null;
                        $item->vendor_amount = ($amount === null || $amount === '') ? null : (float) $amount;
                        $item->save();
                    }

                    $file->vendor_id = $req->vendor_id;
                    $file->vendor_date = $req->vendor_date;

                    // Handing a file over is the moment it leaves the office.
                    if ($file->status === WorkFileModel::IN_OFFICE) {
                        $file->status = WorkFileModel::DISPATCHED;
                        // The jobs move with the folder: the file leaving the office is true of
                        // every work on it, and the folder's own status is derived from
                        // theirs — left behind, they would roll it straight back.
                        /*
                         * Cancelled work is left where it is. It was struck off
                         * the folder and off the customer's statement, and moving
                         * it with the rest brought it back to life — the roll-up
                         * counts a work that is not cancelled, so the charge
                         * reappeared on a statement nobody had touched.
                         */
                        $file->items()
                            ->where('status', '<>', WorkFileModel::CANCELLED)
                            ->update(['status' => WorkFileModel::DISPATCHED]);
                    }

                    // Reloaded because the status above was written straight to
                    // the database; the copies in memory still say what they were.
                    $file->load('items');
                    $file->rollUp();
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
            /*
             * What each of these works has been paid before.
             *
             * A rate is agreed at a counter with the vendor waiting, and the
             * question is always what we paid last time. Sent with the screen
             * rather than fetched when asked: the answer is the same whichever
             * vendor is chosen, and a round trip at that moment is a pause in a
             * conversation.
             */
            'rateHistory' => WorkFileModel::recentVendorRates(
                $files->flatMap(fn ($file) => $file->items->map(fn ($item) => [
                    (int) $item->work_type_id,
                    WorkFileModel::rtoOf($file->registration_no),
                ]))->all()
            ),

            'vendors' => $vendors->map(fn ($vendor) => [
                'id' => (int) $vendor->id,
                'name' => $vendor->name,
                'mobile' => $vendor->mobile,
                'current_balance' => (float) $vendor->current_balance,
            ])->values(),
            'files' => $files->map(fn ($file) => [
                'id' => (int) $file->id,
                'file_no' => $file->file_no,
                // What the papers are for, read off the papers.
                'registration_no' => $file->registration_no,
                'received_date' => date('d-m-Y', strtotime($file->received_date)),
                'description' => $file->description,
                'customer' => $file->customer?->name,
                'customer_amount' => (float) $file->customer_amount,

                /*
                 * A folder is handed over whole, but the rate is agreed per job:
                 * a transfer and a hypothecation addition in one envelope are two
                 * charges and two costs.
                 */
                'items' => $file->items->map(fn ($item) => [
                    'id' => (int) $item->id,
                    'work_type_id' => (int) $item->work_type_id,
                    'work_type' => $item->workType?->name,
                    'customer_amount' => (float) $item->customer_amount,
                    // What this kind of work usually costs to have done, so
                    // ticking the file fills the box in. Never the customer
                    // charge: the gap between the two is the margin.
                    'vendor_rate' => $item->workType?->default_vendor_rate === null
                        ? null
                        : (float) $item->workType->default_vendor_rate,
                ])->values(),
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
                    // The jobs move with the folder: papers going back is true of
                    // every work on it, and the folder's own status is derived from
                    // theirs — left behind, they would roll it straight back.
                    // Except work already cancelled, which is struck off the
                    // folder: papers going back does not un-strike it, and
                    // moving it would put its charge back on the statement.
                    $file->items()
                        ->where('status', '<>', WorkFileModel::CANCELLED)
                        ->update(['status' => WorkFileModel::RETURNED]);
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
                'registration_no' => $file->registration_no,
                // Every work on the file, not the first of them: a folder
                // for a transfer and a hypothecation addition is both.
                'work_type' => $file->workLabel() ?: $file->workType?->name,
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
                    // cannot act on a file that has since finished with these papers.
                    ->whereNotIn('status', [WorkFileModel::CANCELLED, WorkFileModel::RETURNED])
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
                     * Back on our desk — the work that was still out, at least.
                     * Work already through the RTO stays approved: the papers
                     * moving does not undo what was done to them.
                     *
                     * The works move, not just the folder. Moving the folder
                     * alone left it saying In Office while every work on it
                     * still said File Dispatch, and the next roll-up read the
                     * works and flipped it back to claiming it was with the
                     * vendor.
                     *
                     * Nothing is forced on a file already returned to its
                     * customer or cancelled: doing that once rewrote such a
                     * file's status, and syncLedger withdrew the customer's
                     * refund and silently re-charged them in full.
                     */
                    $file->items()
                        ->whereIn('status', WorkFileModel::OPEN_STATUSES)
                        ->update(['status' => WorkFileModel::IN_OFFICE]);

                    $file->load('items');
                    $file->rollUp();
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
                'registration_no' => $file->registration_no,
                // Every work on the file, not the first of them: a folder
                // for a transfer and a hypothecation addition is both.
                'work_type' => $file->workLabel() ?: $file->workType?->name,
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
                // Keyed on the job, not the file. Papers for two works are one
                // folder with two jobs, and each is approved on its own.
                'statuses' => 'required|array|min:1',
                'statuses.*' => ['required', Rule::in(array_keys(WorkFileModel::JOB_STATUSES))],
                'remarks' => 'nullable|array',
                'remarks.*' => 'nullable|string|max:255',
                'screenshots' => 'nullable|array',
                'screenshots.*' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:4096',

                /*
                 * The day the RTO approved it, which is not always the day
                 * someone got round to recording it. A future date is not an
                 * approval that has happened.
                 */
                'approved_on' => 'nullable|array',
                'approved_on.*' => 'nullable|date_format:Y-m-d|before_or_equal:today',
            ], [
                'approved_on.*.date_format' => 'An approval date must be a real date.',
                'approved_on.*.before_or_equal' => 'An approval cannot be dated in the future.',
            ]);

            $wanted = $req->input('statuses');
            $remarks = $req->input('remarks', []);
            $uploads = $req->file('screenshots', []);
            $approvedOn = $req->input('approved_on', []);

            $items = WorkFileItemModel::with('file', 'workType')
                ->whereIn('id', array_keys($wanted))
                ->get();

            $name = fn ($item) => $item->file->file_no.' · '.($item->workType?->name ?? 'work');

            /*
             * Approval has to be evidenced, and now per job: a hypothecation
             * addition and a transfer are approved separately, days apart, each
             * with its own document. Refuse the whole save and name what is
             * missing rather than storing a bare claim.
             */
            $missing = $items
                ->filter(fn ($item) => $wanted[$item->id] === WorkFileModel::APPROVED
                    && ! isset($uploads[$item->id])
                    && ! $item->approval_screenshot)
                ->map($name);

            if ($missing->isNotEmpty()) {
                return back()->withInput()->with(
                    'error',
                    'Approval Done needs a screenshot. Attach one for: '.$missing->implode(', ')
                );
            }

            /*
             * An approval is a thing that happened on a day, and which day is
             * the whole question when two of them arrive a week apart. So it
             * is asked for rather than assumed to be today: work approved on
             * Friday and entered on Monday would otherwise be recorded wrong,
             * and the file's history is what settles arguments later.
             */
            $undated = $items
                ->filter(fn ($item) => $wanted[$item->id] === WorkFileModel::APPROVED
                    && trim((string) ($approvedOn[$item->id] ?? '')) === ''
                    && ! $item->approved_on)
                ->map($name);

            if ($undated->isNotEmpty()) {
                return back()->withInput()->with(
                    'error',
                    'Approval Done needs the date it was approved. Add one for: '.$undated->implode(', ')
                );
            }

            // Papers cannot be approved before they were taken in.
            $tooEarly = $items
                ->filter(function ($item) use ($wanted, $approvedOn) {
                    $date = trim((string) ($approvedOn[$item->id] ?? ''));

                    return $wanted[$item->id] === WorkFileModel::APPROVED
                        && $date !== ''
                        && $date < $item->file->received_date;
                })
                ->map(fn ($item) => $name($item).' (received '.date('d-m-Y', strtotime($item->file->received_date)).')');

            if ($tooEarly->isNotEmpty()) {
                return back()->withInput()->with(
                    'error',
                    'An approval cannot be dated before the papers came in. Check: '.$tooEarly->implode(', ')
                );
            }

            /*
             * Papers go back in one envelope. A folder holding two works cannot
             * send one of them home — and left half returned it would bill the
             * customer for papers they are holding. The return screen takes the
             * whole folder and shows the refund, so it is sent there.
             */
            $partReturn = $items
                ->filter(fn ($item) => $wanted[$item->id] === WorkFileModel::RETURNED
                    && $item->status !== WorkFileModel::RETURNED
                    && $item->file->items()->count() > 1)
                ->map(fn ($item) => $item->file->file_no)
                ->unique();

            if ($partReturn->isNotEmpty()) {
                return back()->withInput()->with(
                    'error',
                    'Papers go back a whole file at a time. Use Return to Customer for: '.$partReturn->implode(', ')
                );
            }

            // Cancelling strikes work off the folder and returning gives its
            // charge back. Both move the customer's balance, so both say why.
            $unexplained = $items
                ->filter(function ($item) use ($wanted, $remarks) {
                    return $item->status !== $wanted[$item->id]
                        && in_array($wanted[$item->id], [WorkFileModel::CANCELLED, WorkFileModel::RETURNED], true)
                        && trim((string) ($remarks[$item->id] ?? '')) === '';
                })
                ->map($name);

            if ($unexplained->isNotEmpty()) {
                return back()->withInput()->with(
                    'error',
                    'Cancelling work changes the customer\'s balance, so it needs a reason. Add a remark for: '.$unexplained->implode(', ')
                );
            }

            $changed = DB::transaction(function () use ($items, $wanted, $remarks, $uploads, $approvedOn) {
                $files = [];
                $notes = [];
                $moved = 0;

                foreach ($items as $item) {
                    $upload = $uploads[$item->id] ?? null;
                    $remark = trim((string) ($remarks[$item->id] ?? '')) ?: null;
                    $from = $item->status;
                    $movedThis = $from !== $wanted[$item->id];

                    // A remark on its own is worth saving: it records chasing the
                    // RTO about one job without that job moving.
                    if (! $movedThis && ! $upload && ! $remark) {
                        continue;
                    }

                    if ($upload) {
                        // Named after the file it evidences, never after the
                        // upload, whose name is attacker-controlled. Traceable to
                        // the folder if it is ever looked at on disk.
                        $item->approval_screenshot = WorkFileModel::storeUpload(
                            $upload,
                            $item->approval_screenshot,
                            $item->file->file_no
                        );
                    }

                    $item->status = $wanted[$item->id];

                    /*
                     * Set before the save, because the model stamps today's
                     * date on an approval that arrives without one — that is
                     * the backstop for older files, not the answer here.
                     */
                    $date = trim((string) ($approvedOn[$item->id] ?? ''));

                    if ($date !== '' && $item->isApproved()) {
                        $item->approved_on = $date;
                    }

                    $item->save();

                    if ($movedThis) {
                        $moved++;
                    }

                    $file = $item->file;

                    // The folder's status before any of its jobs moved, so the
                    // timeline can say where it came from even when two jobs
                    // change in the same save.
                    $files[$file->id] ??= ['file' => $file, 'from' => $file->status];

                    /*
                     * The remark is what a person typed, kept verbatim: it is what
                     * the work report prints under Remarks, and a machine-written
                     * prefix would turn that column into noise. Which job it is
                     * about is recorded beside it instead.
                     */
                    $notes[$file->id][] = ['item' => $item->id, 'remark' => $remark];
                }

                /*
                 * The folder follows its jobs, and is written once after all of
                 * them have moved — a file whose two jobs both changed must not
                 * be saved twice with an answer that was only true in between.
                 *
                 * The timeline comes after the roll-up for the same reason: an
                 * entry written first would record the status the folder was
                 * leaving as the one it arrived at.
                 */
                foreach ($files as ['file' => $file, 'from' => $from]) {
                    // Reloaded because the status above was written straight to
                    // the database; the copies in memory still say what they were.
                    $file->load('items');
                    $file->rollUp();
                    $file->save();
                    $file->syncLedger();

                    foreach ($notes[$file->id] as $note) {
                        $file->logStatus($from, $note['remark'], $note['item']);
                    }
                }

                return ['items' => $moved, 'files' => count($files)];
            });
            if (! $changed['files']) {
                return back()->with('error', 'Nothing was changed.');
            }

            return redirect()->route('workfile.status', array_filter([
                'status' => $req->query('status'),
                'work_type' => $req->query('work_type'),
            ]))->with('success', $changed['items']
                ? $changed['items'].' '.Str::plural('work', $changed['items']).' updated on '.$changed['files'].' '.Str::plural('file', $changed['files']).'.'
                : 'Remarks saved.');
        }
        $filter = $req->query('status', 'open');

        // 'open' and 'all' are tabs rather than stored statuses, so they cannot
        // be validated by Rule::in against the status list.
        if ($filter !== 'open' && $filter !== 'all' && ! array_key_exists($filter, WorkFileModel::STATUSES)) {
            $filter = 'open';
        }

        $workTypeId = $req->query('work_type');

        /*
         * Who is holding the papers. 'none' is a deliberate choice of the work
         * kept in-house, which a blank could not tell apart from no filter.
         */
        $vendorId = $req->query('vendor');
        $files = WorkFileModel::forStatusBoard($filter, $workTypeId, $vendorId);

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
            'resetUrl' => route('workfile.status', array_filter(['status' => $filter, 'work_type' => $workTypeId, 'vendor' => $vendorId])),
            // Only what a single job can be put into. Returning papers is agreed
            // for a folder and has its own screen; partly approved describes a
            // folder whose jobs disagree, and one job never disagrees with itself.
            'statuses' => WorkFileModel::JOB_STATUSES,
            // Also offered per folder below, because a folder of several works
            // cannot send one of them home. See jobStatusesFor().

            // Today, from the server: an approval cannot be dated after it, and
            // the browser's own clock is not the one the ledger is kept by.
            'today' => date('Y-m-d'),

            'approvedKey' => WorkFileModel::APPROVED,
            'cancelledKey' => WorkFileModel::CANCELLED,
            'files' => $files->map(fn ($file) => [
                'id' => $file->id,
                'file_no' => $file->file_no,
                'received_date' => date('d-m-Y', strtotime($file->received_date)),
                'registration_no' => $file->registration_no,
                'description' => $file->description,
                'customer' => $file->customer?->name,
                'vendor' => $file->vendor?->name,
                'customer_amount' => (float) $file->customer_amount,
                // The folder's own state, derived from the jobs below it. Shown,
                // never chosen: it is an answer, not a question.
                'status' => $file->status,
                'status_label' => WorkFileModel::STATUSES[$file->status] ?? $file->status,
                'edit_url' => route('workfile.edit', $file->id),
                'last_remark' => $lastRemarks[$file->id] ?? null,
                'statuses' => WorkFileModel::jobStatusesFor($file->items->count()),

                // How much of the folder is finished, since the board only
                // lists what is left of it.
                'works' => $file->items->count(),
                'settled' => $file->items->filter(fn ($item) => $item->isSettled())->count(),

                /*
                 * The jobs. Each is approved on its own, days apart, with its own
                 * evidence — which is the whole reason the board moved onto them.
                 *
                 * On the 'in hand' view only the work still in hand is listed. Work
                 * that is through is done with: leaving it on the board asked the
                 * operator to read past a finished job every time they came back to
                 * the one that was not, on a screen whose whole purpose is what is
                 * still outstanding. Every other tab shows the whole folder.
                 */
                'items' => $file->items
                    ->filter(fn ($item) => $filter !== 'open' || ! $item->isSettled())
                    ->map(fn ($item) => [
                        'id' => $item->id,
                        'work_type' => $item->workType?->name,
                        'customer_amount' => (float) $item->customer_amount,
                        'status' => $item->status,
                        'has_screenshot' => (bool) $item->approval_screenshot,
                        'screenshot_url' => $item->approval_screenshot ? url($item->approval_screenshot) : null,
                        'approved_on' => $item->approved_on ? date('d-m-Y', strtotime($item->approved_on)) : null,
                        // The box is filled with today, which is right far more
                        // often than it is wrong, and can be typed over.
                        'approved_on_value' => $item->approved_on
                            ? date('Y-m-d', strtotime($item->approved_on))
                            : date('Y-m-d'),
                    ])->values(),
            ])->values(),
        ];
        $statuses = WorkFileModel::STATUSES;

        return Screen::make('admin.work.status', 'vue-status-board', $props, [
            'filter' => $filter,
            'workTypeId' => $workTypeId ? (int) $workTypeId : null,
            'statuses' => $statuses,
            'statusCounts' => WorkFileModel::statusCounts($workTypeId, $vendorId),
            'workTypeCounts' => WorkFileModel::workTypeCounts($filter, $vendorId),
            'vendorCounts' => WorkFileModel::vendorCounts($filter, $workTypeId),
            'vendorId' => $vendorId,
            'inHouseKey' => WorkFileModel::IN_HOUSE,
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

                /*
                 * A folder holding several works is corrected work by work:
                 * its own type, charge and cost are the sum of theirs and are
                 * rolled up again below, so there is nothing to type into the
                 * boxes above. A folder holding one is that one work, and the
                 * boxes still write straight through to it.
                 */
                'items' => 'nullable|array',
                'items.*.work_type_id' => 'required|integer|exists:work_type,id',
                'items.*.customer_amount' => 'required|numeric|gte:0|max:99999999',
                'items.*.vendor_amount' => 'nullable|numeric|gte:0|max:99999999',

                /*
                 * Work added to a file that already exists: papers turning up
                 * later for another job on the same vehicle.
                 */
                'new_works' => 'nullable|array',
                'new_works.*.work_type_id' => 'required|integer|exists:work_type,id',
                'new_works.*.amount' => 'required|numeric|gte:0|max:99999999',
                'new_works.*.vendor_amount' => 'nullable|numeric|gte:0|max:99999999',

                // Work being taken off the file, by id.
                'remove_works' => 'nullable|array',
                'remove_works.*' => 'integer',
            ], [
                'vendor_id.required_with' => 'Select the vendor this file was given to before entering a vendor amount.',
                'items.*.work_type_id.required' => 'Every work on the file needs a type.',
                'items.*.customer_amount.required' => 'Every work on the file needs a charge.',
                'new_works.*.work_type_id.required' => 'Every work added needs a type.',
                'new_works.*.amount.required' => 'Every work added needs a charge.',
            ]);

            $adding = collect($req->input('new_works', []))
                ->filter(fn ($work) => ! empty($work['work_type_id']));

            if ($adding->isNotEmpty()) {
                /*
                 * A file that is approved, returned or cancelled is finished.
                 * Papers arriving after that are a new file, not a fourth work
                 * on one that has already been settled and billed.
                 */
                if ($file->isSettled()) {
                    return back()->withInput()->with(
                        'error',
                        'This file is '.strtolower(WorkFileModel::STATUSES[$file->status] ?? $file->status)
                            .', so no more work can be added to it. Receive the papers as a new file.'
                    );
                }

                // The rule the whole file lives by: one vehicle has one
                // transfer. Checked against what it already holds as well as
                // against the rest of what is being added.
                $already = $file->items()->pluck('work_type_id');
                $wanted = $adding->pluck('work_type_id');
                $twice = $wanted->duplicates()->merge($wanted->intersect($already))->unique();

                if ($twice->isNotEmpty()) {
                    $named = WorkTypeModel::whereIn('id', $twice)->pluck('name')->implode(', ');

                    return back()->withInput()->with(
                        'error',
                        'A file cannot be for the same work twice. It already has: '.$named
                    );
                }
            }

            $removing = $file->items()
                ->whereIn('id', (array) $req->input('remove_works', []))
                ->with('workType')
                ->get();

            if ($removing->isNotEmpty()) {
                /*
                 * An approved work is a record of something that happened at
                 * the RTO, with a date and a document behind it. Striking it
                 * off is cancelling, on the board, where it keeps both.
                 */
                $through = $removing->filter(fn ($item) => $item->isApproved())
                    ->map(fn ($item) => $item->workType?->name ?? 'a work');

                if ($through->isNotEmpty()) {
                    return back()->withInput()->with(
                        'error',
                        'Work that is approved cannot be taken off the file — it has a date and a document '
                            .'behind it. Cancel it on the status board instead. Check: '.$through->implode(', ')
                    );
                }

                // A file is for at least one work, counting whatever is being
                // added in the same save.
                $adding = collect($req->input('new_works', []))
                    ->filter(fn ($work) => ! empty($work['work_type_id']))
                    ->count();

                if ($file->items()->count() - $removing->count() + $adding < 1) {
                    return back()->withInput()->with(
                        'error',
                        'A file has to be for at least one work. Add another before taking the last one off, '
                            .'or cancel the whole file.'
                    );
                }
            }

            /*
             * The same rule when a work is corrected: retyping one folder's
             * transfer as a hypothecation addition, where it already has one,
             * would leave it charging twice for the same job.
             */
            $corrections = collect($req->input('items', []));

            if ($corrections->isNotEmpty()) {
                $twice = $corrections->pluck('work_type_id')->filter()->duplicates();

                if ($twice->isNotEmpty()) {
                    $named = WorkTypeModel::whereIn('id', $twice->unique())->pluck('name')->implode(', ');

                    return back()->withInput()->with(
                        'error',
                        'A file cannot be for the same work twice. It already has: '.$named
                    );
                }
            }

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

            DB::transaction(function () use ($file, $req, $removing) {
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

                /*
                 * The jobs are the record of what the file is for, so a
                 * correction made here has to reach them or the two disagree.
                 *
                 * Only for a folder holding exactly one job. A folder with
                 * several has no single type, price or status to correct from
                 * one set of boxes, and the board is where those are moved —
                 * so this screen leaves them alone rather than flattening them.
                 */
                $items = $file->items()->get();

                if ($items->count() === 1) {
                    $item = $items->first();
                    $item->work_type_id = $file->work_type_id;
                    $item->customer_amount = $file->customer_amount;
                    $item->vendor_amount = $file->vendor_amount;
                    $item->status = $file->status;
                    $item->save();
                }

                /*
                 * Corrections made against each work on a folder that holds
                 * several. Keyed on the job and matched against this file's
                 * own jobs, so a tampered form cannot reprice a work sitting
                 * on somebody else's file.
                 *
                 * The status is not among them: a work moves on the board,
                 * where the approval evidence goes with it.
                 */
                $corrections = $req->input('items', []);

                if ($corrections && $items->count() > 1) {
                    foreach ($items as $item) {
                        $correction = $corrections[$item->id] ?? null;

                        if (! $correction) {
                            continue;
                        }

                        $item->work_type_id = (int) $correction['work_type_id'];
                        $item->customer_amount = (float) $correction['customer_amount'];
                        $item->vendor_amount = ($correction['vendor_amount'] ?? '') === ''
                            ? null
                            : (float) $correction['vendor_amount'];
                        $item->save();
                    }
                }

                /*
                 * Work added to the file. After the corrections, so the boxes
                 * above still describe the work that was already there — and
                 * a single-work file keeps its write-through, which is decided
                 * from the count taken before any of this.
                 *
                 * It starts in the office. A folder already with a vendor gains
                 * work that has not gone anywhere, and the roll-up saying so is
                 * the truth: part of this file is back on the desk.
                 */
                $added = 0;

                foreach ($req->input('new_works', []) as $work) {
                    if (empty($work['work_type_id'])) {
                        continue;
                    }

                    $item = new WorkFileItemModel;
                    $item->work_file_id = $file->id;
                    $item->work_type_id = (int) $work['work_type_id'];
                    $item->customer_amount = (float) $work['amount'];
                    $item->vendor_amount = ($work['vendor_amount'] ?? '') === ''
                        ? null
                        : (float) $work['vendor_amount'];
                    $item->status = 'in_office';
                    $item->save();

                    $added++;
                }

                /*
                 * And work taken off it. After the additions, so one work can
                 * be swapped for another in a single save whichever way round
                 * the operator did it.
                 */
                $removed = $removing->isEmpty()
                    ? 0
                    : $file->items()->whereIn('id', $removing->pluck('id'))->delete();

                /*
                 * The folder is the sum of its works, so it is written from
                 * them rather than from the boxes above — whenever any of them
                 * moved.
                 */
                if ($added || $removed || ($corrections && $items->count() > 1)) {
                    $file->load('items');
                    $file->rollUp();
                    $file->save();
                }

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
             * The works this file is for.
             *
             * Each carries its own approval and the document it arrived with,
             * because two approvals days apart are two documents — and the
             * list upstairs can only link to one of them. This is where the
             * whole record of a folder is, so this is where they all hang.
             */
            'items' => $isEdit
                ? $file->items()->with('workType')->get()->map(fn ($item) => [
                    'id' => (int) $item->id,
                    'work_type_id' => (int) $item->work_type_id,
                    'work_type' => $item->workType?->name,
                    'customer_amount' => (float) $item->customer_amount,
                    'vendor_amount' => $item->vendor_amount === null ? '' : (float) $item->vendor_amount,
                    'status' => $item->status,
                    'status_label' => WorkFileModel::STATUSES[$item->status] ?? $item->status,
                    'screenshot_url' => $item->approval_screenshot ? url($item->approval_screenshot) : null,
                    'approved_on' => $item->approved_on ? date('d-m-Y', strtotime($item->approved_on)) : null,
                ])->values()
                : [],

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
