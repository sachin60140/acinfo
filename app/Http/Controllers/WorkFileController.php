<?php

namespace App\Http\Controllers;

use App\Models\ExpenseTypeModel;
use App\Models\PartyModel;
use App\Models\WorkFileDocumentModel;
use App\Models\WorkFileExpenseModel;
use App\Models\WorkFileItemModel;
use App\Models\WorkFileModel;
use App\Models\WorkFilePaperModel;
use App\Models\WorkTypeModel;
use App\Support\Screen;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
            'status' => ['nullable', Rule::in(array_merge(
                array_keys(WorkFileModel::STATUSES),
                ['open', WorkFileModel::AWAITING_HANDOVER, WorkFileModel::AWAITING_AUDIT, WorkFileModel::PAPERS_PENDING]
            ))],
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
            // The office's own share of that cost, kept apart so the column
            // below can say what the vendor was agreed at.
            $expenses = $line['expenses'];

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

                // The day it went to the vendor, and how long it has been there.
                'dispatched' => $f->vendor_date ? date('d-m-Y', strtotime($f->vendor_date)) : null,
                // Sorted on rather than shown, for the reason 'received' is.
                'dispatched_raw' => $f->vendor_date ? date('Y-m-d', strtotime($f->vendor_date)) : null,
                'days_out' => WorkFileModel::daysOutText($f->vendor_date, $f->status, $f->finished_on),
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

                // "2 vendors" where the folder is split between them: with no
                // vendor of its own it would otherwise read as work nobody was given.
                'vendor' => $f->vendor_label ?? 'In-house',
                'vendor_url' => $f->vendor_id ? route('party.statement', $f->vendor_id) : null,

                /*
                 * An in-house file has no vendor and so has no vendor cost;
                 * 0.00 states one that was never incurred, and netVendor()
                 * returns 0.0 for a null amount, so the cell is left blank for
                 * exactly that row.
                 *
                 * Unless the office paid something out on it. A file done
                 * in-house with a challan against it cost that challan, and
                 * blanking the cell would hide a figure that is in the margin
                 * beside it — which is how this column briefly read when
                 * expenses were first folded into the cost.
                 */
                'cost' => ($f->vendor_amount === null && $expenses <= 0) ? null : $netVendor,

                /*
                 * What the vendor was agreed at, when the cost no longer
                 * matches it. Compared against the vendor's own share and not
                 * the total: expenses are not a change to what the vendor
                 * charged, and counting them here put "was 3,500.00" under
                 * every file that had ever had a challan.
                 */
                'cost_was' => abs(($netVendor - $expenses) - (float) $f->vendor_amount) > 0.005
                    ? 'was '.number_format((float) $f->vendor_amount, 2, '.', ',')
                    : null,

                // What the office paid out of its own till on this file.
                'expenses' => $expenses > 0 ? $expenses : null,

                // Null while a price is outstanding on any work: a difference
                // between a figure and a blank is not a margin.
                'margin' => $line['margin'],

                'status' => WorkFileModel::STATUSES[$f->status] ?? $f->status,
                // The badge colours itself from the raw key, not the label.
                'status_key' => $f->status,
                // Which works are through, and — once they all are — whether the
                // papers have gone back, which is the next thing asked of a file
                // that is finished.
                'works_note' => implode(' · ', array_filter([
                    WorkFileModel::workNote($breakdown[$f->id] ?? []),
                    // What the Details column used to be pressed into saying.
                    $f->pending_papers ? 'Papers pending: '.$f->pending_papers : null,
                    $f->needs_audit && in_array($f->status, [WorkFileModel::IN_OFFICE, WorkFileModel::PAPER_PENDENCY], true)
                        ? 'Papers to check' : null,
                    WorkFileModel::handoverText($f->handed_over_on),
                ])) ?: null,
                'pending_papers' => $f->pending_papers ?: null,
                'handed_over' => $f->handed_over_on ? date('d-m-Y', strtotime($f->handed_over_on)) : null,

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
                'screenshot_url' => $f->approval_screenshot ? route('workfile.approval', $f->id) : null,

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
            'totals' => ['charged' => 'sum', 'cost' => 'sum', 'expenses' => 'sum', 'margin' => 'sum'],
            'rowClass' => 'row_class',
            /*
             * What else this list can say, offered rather than shown.
             *
             * Fourteen columns answered every question anybody had ever asked of
             * this screen at once, which meant it answered none of them well:
             * the seven that say which file this is and where it has got to were
             * read past a wall of figures nobody was looking at that morning.
             *
             * So those seven stand, and the rest are grouped by the question
             * they answer and turned on when it is being asked. Nothing is lost
             * — every column still goes into every export, whichever bands are
             * open — and the choice is remembered per browser, so an office that
             * always wants the money can turn it on once.
             */
            'groups' => [
                ['key' => 'dispatch', 'label' => 'Vendor & dispatch'],
                ['key' => 'money', 'label' => 'Cost & margin'],
                ['key' => 'detail', 'label' => 'Details'],
            ],
            'columns' => [
                ['key' => 'file_no', 'label' => 'File No.', 'type' => 'link', 'linkTo' => 'edit_url'],
                ['key' => 'registration_no', 'label' => 'Vehicle'],
                // Shown dd-mm-yyyy, sorted on the raw Y-m-d each row also carries,
                // so oldest-first and newest-first both mean what they say.
                ['key' => 'received', 'label' => 'Received', 'sortBy' => 'received_raw'],
                /*
                 * When it went to the vendor, with how long it has been there
                 * beneath it. Newest first on the first click: what a reader
                 * asks of a dispatch date is what went out lately.
                 */
                ['key' => 'dispatched', 'label' => 'Dispatched', 'sortBy' => 'dispatched_raw',
                    'sortDesc' => true, 'sub' => 'days_out', 'group' => 'dispatch'],
                ['key' => 'work_type', 'label' => 'Work Type'],
                ['key' => 'description', 'label' => 'Details', 'group' => 'detail'],
                // A party statement is opened to be read against this list, and
                // this list is behind a status and date filter — taking the tab
                // with it means setting the filter again to come back.
                ['key' => 'customer', 'label' => 'Customer', 'type' => 'link', 'linkTo' => 'customer_url'],
                // Debit green, credit red — the same two directions the rest of
                // the ledger uses, carried by the class the sheet already defines.
                ['key' => 'charged', 'label' => 'Charged', 'type' => 'money', 'class' => 'dr', 'sub' => 'charged_was'],
                ['key' => 'vendor', 'label' => 'Vendor', 'type' => 'link', 'linkTo' => 'vendor_url',
                    'group' => 'dispatch'],
                ['key' => 'cost', 'label' => 'Cost', 'type' => 'money', 'class' => 'cr', 'sub' => 'cost_was',
                    'group' => 'money'],
                // What the office paid out of its own till, included in the
                // Cost beside it and broken out so a margin can be read.
                ['key' => 'expenses', 'label' => 'Expenses', 'type' => 'money', 'class' => 'cr',
                    'group' => 'money'],
                // A margin has a side: earned reads Dr, lost reads Cr, and neither
                // needs a minus sign to be read correctly.
                ['key' => 'margin', 'label' => 'Margin', 'type' => 'balance', 'class' => 'fw-bold',
                    'group' => 'money'],
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
                /*
                 * Drawn on the approved screen, where every file is finished and
                 * whether its papers have gone back is the question left. On the
                 * full list it is said in the status cell and exported here.
                 */
                ['key' => 'handed_over', 'label' => 'Handed Over', 'exportOnly' => ! $approvals],
                // A column a spreadsheet can filter on; on screen it is said in the status cell.
                ['key' => 'pending_papers', 'label' => 'Papers Pending', 'exportOnly' => true],
                /*
                 * No Action column. It held the word "Edit" on every row and
                 * went to the same place the file number already goes — a whole
                 * column, and the rightmost one on the widest table in the
                 * application, spent repeating a link that was already there.
                 */
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
                // Ticked by the operator when the same work really is coming in
                // again on purpose. See the check below.
                'rows.*.duplicate_ok' => 'nullable|boolean',
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

            /*
             * The same vehicle and the same work, twice in one batch. Always a
             * slip: two envelopes for one job, charged twice, sent twice.
             */
            $seen = [];
            $twiceHere = [];

            foreach ($req->input('rows') as $index => $row) {
                $plate = WorkFileModel::normaliseRegistration($row['registration_no'] ?? null);

                if ($plate === '') {
                    continue;
                }

                foreach ($row['works'] ?? [] as $work) {
                    $key = $plate.'|'.($work['work_type_id'] ?? '');

                    if (isset($seen[$key])) {
                        $twiceHere[] = $plate.' ('.(WorkTypeModel::whereKey($work['work_type_id'])->value('name') ?? 'work')
                            .') on files '.($seen[$key] + 1).' and '.($index + 1);
                    }

                    $seen[$key] ??= $index;
                }
            }

            if ($twiceHere) {
                return back()->withInput()->with(
                    'error',
                    'The same vehicle and work are on this page twice: '.implode('; ', array_unique($twiceHere))
                );
            }

            /*
             * And the same work already in hand from before — the counter's
             * real mistake, which is the same papers entered a second time.
             * Refused unless the row says it is deliberate: work finished long
             * ago does not count, so what is left is a file already open for
             * this vehicle and this job.
             */
            $inHand = [];

            foreach ($req->input('rows') as $index => $row) {
                if (filter_var($row['duplicate_ok'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    continue;
                }

                $clashes = WorkFileModel::workAlreadyInHand(
                    $row['registration_no'] ?? null,
                    collect($row['works'] ?? [])->pluck('work_type_id')->all()
                );

                foreach ($clashes as $clash) {
                    $inHand[] = WorkFileModel::normaliseRegistration($row['registration_no'] ?? null)
                        .' — '.$clash->work_type.' is already in hand on '.$clash->file_no
                        .' ('.(WorkFileModel::STATUSES[$clash->status] ?? $clash->status).')';
                }
            }

            if ($inHand) {
                return back()->withInput()->with(
                    'error',
                    'This work is already open for this vehicle: '.implode('; ', array_unique($inHand))
                        .'. Tick "take it in anyway" on that file if it really is coming in again.'
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
            // What this customer has paid before, looked up when one is chosen.
            'ratesUrl' => route('api.workfile.customerrates'),
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
     * Hand a batch of work to one vendor.
     *
     * By the work, not by the folder. A folder holding a transfer and a
     * hypothecation addition need not send both to the same person: the ticks
     * are per job, and a folder half of which has gone comes back to this
     * screen for the other half.
     *
     * Only work nobody has yet is offered. Moving work that is already with
     * someone is a correction, and belongs on the edit screen where the
     * consequence for the first vendor's balance is visible.
     */
    public function assign(Request $req)
    {
        if ($req->isMethod('POST')) {
            $req->validate([
                'vendor_id' => ['required', 'integer', Rule::exists('party', 'id')->where('party_type', 'vendor')],
                'vendor_date' => 'required|date_format:Y-m-d',
                'files' => 'required|array|min:1',
                'files.*' => 'integer',
                /*
                 * The works going out, by job id. Left out, the whole folder
                 * goes — which is the ordinary handover and stays one tick.
                 */
                'jobs' => 'nullable|array',
                'jobs.*' => 'integer',
                'amounts' => 'nullable|array',
                'amounts.*' => 'nullable|numeric|gte:0|max:99999999',
                'remark' => 'nullable|string|max:200',
                // Why a file goes out with its papers not complete, by file id.
                'overrides' => 'nullable|array',
                'overrides.*' => 'nullable|string|max:200',
            ], [
                'files.required' => 'Tick at least one file to give to the vendor.',
                'amounts.*.numeric' => 'A vendor rate must be a number.',
            ]);

            $amounts = $req->input('amounts', []);

            /*
             * Step 3 follows step 2: a file goes to a vendor once its papers are
             * checked and complete. Sometimes the RTO will take a paper later,
             * so it can still go — with a reason, which is kept on the file's
             * history. Asked of the database now, not of the page.
             */
            $overrides = collect((array) $req->input('overrides', []))
                ->map(fn ($reason) => trim((string) $reason))
                ->filter(fn ($reason) => $reason !== '');

            /*
             * Asked of the works going out, not of the folder.
             *
             * A folder can hold a transfer whose papers are complete and a
             * hypothecation addition still waiting on a form. Sending the
             * transfer is not sending the form out incomplete, and stopping it
             * because of the other work is the kind of refusal people learn to
             * click past.
             */
            $jobs = array_map('intval', (array) $req->input('jobs', []));

            $notReady = WorkFileModel::papersNotReady(array_map('intval', (array) $req->input('files')), $jobs);

            $unexplained = collect($notReady)->reject(fn ($why, $id) => $overrides->has($id));

            if ($unexplained->isNotEmpty()) {
                $names = WorkFileModel::whereIn('id', $unexplained->keys()->all())->pluck('file_no', 'id');

                return back()->withInput()->with(
                    'error',
                    'These files\' papers are not ready. Complete them on Paper Audit, or give a reason to send them anyway: '
                        .$unexplained->map(fn ($why, $id) => ($names[$id] ?? '#'.$id).' ('.$why.')')->implode(', ')
                );
            }

            $assigned = DB::transaction(function () use ($req, $amounts, $notReady, $overrides, $jobs) {
                /*
                 * Re-read under the same rules the form was built with, so a
                 * stale page cannot assign work that has since been given away
                 * or cancelled, and unknown ids simply do not come back.
                 *
                 * By the works: a folder half of which is already with somebody
                 * is still here for the other half.
                 */
                $files = WorkFileModel::whereIn('id', $req->input('files'))
                    ->where(fn ($outer) => $outer
                        ->whereHas('items', fn ($q) => $q->whereNull('vendor_id')
                            ->whereNull('kept_in_house_on')
                            ->whereNotIn('status', [WorkFileModel::APPROVED, WorkFileModel::RETURNED, WorkFileModel::CANCELLED]))
                        ->orWhereDoesntHave('items'))
                    ->where('status', '!=', WorkFileModel::CANCELLED)
                    ->get();

                $vendorName = PartyModel::whereKey($req->vendor_id)->value('name');

                /*
                 * The folders work actually went out of, which is not the
                 * folders that were read back. A ticked job somebody else sent
                 * a moment ago leaves its folder with nothing to give, and
                 * counting it here would report a handover that did not happen.
                 */
                $done = collect();

                foreach ($files as $file) {
                    $from = $file->status;

                    /*
                     * The works going out of this folder: the ones ticked, or
                     * all of those still here when the form ticked none.
                     *
                     * Work already with a vendor is not re-sent — the same
                     * folder can come back to this screen for its other half,
                     * and the half that left is not leaving twice.
                     */
                    $here = $file->items->filter(fn ($item) => $item->isWaitingForAVendor());

                    $going = $jobs ? $here->whereIn('id', $jobs) : $here;

                    /*
                     * A folder with no works on it at all is handed over whole,
                     * the way it always was. Files taken in before a folder
                     * could hold several works have none, and there is nothing
                     * for the works to carry — so the folder carries it.
                     */
                    if ($file->items->isEmpty()) {
                        $file->vendor_id = $req->vendor_id;
                        $file->vendor_date = $req->vendor_date;

                        /*
                         * No rate is taken here. amounts[] is keyed by job, and a
                         * folder id read out of it is whatever job happens to share
                         * the number — work_file and work_file_item count from one
                         * apiece. The screen offers no box on a folder with no work
                         * to price, so there is nothing to read even in principle,
                         * and what it costs is settled on the edit screen as before.
                         */

                        if (in_array($file->status, [WorkFileModel::IN_OFFICE, WorkFileModel::PAPER_PENDENCY], true)) {
                            $file->status = WorkFileModel::DISPATCHED;
                        }

                        $file->save();
                        $file->syncLedger();
                        $file->logStatus($from, trim(($req->remark ? $req->remark.' — ' : '').'Given to '.$vendorName));
                        $done->push($file);

                        continue;
                    }

                    if ($going->isEmpty()) {
                        continue;
                    }

                    /*
                     * The rate is agreed per job, because the charge is. A folder
                     * holding a transfer and a hypothecation addition has two
                     * costs, and the folder's own figure is their sum — set here
                     * would be erased by the roll-up a moment later.
                     */
                    foreach ($going as $item) {
                        $amount = $amounts[$item->id] ?? null;

                        $item->vendor_amount = ($amount === null || $amount === '') ? null : (float) $amount;
                        $item->vendor_id = $req->vendor_id;
                        $item->vendor_date = $req->vendor_date;
                        // Going out again clears the day it last came back.
                        $item->vendor_returned_on = null;

                        // Leaving the office is true of the work that leaves it.
                        if (in_array($item->status, [WorkFileModel::IN_OFFICE, WorkFileModel::PAPER_PENDENCY], true)) {
                            $item->status = WorkFileModel::DISPATCHED;
                        }

                        $item->save();
                    }

                    /*
                     * The folder's vendor, date and status are not set here.
                     *
                     * They are worked out from the works by the roll-up below:
                     * one vendor while the works agree and none while they do
                     * not, and File Dispatch once the work that left says so.
                     * Written here as well, a handover of half a folder would
                     * claim the whole of it had gone.
                     */
                    // Reloaded because the status above was written straight to
                    // the database; the copies in memory still say what they were.
                    $file->load('items');
                    $file->rollUp();
                    $file->save();
                    $file->syncLedger();
                    /*
                     * Named when only part of the folder went. "Given to
                     * Sharma" on a folder of three, two of which are with
                     * somebody else, is a sentence nobody can act on.
                     */
                    $went = $going->count() === $file->items->count()
                        ? 'Given to '.$vendorName
                        : $going->map(fn ($item) => $item->workType?->name ?? 'work')->implode(', ').' given to '.$vendorName;

                    $file->logStatus($from, trim(($req->remark ? $req->remark.' — ' : '').$went));

                    if (isset($notReady[$file->id])) {
                        $file->logStatus($file->status, 'Went to the vendor before its papers were complete ('.$notReady[$file->id].'). Reason: '.$overrides[$file->id], null, WorkFileModel::PAPERS_OVERRIDE);
                    }

                    $done->push($file);
                }

                return $done;
            });

            if ($assigned->isEmpty()) {
                return back()->with('error', 'Those files are no longer available to give out — they may have been assigned or cancelled already.');
            }

            /*
             * And the sheet for what just went out, offered where the office
             * already is: the papers are in somebody's hand now, and the
             * signature is worth asking for before they leave the counter.
             */
            return redirect()->route('workfile.index')
                ->with('success', $assigned->count().' '.Str::plural('file', $assigned->count()).' given to the vendor: '.$assigned->pluck('file_no')->implode(', '))
                ->with('sheet', [
                    'url' => route('workfile.dispatchsheet', ['vendor' => $req->vendor_id, 'date' => $req->vendor_date]),
                    'label' => 'Print the hand-over sheet for '.PartyModel::whereKey($req->vendor_id)->value('name'),
                ]);
        }

        $files = WorkFileModel::unassigned();
        $vendors = PartyModel::selectList('vendor');

        // Asked of every work on the screen at once, rather than a query a row.
        $jobPapers = WorkFileModel::papersNotReadyByJob($files->pluck('id')->all());

        // A bounced batch comes back with the date the user chose, not today's.
        $vendorDate = old('vendor_date', date('Y-m-d'));
        $vendorDateDisplay = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $vendorDate)
            ? date('d-m-Y', strtotime($vendorDate))
            : (string) $vendorDate;

        $props = [
            'action' => route('workfile.assign'),
            // The same ticks, posted somewhere else: see keepInHouse().
            'keepUrl' => route('workfile.keepinhouse'),
            'csrf' => csrf_token(),
            'cancelUrl' => route('workfile.index'),
            'vendorId' => old('vendor_id') ? (int) old('vendor_id') : '',
            'vendorDate' => $vendorDate,
            'vendorDateDisplay' => $vendorDateDisplay,
            'remark' => (string) old('remark'),
            'pickedFiles' => array_map('intval', (array) old('files', [])),
            /*
             * The works ticked, not only the folders. A bounced batch that
             * came back ticking whole folders would quietly widen a handover
             * the operator had narrowed.
             */
            'pickedJobs' => array_map('intval', (array) old('jobs', [])),
            'oldOverrides' => (object) (array) old('overrides', []),
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

                // Whether its papers are ready to go: ready, to_check or pending.
                'papers' => $file->needs_audit ? 'to_check' : ($file->pending_papers ? 'pending' : 'ready'),
                'papers_note' => $file->needs_audit
                    ? 'Papers not checked yet'
                    : ($file->pending_papers ? 'Papers pending: '.$file->pending_papers : null),
                'papers_url' => route('workfile.papers', ['id' => $file->id, 'return_to' => route('workfile.assign')]),

                /*
                 * The works in the envelope, each one its own handover.
                 *
                 * The rate is agreed per job because the charge is — a transfer
                 * and a hypothecation addition in one folder are two charges and
                 * two costs — and now the tick is per job too: they do not have
                 * to go to the same person, or on the same day.
                 *
                 * Works already gone and works already finished are sent as
                 * well. They cannot be ticked, but leaving them out would make a
                 * half-empty folder look like a whole one, and the operator
                 * would have no way to see who is holding the rest.
                 */
                'items' => $file->items->map(fn ($item) => [
                    'id' => (int) $item->id,
                    'work_type_id' => (int) $item->work_type_id,
                    'work_type' => $item->workType?->name,
                    'customer_amount' => (float) $item->customer_amount,
                    // What this kind of work usually costs to have done, so
                    // ticking it fills the box in. Never the customer charge:
                    // the gap between the two is the margin.
                    'vendor_rate' => $item->workType?->default_vendor_rate === null
                        ? null
                        : (float) $item->workType->default_vendor_rate,

                    /*
                     * Where this work stands: here to be given out, out with
                     * somebody already, or done with and never going anywhere.
                     * Only "here" can be ticked.
                     */
                    'state' => in_array($item->status, [WorkFileModel::APPROVED, WorkFileModel::RETURNED, WorkFileModel::CANCELLED], true)
                        ? 'done'
                        : ($item->vendor_id ? 'out' : ($item->isKeptInHouse() ? 'kept' : 'here')),
                    // When the office said it was doing this one itself.
                    'kept_on' => $item->kept_in_house_on
                        ? date('d-m-Y', strtotime($item->kept_in_house_on))
                        : null,
                    'status_label' => WorkFileModel::STATUSES[$item->status] ?? null,

                    // Who has it and since when, for the works that are gone.
                    'vendor' => $item->vendor?->name,
                    'vendor_date' => $item->vendor_date ? date('d-m-Y', strtotime($item->vendor_date)) : null,
                    'vendor_amount' => $item->vendor_amount === null ? null : (float) $item->vendor_amount,

                    /*
                     * Its own papers, not the folder's. The server asks the
                     * same question of the works going out, so a folder held up
                     * by a form on work that is staying here must not ask the
                     * operator to explain a refusal nobody made.
                     */
                    'papers' => $jobPapers[$item->id]['why'] ?? 'ready',
                    'papers_pending' => $jobPapers[$item->id]['papers'] ?? null,
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
     * Say that the office is doing some work itself.
     *
     * The other thing that can happen to a work on the Give to Vendor screen.
     * It is offered there because it has no vendor and is not finished, which
     * is exactly what a work being done at this counter looks like — so that
     * folder sat on the list of work waiting to go out until the work was
     * approved, next to the work that really was waiting.
     *
     * Nothing about the money moves. The customer is charged the same, no
     * vendor is credited anything, and the work goes on through the status
     * board as before. All that changes is that nobody is being asked to send
     * it any more.
     *
     * Letting go of it again is a correction and lives on the edit screen, for
     * the same reason moving a file to a different vendor does: by then the
     * folder may have left this list entirely.
     */
    public function keepInHouse(Request $req)
    {
        $req->validate([
            'files' => 'required|array|min:1',
            'files.*' => 'integer',
            'jobs' => 'required|array|min:1',
            'jobs.*' => 'integer',
        ], [
            'jobs.required' => 'Tick the work you are keeping in-house.',
        ]);

        $jobs = array_map('intval', (array) $req->input('jobs'));

        $kept = DB::transaction(function () use ($req, $jobs) {
            /*
             * Re-read under the same rule the screen was drawn with, so a stale
             * page cannot keep work that has since been given away: work with a
             * vendor is with them, and saying it is being done here would be a
             * second answer to a question already settled.
             */
            $files = WorkFileModel::whereIn('id', $req->input('files'))
                ->where('status', '!=', WorkFileModel::CANCELLED)
                ->with('items.workType')
                ->get();

            $done = collect();

            foreach ($files as $file) {
                $keeping = $file->items
                    ->whereIn('id', $jobs)
                    ->filter(fn ($item) => $item->isWaitingForAVendor());

                if ($keeping->isEmpty()) {
                    continue;
                }

                foreach ($keeping as $item) {
                    $item->kept_in_house_on = now()->toDateString();
                    $item->save();
                }

                $named = $keeping->map(fn ($item) => $item->workType?->name ?? 'work')->implode(', ');

                $file->logStatus($file->status, $named.' kept in-house');

                $done->push($file);
            }

            return $done;
        });

        if ($kept->isEmpty()) {
            return back()->with('error', 'That work is no longer here to keep — it may have been given out already.');
        }

        return redirect()->route('workfile.assign')
            ->with('success', 'Kept in-house on '.$kept->count().' '.Str::plural('file', $kept->count()).': '.$kept->pluck('file_no')->implode(', '));
    }

    /**
     * The office's own to-do list: work it said it is doing itself, not done.
     *
     * Keep in-house took that work off Give to Vendor, and it then showed up
     * nowhere except inside its folder. A vendor's work has the vendor report
     * to be chased from; this is the same list for the counter.
     *
     * Each row has the Work Report's Update button, posting to Update Status's
     * own controller and coming back here, so moving a job along does not
     * mean finding it again on the board. It falls off the list the moment it
     * is approved.
     */
    public function inHouse(Request $req)
    {
        $rows = WorkFileModel::inHouseWork();

        $totals = [
            'works' => $rows->count(),
            'files' => $rows->pluck('file_id')->unique()->count(),
            'charged' => (float) $rows->sum('charged'),
            'oldest' => (int) $rows->max('days'),
        ];

        $props = [
            'title' => 'In-house Work',
            'perPage' => 100,
            'emptyText' => 'Nothing is being done in-house. Work appears here once it is marked '
                .'"Keep in-house" on Give to Vendor.',
            'totals' => ['charged' => 'sum'],
            'columns' => [
                ['key' => 'file_no', 'label' => 'File No.', 'type' => 'link', 'linkTo' => 'edit_url'],
                ['key' => 'registration_no', 'label' => 'Vehicle'],
                ['key' => 'customer', 'label' => 'Customer', 'type' => 'link', 'linkTo' => 'customer_url'],
                /*
                 * The day it was kept rides under the work rather than in a
                 * column of its own, and is its own column only in the
                 * exports. With the Update column that made nine, and at nine
                 * the grid goes wide and scrolls sideways — putting Update, the
                 * one thing to do here, past the edge of a laptop screen.
                 */
                ['key' => 'work', 'label' => 'Work', 'sub' => 'kept_text'],
                // How long the customer has been waiting, which is the order
                // the work wants doing in.
                ['key' => 'received', 'label' => 'Received', 'sortBy' => 'received_raw', 'sub' => 'days_text'],
                ['key' => 'kept_on', 'label' => 'Kept In-house', 'sortBy' => 'kept_raw', 'exportOnly' => true],
                ['key' => 'status', 'label' => 'Status', 'type' => 'badge'],
                ['key' => 'charged', 'label' => 'Charged', 'type' => 'money'],
                // Kept out of the exports and the search, as every action
                // column is: a column of the word Update is not data.
                [
                    'key' => 'update',
                    'label' => 'Update',
                    'type' => 'action',
                    'icon' => 'bi-pencil-square',
                    'sortable' => false,
                    'searchable' => false,
                    'exportable' => false,
                ],
            ],
            'rows' => $rows->values(),

            /*
             * What the Update dialog needs, from the model and the status
             * screen as the Work Report takes it: the dialog posts to that
             * controller, and a second copy of its rules would drift.
             */
            'action' => route('workfile.status'),
            'csrf' => csrf_token(),
            // Back to this list once the change is saved.
            'returnTo' => route('workfile.inhouse'),
            'jobStatuses' => WorkFileModel::JOB_STATUSES,
            'pendencyKey' => WorkFileModel::PAPER_PENDENCY,
            'cancelledKey' => WorkFileModel::CANCELLED,
            'approvedKey' => WorkFileModel::APPROVED,
            'reasonKeys' => [WorkFileModel::CANCELLED, WorkFileModel::RETURNED],
            'today' => now()->toDateString(),
            // What a refused save held, so the dialog opens again with it.
            'restore' => \App\Support\UpdateDialog::restore(),
        ];

        return Screen::make('admin.work.in-house', 'vue-inhouse-work', $props, [
            'totals' => $totals,
        ])->toResponse($req);
    }

    /**
     * Give a batch of files back to their customers.
     *
     * The charge each customer already carries stays on their statement and a
     * credit is added beside it, so the pair reads as "charged, then returned"
     * rather than the charge quietly vanishing.
     */
    /**
     * A page to return to, but only one of ours.
     *
     * This arrives in the form body, which means it arrives from whoever sent
     * the form. Redirecting to it unchecked is how a link that looks like a
     * page of this application lands somebody on a copy of the login screen
     * somewhere else — so the host has to match, and anything that is not a
     * plain http(s) URL on this site is ignored rather than argued with.
     *
     * The path and query survive; nothing else does.
     */
    /**
     * What an edit form posted, as one short string: the same page pressed
     * twice posts the same thing.
     *
     * The files attached count as much as the fields typed. Found in review:
     * left out, a reader who went Back, picked a different PDF or a different
     * approval screenshot and saved again was told the save was a repeat — and
     * the new file was thrown away. Each is known by its name, its size and
     * its contents, which is why this is worked out before anything moves the
     * uploads into place.
     */
    private static function postPrint(Request $req): string
    {
        $files = collect(\Illuminate\Support\Arr::dot($req->allFiles()))
            ->map(fn ($upload) => $upload instanceof \Illuminate\Http\UploadedFile && $upload->isValid()
                ? [$upload->getClientOriginalName(), $upload->getSize(), (string) sha1_file($upload->getRealPath())]
                : null)
            ->all();

        return sha1(json_encode([
            \Illuminate\Support\Arr::except($req->input(), ['_token']),
            $files,
        ]));
    }

    private static function safeReturn($url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $parts = parse_url(trim($url));

        if ($parts === false) {
            return null;
        }

        /*
         * Only the path and the query are kept, and the address is rebuilt from
         * this application's own base. That is the whole guard: whatever host,
         * scheme or credentials were sent are not rejected so much as never
         * used, so there is nothing left for them to point at.
         *
         * Checking them as well was the first version of this and every one of
         * those checks turned out to be unreachable — a URL naming another host
         * was already landing on ours, because its host was thrown away here.
         * A guard that cannot fail is not protection, it is furniture.
         */
        $path = $parts['path'] ?? '/';

        /*
         * Browsers read a backslash in this position as a slash, so "/\host"
         * is "//host" by the time anything acts on it. Normalised before the
         * test below rather than tested for separately: the point is that the
         * string a browser sees must be the string that was checked.
         */
        $path = str_replace('\\', '/', $path);

        // "//evil.test" parses as a path on some inputs and is a protocol
        // relative URL to a browser.
        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }

        return url($path.(isset($parts['query']) ? '?'.$parts['query'] : ''));
    }

    /**
     * An approval screenshot, served to the office by the application.
     *
     * These sit under public/ and are web-served, which means the URL works for
     * anyone holding it whether or not they can sign in. The customer portal
     * was given a guarded route for exactly that reason; the office kept
     * linking at the path, so the same document had one address that checked
     * who was asking and one that did not.
     *
     * Behind this route it is the admin session that decides, every time.
     */
    public function approvalFile(Request $req, int $id, ?int $item = null)
    {
        $file = WorkFileModel::findOrFail($id);

        $path = $item === null
            ? $file->approval_screenshot
            // Scoped to this file, so an item id from another one resolves to
            // nothing rather than to somebody else's evidence.
            : WorkFileItemModel::where('work_file_id', $file->id)->where('id', $item)->value('approval_screenshot');

        abort_unless(WorkFileModel::isStoredUpload($path), 404);

        return response()->file(public_path($path), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * One of a file's documents, likewise.
     *
     * Inline rather than as an attachment, because the office opens these to
     * read them — but named, so saving one writes the name it was scanned
     * under instead of the generated one it is stored as.
     */
    public function documentFile(Request $req, int $id, int $doc)
    {
        $file = WorkFileModel::findOrFail($id);

        $document = $file->documents()->where('id', $doc)->first();

        abort_if($document === null, 404);
        abort_unless(WorkFileModel::isStoredUpload($document->path), 404);

        $response = response()->file(public_path($document->path), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        /*
         * Set through the response rather than written as a header string. A
         * name is typed by the office now and may be in Hindi, and a raw
         * non-ASCII filename in that header is one some browsers refuse
         * outright; this writes the UTF-8 name and an ASCII one beside it.
         */
        $response->setContentDisposition('inline', $document->downloadName(), $document->downloadFallback());

        return $response;
    }

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
                /*
                 * The same rule the screen listed by, asked again here. The
                 * page a file was ticked on may have been open since before it
                 * was approved, and the post is what moves the money.
                 */
                $files = WorkFileModel::whereIn('id', $req->input('files'))
                    ->withoutApprovedWork()
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
     * Step 2 of a file's life: its papers checked.
     *
     * Two lists. Files received and not yet checked, each opening its
     * checklist. And every paper still pending, across every file — which is
     * what the counter works from when a customer walks in with Form 30 for one
     * file and an NOC for another: search, tick, Mark received.
     */
    public function paperAudit(Request $req)
    {
        if ($req->isMethod('POST')) {
            $req->validate([
                'received' => 'required|array|min:1',
                'received.*' => 'integer',
                'received_on' => 'required|date_format:Y-m-d|before_or_equal:today',
            ], [
                'received.required' => 'Tick the papers the customer has brought in.',
                'received_on.before_or_equal' => 'Papers cannot be received on a day that has not happened.',
            ]);

            $done = WorkFileModel::receivePapers($req->input('received'), $req->input('received_on'));

            if (! $done) {
                return back()->with('error', 'Those papers are no longer pending — someone may already have marked them.');
            }

            $count = collect($done)->sum(fn ($file) => count($file['papers']));

            return redirect()->route('workfile.paperaudit')->with('success', $count.' '.Str::plural('paper', $count)
                .' received: '.collect($done)->map(fn ($file) => $file['file_no'].' ('.implode(', ', $file['papers']).')')->implode('; '));
        }

        $back = route('workfile.paperaudit');

        $toCheck = WorkFileModel::query()
            ->with('workType', 'customer', 'items.workType')
            ->whereIn('status', [WorkFileModel::IN_OFFICE, WorkFileModel::PAPER_PENDENCY])
            ->whereRaw(WorkFileModel::NEEDS_AUDIT)
            ->orderBy('received_date')
            ->orderBy('id')
            ->get();

        /*
         * What was written about each file before it had a checklist. For a
         * file that was already in Paper Pendency, the remark — and the Details
         * box, where "Without Challan" used to go — is the only record of which
         * papers were missing, and the person checking it needs to see that.
         */
        $lastRemarks = WorkFileModel::latestRemarks($toCheck->pluck('id')->all());

        $pending = DB::table('work_file_paper as p')
            ->join('paper_type as pt', 'pt.id', '=', 'p.paper_type_id')
            ->join('work_file as f', 'f.id', '=', 'p.work_file_id')
            ->leftJoin('party as c', 'c.id', '=', 'f.customer_id')
            ->where('p.state', WorkFilePaperModel::PENDING)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('work_file_paper_item as pi')
                ->join('work_file_item as i', 'i.id', '=', 'pi.work_file_item_id')
                ->whereColumn('pi.work_file_paper_id', 'p.id')
                ->whereNotIn('i.status', [WorkFileModel::APPROVED, WorkFileModel::RETURNED, WorkFileModel::CANCELLED]))
            ->orderBy('f.received_date')
            ->orderBy('f.id')
            ->orderBy('pt.sort')
            ->get(['p.id', 'p.work_file_id', 'p.note', 'p.office_note', 'p.updated_at', 'pt.name as paper',
                'f.file_no', 'f.registration_no', 'f.received_date', 'c.name as customer',
                // Who to send the list to. The customer is the one bringing the papers.
                'c.id as customer_id', 'c.mobile as customer_mobile', 'c.whatsapp as customer_whatsapp']);

        // Which works each pending paper is holding, in one query.
        $holding = $pending->isEmpty() ? collect() : DB::table('work_file_paper_item as pi')
            ->join('work_file_item as i', 'i.id', '=', 'pi.work_file_item_id')
            ->leftJoin('work_type as t', 't.id', '=', 'i.work_type_id')
            ->whereIn('pi.work_file_paper_id', $pending->pluck('id')->all())
            ->whereNotIn('i.status', [WorkFileModel::APPROVED, WorkFileModel::RETURNED, WorkFileModel::CANCELLED])
            ->orderBy('i.id')
            ->get(['pi.work_file_paper_id', 't.name'])
            ->groupBy('work_file_paper_id');

        /*
         * Files sitting in Paper Pendency that this screen cannot help with.
         *
         * Work booked under one of the retired combination types has no list of
         * papers behind it, so it can never be audited and never gets a
         * checklist — and a file in Paper Pendency with no checklist appears in
         * neither list above. It was on no screen at all, which is how one came
         * to sit there for weeks. It is named here, with the reason, because
         * the answer is either to give that work a paper list or to move the
         * file on from the board.
         */
        $stuck = WorkFileModel::query()
            ->with('workType', 'customer', 'items.workType')
            ->where('status', WorkFileModel::PAPER_PENDENCY)
            ->whereRaw('NOT '.WorkFileModel::NEEDS_AUDIT)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('work_file_paper')
                ->whereColumn('work_file_paper.work_file_id', 'work_file.id')
                ->where('work_file_paper.state', WorkFilePaperModel::PENDING))
            ->orderBy('received_date')
            ->orderBy('id')
            ->get();

        $unmapped = $stuck->isEmpty() ? collect() : DB::table('work_file_item as i')
            ->join('work_type as t', 't.id', '=', 'i.work_type_id')
            ->whereIn('i.work_file_id', $stuck->pluck('id')->all())
            ->whereNotIn('i.status', [WorkFileModel::APPROVED, WorkFileModel::RETURNED, WorkFileModel::CANCELLED])
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('work_type_paper')
                ->join('paper_type', 'paper_type.id', '=', 'work_type_paper.paper_type_id')
                ->whereColumn('work_type_paper.work_type_id', 'i.work_type_id')
                ->where('paper_type.is_active', 1))
            ->get(['i.work_file_id', 't.name'])
            ->groupBy('work_file_id');

        /*
         * Every vendor, read once, for the notes the customer is sent below —
         * less, for each customer, the vendor account that is them.
         */
        $shareMarks = WorkFileModel::vendorMarks();
        $marksOf = [];
        $marksFor = function (int $customerId) use ($shareMarks, &$marksOf) {
            return $marksOf[$customerId] ??= WorkFileModel::vendorMarksFor($customerId, $shareMarks);
        };

        $props = [
            'action' => route('workfile.paperaudit'),
            'csrf' => csrf_token(),
            'today' => date('Y-m-d'),
            'stuck' => $stuck->map(function ($file) use ($unmapped, $back) {
                $works = $unmapped->get($file->id, collect())->pluck('name')->filter()->unique()->values();

                return [
                    'id' => $file->id,
                    'file_no' => $file->file_no,
                    'registration_no' => $file->registration_no,
                    'customer' => $file->customer?->name,
                    'work_type' => $file->workLabel() ?: $file->workType?->name,
                    'received_date' => date('d-m-Y', strtotime($file->received_date)),
                    // Said plainly, because the two cases need different answers.
                    'why' => $works->isNotEmpty()
                        ? $works->implode(', ').' has no papers set up, so this file cannot be audited'
                        : 'Nothing is pending on its checklist, so it is in Paper Pendency for no reason on record',
                    'work_type_url' => route('worktype.index'),
                    'board_url' => route('workfile.status', ['status' => WorkFileModel::PAPER_PENDENCY]),
                    'papers_url' => route('workfile.papers', ['id' => $file->id, 'return_to' => $back]),
                ];
            })->values(),
            'search' => (string) $req->query('q', ''),
            'toCheck' => $toCheck->map(fn ($file) => [
                'id' => $file->id,
                'file_no' => $file->file_no,
                'registration_no' => $file->registration_no,
                'customer' => $file->customer?->name,
                'work_type' => $file->workLabel() ?: $file->workType?->name,
                'received_date' => date('d-m-Y', strtotime($file->received_date)),
                'description' => $file->description ?: null,
                'last_remark' => $lastRemarks[$file->id] ?? null,
                'papers_url' => route('workfile.papers', ['id' => $file->id, 'return_to' => $back]),
            ])->values(),
            'pending' => $pending->map(fn ($line) => [
                'id' => (int) $line->id,
                'file_id' => (int) $line->work_file_id,
                'file_no' => $line->file_no,
                'registration_no' => $line->registration_no,
                'customer' => $line->customer,
                'customer_id' => (int) $line->customer_id,
                // Their WhatsApp number when one is saved, as everywhere else a
                // chat is opened.
                'customer_mobile' => $line->customer_whatsapp ?: $line->customer_mobile,
                'paper' => $line->paper,
                'works' => $holding->get($line->id, collect())->pluck('name')->filter()->unique()->values()->all(),
                'note' => $line->note,
                // The same note as the customer is sent it: no vendor in it.
                'share_note' => WorkFileModel::redactVendors($line->note, $marksFor((int) $line->customer_id)),
                'office_note' => $line->office_note,
                'since' => date('d-m-Y', strtotime($line->updated_at)),
                'papers_url' => route('workfile.papers', ['id' => $line->work_file_id, 'return_to' => $back]),
            ])->values(),
        ];

        return Screen::make('admin.work.paper-audit', 'vue-paper-audit', $props, [
            'toCheckCount' => $toCheck->count(),
            'pendingCount' => $pending->count(),
            'stuckCount' => $stuck->count(),
        ])->toResponse($req);
    }

    /**
     * One file's paper checklist: every paper its unfinished work needs, each
     * marked received, pending or not needed.
     */
    public function papers(Request $req, int $id)
    {
        $file = WorkFileModel::with('customer', 'items.workType')->findOrFail($id);
        $back = self::safeReturn($req->input('return_to')) ?? route('workfile.paperaudit');

        if ($req->isMethod('POST')) {
            $checklist = $file->paperChecklist();

            if (! $checklist) {
                return redirect($back)->with('error', 'There is nothing to check on '.$file->file_no.': none of its unfinished work needs papers.');
            }

            $req->validate([
                'papers' => 'required|array',
                'papers.*.state' => ['nullable', Rule::in(array_keys(WorkFilePaperModel::STATES))],
                'papers.*.note' => 'nullable|string|max:200',
                'papers.*.office_note' => 'nullable|string|max:200',
            ]);

            /*
             * Checked against the list as it stands now, not as the page drew
             * it: a work added or cancelled since changes what has to be
             * answered, and a line with no answer is the one thing this screen
             * exists to prevent.
             */
            $errors = [];

            foreach ($checklist as $paperId => $line) {
                $in = (array) $req->input('papers.'.$paperId, []);
                $state = $in['state'] ?? null;

                if (! $state) {
                    $errors['papers.'.$paperId.'.state'] = 'Mark '.$line['name'].' received, pending or not needed.';

                    continue;
                }

                $reason = trim((string) ($in['note'] ?? '')).trim((string) ($in['office_note'] ?? ''));

                if ($line['required'] && $state === WorkFilePaperModel::NOT_NEEDED && $reason === '') {
                    $errors['papers.'.$paperId.'.note'] = $line['name'].' is a required paper. Say why it is not needed.';
                }
            }

            if ($errors) {
                return back()->withInput()->withErrors($errors);
            }

            $result = $file->savePaperChecklist(collect($checklist)
                ->mapWithKeys(fn ($line, $paperId) => [$paperId => (array) $req->input('papers.'.$paperId)])
                ->all());

            return redirect($back)->with('success', $result['pending']
                ? 'Papers checked for '.$file->file_no.'. Pending: '.implode(', ', $result['pending']).' — the customer can see what is needed.'
                : 'Papers complete for '.$file->file_no.'. It is ready to give to a vendor.');
        }

        $checklist = $file->paperChecklist();
        $bag = session('errors');
        $failed = $bag && $bag->any();

        $lastCheck = $file->statusLog()->with('user')->where('event', WorkFileModel::PAPERS)->first();

        $props = [
            'action' => route('workfile.papers', $file->id),
            'csrf' => csrf_token(),
            'returnTo' => $back,
            'backUrl' => $back,
            'editUrl' => route('workfile.edit', $file->id),
            'file' => [
                'file_no' => $file->file_no,
                'registration_no' => $file->registration_no,
                'customer' => $file->customer?->name,
                'received' => date('d-m-Y', strtotime($file->received_date)),
                'status' => WorkFileModel::STATUSES[$file->status] ?? $file->status,
                'status_key' => $file->status,
                // What was noted before this checklist — see paperAudit().
                'description' => $file->description ?: null,
                'last_remark' => WorkFileModel::latestRemarks([$file->id])[$file->id] ?? null,
                'works' => $file->items->reject(fn ($item) => $item->isSettled())
                    ->map(fn ($item) => $item->workType?->name)->filter()->values()->all(),
            ],
            'lastCheck' => $lastCheck ? [
                'on' => date('d-m-Y', strtotime($lastCheck->created_at)),
                'by' => $lastCheck->user?->name,
            ] : null,
            'states' => WorkFilePaperModel::STATES,
            'lines' => collect($checklist)->map(function ($line, $paperId) use ($failed) {
                $old = $failed ? (array) old('papers.'.$paperId, []) : null;

                return [
                    'paper_type_id' => $paperId,
                    'name' => $line['name'],
                    'required' => $line['required'],
                    // A paper only if applicable starts as not needed; a required one starts unanswered.
                    'state' => $old ? ($old['state'] ?? null) : ($line['state'] ?? ($line['required'] ? null : WorkFilePaperModel::NOT_NEEDED)),
                    'saved_state' => $line['state'],
                    'note' => $old ? ($old['note'] ?? '') : (string) $line['note'],
                    'office_note' => $old ? ($old['office_note'] ?? '') : (string) $line['office_note'],
                    'received_on' => $line['received_on'] ? date('d-m-Y', strtotime($line['received_on'])) : null,
                    'works' => array_values($line['works']),
                ];
            })->values(),
            'errors' => (object) ($bag ? collect($bag->messages())->map(fn ($messages) => $messages[0])->all() : []),
        ];

        return Screen::make('admin.work.papers', 'vue-paper-checklist', $props, [
            'fileNo' => $file->file_no,
        ])->toResponse($req);
    }

    /**
     * Approved papers going back to the customer.
     *
     * The counterpart to Return to Customer, and deliberately not part of it: a
     * return is a refund and credits the customer, and approved work is finished
     * work they pay for in full. Nothing here touches a ledger or a status. It
     * records when the papers left, who recorded it, and — optionally — who took
     * them, which is office-only.
     */
    public function handOver(Request $req)
    {
        if ($req->isMethod('POST')) {
            $req->validate([
                // Not in the future: this records something that has happened.
                'handed_over_on' => 'required|date_format:Y-m-d|before_or_equal:today',
                'files' => 'required|array|min:1',
                'files.*' => 'integer',
                'collected_by' => 'nullable|string|max:120',
                // Optional, because no balance moves. Shown to the customer.
                'remark' => 'nullable|string|max:200',
            ], [
                'files.required' => 'Tick at least one file to hand over.',
                'handed_over_on.before_or_equal' => 'The handover date cannot be in the future.',
            ]);

            $done = DB::transaction(function () use ($req) {
                /*
                 * The same rule the screen listed by, asked again and locked. The
                 * page may have been open since before a file was handed over by
                 * someone else, or since before a work on it was cancelled.
                 */
                $files = WorkFileModel::whereIn('id', $req->input('files'))
                    ->awaitingHandover()
                    ->lockForUpdate()
                    ->get();

                foreach ($files as $file) {
                    $file->handOver($req->input('handed_over_on'), $req->input('collected_by'), $req->input('remark'));
                }

                return $files;
            });

            if ($done->isEmpty()) {
                return back()->withInput()->with(
                    'error',
                    'Those files are no longer waiting to be handed over — their papers may already have gone back, or a work on them is no longer approved.'
                );
            }

            return redirect()->route('workfile.handover')
                ->with('success', 'Papers handed over for '.$done->count().' '.Str::plural('file', $done->count())
                    .': '.$done->pluck('file_no')->implode(', '));
        }

        $files = WorkFileModel::readyForHandover();

        $props = [
            'action' => route('workfile.handover'),
            'csrf' => csrf_token(),
            'cancelUrl' => route('workfile.approved'),
            'handedOverOn' => old('handed_over_on', date('Y-m-d')),
            'today' => date('Y-m-d'),
            'oldCollectedBy' => old('collected_by', ''),
            'oldRemark' => old('remark', ''),
            'oldFiles' => array_values((array) old('files', [])),
            // Arriving from one file's own screen: the list opens narrowed to it.
            'search' => (string) $req->query('q', ''),
            'files' => $files->map(fn ($file) => [
                'id' => $file->id,
                'file_no' => $file->file_no,
                'received_date' => date('d-m-Y', strtotime($file->received_date)),
                'approved_on' => ($on = $file->items->max('approved_on')) ? date('d-m-Y', strtotime($on)) : null,
                'registration_no' => $file->registration_no,
                'work_type' => $file->workLabel() ?: $file->workType?->name,
                'description' => $file->description,
                'customer' => $file->customer?->name,
            ])->values(),
        ];

        return Screen::make('admin.work.hand-over', 'vue-hand-over', $props, [
            'fileCount' => $files->count(),
            'anyApproved' => WorkFileModel::where('status', WorkFileModel::APPROVED)->exists(),
        ])->toResponse($req);
    }

    /**
     * A handover recorded by mistake, taken back.
     *
     * A reason is required, and kept on the office's history: a record that
     * silently changed is worse than one that says it was corrected.
     */
    public function undoHandover(Request $req, int $id)
    {
        $req->validate([
            'undo_remark' => 'required|string|max:200',
        ], [
            'undo_remark.required' => 'Say why the handover is being taken back — it stays on the file\'s history.',
        ]);

        $file = WorkFileModel::findOrFail($id);

        if (! $file->isHandedOver()) {
            return redirect()->route('workfile.edit', $file->id)
                ->with('error', 'The papers for '.$file->file_no.' are not recorded as handed over, so there is nothing to take back.');
        }

        DB::transaction(fn () => $file->undoHandover($req->input('undo_remark')));

        return redirect()->route('workfile.edit', $file->id)
            ->with('success', 'Handover taken back for '.$file->file_no.'. It is on the Hand Over Papers list again.');
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

                    // The works come back with the folder, for the reason they
                    // went out with it.
                    $file->items()
                        ->where('status', '<>', WorkFileModel::CANCELLED)
                        ->whereNotNull('vendor_id')
                        ->update(['vendor_returned_on' => $req->returned_on]);
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
                // Named from the works when the folder is split between vendors.
                'vendor' => $file->vendorLabel(),
                'vendor_date' => $file->vendor_date ? date('d-m-Y', strtotime($file->vendor_date)) : null,
                // How long the vendor has had it, which is why this list is read.
                'days_out' => WorkFileModel::daysOutText($file->vendor_date, $file->status),
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

                // What each work said when the page was drawn; see below.
                'was' => 'nullable|array',
                // Only ever compared, never stored, so an old status no longer on
                // the list cannot refuse a save.
                'was.*' => 'nullable|string|max:50',
                // And the approval date each showed, for the same reason.
                'was_approved_on' => 'nullable|array',
                'was_approved_on.*' => 'nullable|string|max:20',
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
             * Work that has moved since the page was drawn.
             *
             * Every screen that posts here sends a status for each work it
             * shows — the board one per row, the Work Report's dialog one for
             * each work in the folder — whether or not anybody touched it. So a
             * page left open while a colleague returned the file, or approved
             * the work, sent the old status back with the next save and put the
             * work where it had been: the return undone and its refund taken
             * off the ledger, the approval's date wiped.
             *
             * The page now says what each work was when it was drawn. A work
             * this post leaves alone — the status as it was, no remark, no
             * document — is not touched, whatever it says now. One the post
             * does ask something of, and that has moved in the meantime, stops
             * the whole save: what was chosen was chosen against a status that
             * is no longer true, and the reader needs to see the new one before
             * deciding again.
             *
             * A post with no `was` at all is a page drawn before this was
             * added, and is read as it always was.
             */
            $was = (array) $req->input('was', []);

            /*
             * The approval date is the same kind of thing. The board draws the
             * date on every approved row so it can be corrected, and posts it
             * back with any remark on that row — which put back a date a
             * colleague had corrected since. So it says what date it drew, and
             * a date is only applied when the work is being approved now or
             * the reader actually changed it.
             */
            $wasOn = (array) $req->input('was_approved_on', []);

            $shown = fn ($item) => array_key_exists($item->id, $was) && $was[$item->id] !== null && $was[$item->id] !== '';

            $postedOn = fn ($item) => trim((string) ($approvedOn[$item->id] ?? ''));
            $drawnOn = fn ($item) => trim((string) ($wasOn[$item->id] ?? ''));
            $storedOn = fn ($item) => $item->approved_on ? date('Y-m-d', strtotime($item->approved_on)) : '';

            $datedAnew = fn ($item) => array_key_exists($item->id, $wasOn)
                && $postedOn($item) !== ''
                && $postedOn($item) !== $drawnOn($item);

            $asked = fn ($item) => $wanted[$item->id] !== $was[$item->id]
                || trim((string) ($remarks[$item->id] ?? '')) !== ''
                || isset($uploads[$item->id])
                || $datedAnew($item);

            $stale = $items
                ->filter(fn ($item) => $shown($item) && $asked($item) && (
                    $was[$item->id] !== $item->status
                    // A date changed on the page, over one changed since. Only
                    // for a work staying where it is: one being moved is
                    // already judged by its status above.
                    || ($wanted[$item->id] === $was[$item->id] && $datedAnew($item) && $storedOn($item) !== $drawnOn($item))
                ))
                ->map(fn ($item) => $name($item).' (now '.(WorkFileModel::STATUSES[$item->status] ?? $item->status)
                    .($item->approved_on && $item->isApproved() ? ', approved '.date('d-m-Y', strtotime($item->approved_on)) : '').')');

            if ($stale->isNotEmpty()) {
                return back()->withInput()->with(
                    'error',
                    'Nothing was saved: this work has changed since the page was opened. Reload the page to see where it stands now, then try again: '.$stale->implode(', ')
                );
            }

            /*
             * What the page did not ask anything of is left out of the save
             * altogether — not written, and not judged by the checks below,
             * which are about what is being asked for. A row a colleague
             * approved since, untouched here, must not refuse this save for
             * want of a screenshot nobody here was trying to give.
             */
            $items = $items->reject(fn ($item) => $shown($item) && ! $asked($item))->values();

            /*
             * Paper Pendency is set by the paper checklist. Chosen here by hand
             * it would say papers are missing with no list of which.
             *
             * Moving a work out of it is not refused. The status says where the
             * work has got to; the checklist says which papers are in, and it
             * keeps saying so — to the office on Paper Audit and to the
             * customer on their own page — whatever the work does next. Files
             * booked under the retired combination types have no paper list at
             * all, and refusing the move left them with nowhere to go but
             * Cancelled.
             */
            $byHand = $items->filter(function ($item) use ($wanted) {
                $to = $wanted[$item->id];

                if ($to === $item->status) {
                    return false;
                }

                return $to === WorkFileModel::PAPER_PENDENCY;
            })->map($name);

            if ($byHand->isNotEmpty()) {
                return back()->withInput()->with(
                    'error',
                    'Paper Pendency is set by the paper checklist, not chosen here. Mark the papers on Paper Audit for: '.$byHand->implode(', ')
                );
            }

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

            $changed = DB::transaction(function () use ($items, $wanted, $remarks, $uploads, $approvedOn, $wasOn) {
                $files = [];
                $notes = [];
                $moved = 0;
                $approvedNow = [];

                foreach ($items as $item) {
                    $upload = $uploads[$item->id] ?? null;
                    $remark = trim((string) ($remarks[$item->id] ?? '')) ?: null;
                    $from = $item->status;
                    $movedThis = $from !== $wanted[$item->id];

                    // A remark on its own is worth saving: it records chasing the
                    // RTO about one job without that job moving. So is a
                    // corrected approval date.
                    $redated = array_key_exists($item->id, $wasOn)
                        && trim((string) ($approvedOn[$item->id] ?? '')) !== ''
                        && trim((string) ($approvedOn[$item->id] ?? '')) !== trim((string) ($wasOn[$item->id] ?? ''));

                    if (! $movedThis && ! $upload && ! $remark && ! $redated) {
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

                    /*
                     * Only when the work is being approved now, or the date
                     * was changed on the page. A date posted back as it was
                     * drawn is not a correction, and writing it would undo one
                     * made since. A page that says nothing about what it drew
                     * is read as it always was.
                     */
                    $applyDate = $movedThis
                        || ! array_key_exists($item->id, $wasOn)
                        || $date !== trim((string) ($wasOn[$item->id] ?? ''));

                    if ($date !== '' && $item->isApproved() && $applyDate) {
                        $item->approved_on = $date;
                    }

                    $item->save();

                    if ($movedThis) {
                        $moved++;
                    }

                    // Approved in this save, so the customer can be told from
                    // the page it lands on; see approvalNotices().
                    if ($movedThis && $item->isApproved()) {
                        $approvedNow[] = (int) $item->id;
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

                return ['items' => $moved, 'files' => count($files), 'approved' => $approvedNow];
            });
            if (! $changed['files']) {
                return back()->with('error', 'Nothing was changed.');
            }

            // Work approved in this save: the page it lands on offers to tell
            // each customer, on WhatsApp. See the alerts partial.
            if ($changed['approved']) {
                session()->flash('approved', WorkFileModel::approvalNotices($changed['approved']));
            }

            /*
             * Back where the change was made from. The board posts nothing
             * here and lands on itself as before; the work report sends the
             * page it was showing, so a reader who moved one file along does
             * not lose the customer, the dates and the status they had filtered
             * to in order to find it.
             */
            $back = self::safeReturn($req->input('return_to'));

            if ($back !== null) {
                return redirect($back)->with('success', $changed['items']
                    ? $changed['items'].' '.Str::plural('work', $changed['items']).' updated.'
                    : 'Remarks saved.');
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
        $pendingPapers = WorkFileModel::pendingPaperNames($files->pluck('id')->all());

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
            // The status only the paper checklist sets. cancelledKey, below, is the one move out of it.
            'pendencyKey' => WorkFileModel::PAPER_PENDENCY,
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
                // When it went to the vendor, and how long it has been there.
                'dispatched' => $file->vendor_date ? date('d-m-Y', strtotime($file->vendor_date)) : null,
                'days_out' => WorkFileModel::daysOutText($file->vendor_date, $file->status, $file->finishedOn()),
                'registration_no' => $file->registration_no,
                'description' => $file->description,
                'customer' => $file->customer?->name,
                'vendor' => $file->vendorLabel(),
                'customer_amount' => (float) $file->customer_amount,
                // The folder's own state, derived from the jobs below it. Shown,
                // never chosen: it is an answer, not a question.
                'status' => $file->status,
                'status_label' => WorkFileModel::STATUSES[$file->status] ?? $file->status,
                'edit_url' => route('workfile.edit', $file->id),
                // Where a paper still to come is marked in.
                'papers_url' => route('workfile.papers', ['id' => $file->id, 'return_to' => route('workfile.status')]),
                /*
                 * Which papers are still missing, whatever the work is doing.
                 *
                 * The board used to read this off the status, so a file given
                 * to a vendor on an override — which no longer sits in Paper
                 * Pendency, because it is not on the desk any more — would have
                 * stopped saying anything at all. It is the checklist's answer,
                 * and it is worth having on a file that is out: it is what the
                 * office owes the RTO.
                 */
                'pending_papers' => $pendingPapers[$file->id] ?? null,
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
                        'screenshot_url' => $item->approval_screenshot ? route('workfile.approval', ['id' => $item->work_file_id, 'item' => $item->id]) : null,
                        'approved_on' => $item->approved_on ? date('d-m-Y', strtotime($item->approved_on)) : null,
                        // The box is filled with today, which is right far more
                        // often than it is wrong, and can be typed over.
                        'approved_on_value' => $item->approved_on
                            ? date('Y-m-d', strtotime($item->approved_on))
                            : date('Y-m-d'),
                        // What is actually stored, which the box above is not
                        // when there is none. Posted back so the save can tell
                        // a date changed since from one being entered now.
                        'approved_on_iso' => $item->approved_on
                            ? date('Y-m-d', strtotime($item->approved_on))
                            : null,
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
            /*
             * A page left open while somebody else changed the file.
             *
             * The form posts every field it shows, so saving it wrote the file
             * back as it was when the page was drawn: a return a colleague
             * made since was undone and its refund taken off the ledger, an
             * approval lost its date, a corrected price went back. The page
             * says what it was drawn from; see editFingerprint().
             *
             * Checked before anything else, including validation, and sent to
             * a freshly drawn page without the typed values: those were typed
             * against the old file, and put back into the form they would
             * carry its old status straight into the next save.
             *
             * A post that carries no fingerprint is a page drawn before this
             * was added, and is saved as it always was.
             */
            $drawn = $req->input('drawn');

            // What this post is, taken now while its uploads are still where
            // they arrived; see postPrint().
            $print = self::postPrint($req);

            $current = $file->editFingerprint();

            if (is_string($drawn) && $drawn !== '' && ! hash_equals($current, $drawn)) {
                /*
                 * Unless it is this reader's own save, arriving twice — a
                 * double click, or Enter pressed again. The first went through
                 * and changed the file, so the second no longer matches it;
                 * refused, it would say nothing was saved and blame a
                 * colleague, and anybody believing it would type the change in
                 * again. The same page, the same answers, and the file exactly
                 * as that save left it: the second press is told it was saved.
                 * A different change sent from the same old page is not this,
                 * and is refused like any other.
                 */
                $saved = $req->session()->get('file_saved.'.$file->id);

                if (is_array($saved)
                    && hash_equals((string) ($saved['from'] ?? ''), $drawn)
                    && hash_equals((string) ($saved['to'] ?? ''), $current)
                    && hash_equals((string) ($saved['post'] ?? ''), $print)) {
                    return redirect()->route('workfile.index')->with(
                        'success',
                        'File '.$file->file_no.' was saved. The button was pressed twice, so the second press changed nothing.'
                    );
                }

                $was = (string) $req->input('was_status');

                $now = $was !== '' && $was !== $file->status
                    ? ' (now '.(WorkFileModel::STATUSES[$file->status] ?? $file->status).')'
                    : '';

                return redirect()->route('workfile.edit', $file->id)->with(
                    'error',
                    $file->file_no.' has changed since you opened it'.$now.'. Nothing was saved. '
                        .'This page now shows it as it is — make your change again.'
                );
            }

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
                // Ticked, the office is doing this work itself; see keepInHouse().
                'items.*.in_house' => 'nullable|boolean',
                // Why a price that was already agreed has moved. Office-only;
                // see the check below.
                'price_remark' => 'nullable|string|max:200',

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

                /*
                 * Money the office paid out on this file: a transfer challan,
                 * an affidavit, a notary's fee. Office cash, so no party ledger
                 * is touched — it raises what the file cost and nothing else.
                 *
                 * Rows already on the file are corrected in place by id;
                 * new_expenses are ones being added now. Both are guarded the
                 * same way, because an expense is money and a mistyped one is a
                 * margin that is wrong until somebody notices.
                 */
                'expenses' => 'nullable|array',
                'expenses.*.expense_type_id' => 'required|integer|exists:expense_type,id',
                'expenses.*.amount' => 'required|numeric|gt:0|max:99999999',
                'expenses.*.spent_on' => 'required|date_format:Y-m-d|before_or_equal:today',
                'expenses.*.remark' => 'nullable|string|max:255',

                'new_expenses' => 'nullable|array',
                'new_expenses.*.expense_type_id' => 'required|integer|exists:expense_type,id',
                'new_expenses.*.amount' => 'required|numeric|gt:0|max:99999999',
                'new_expenses.*.spent_on' => 'required|date_format:Y-m-d|before_or_equal:today',
                'new_expenses.*.remark' => 'nullable|string|max:255',

                // Expenses being taken off the file, by id.
                'remove_expenses' => 'nullable|array',
                'remove_expenses.*' => 'integer',

                /*
                 * Papers scanned against the file. Several at once, because
                 * that is how they are scanned — a form, its annexure and the
                 * receipt are one trip to the scanner.
                 *
                 * PDFs only, and checked by content rather than by the name
                 * the browser sent: this directory is web-served, and what
                 * lands in it must not depend on what a filename claims.
                 */
                'documents' => 'nullable|array|max:20',
                'documents.*' => 'array',
                /*
                 * One row per PDF: the file, and the name the office gives it.
                 * Each needs the other. A PDF with no name reaches the customer
                 * as a row of hex; a name with no PDF is a document somebody
                 * thinks they attached and did not. An empty row — no file, no
                 * name — is the spare one the form always offers, and is
                 * ignored.
                 */
                'documents.*.file' => 'nullable|file|mimes:pdf|max:10240|required_with:documents.*.title',
                'documents.*.title' => 'nullable|string|max:'.WorkFileDocumentModel::TITLE_MAX.'|required_with:documents.*.file',

                // Names given to documents already on the file, by id.
                'document_names' => 'nullable|array',
                'document_names.*' => 'nullable|string|max:'.WorkFileDocumentModel::TITLE_MAX,

                // Documents being taken off the file, by id.
                'remove_documents' => 'nullable|array',
                'remove_documents.*' => 'integer',
            ], [
                'vendor_id.required_with' => 'Select the vendor this file was given to before entering a vendor amount.',
                'items.*.work_type_id.required' => 'Every work on the file needs a type.',
                'items.*.customer_amount.required' => 'Every work on the file needs a charge.',
                'new_works.*.work_type_id.required' => 'Every work added needs a type.',
                'new_works.*.amount.required' => 'Every work added needs a charge.',
                'documents.*.title.required_with' => 'Give each PDF a name — it is what the customer sees it as.',
                'documents.*.file.required_with' => 'Choose the PDF for each name you typed.',
                'documents.*.file.mimes' => 'Only PDF files can be added as documents.',
                'documents.*.file.max' => 'Each PDF must be 10 MB or smaller.',
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

            /*
             * A price that was agreed and has now moved says why.
             *
             * Changing one rewrites this file's entries on a statement somebody
             * has already seen, and "why is this file 6,000 now" is asked weeks
             * later, by which time nobody remembers. The reason is kept for the
             * office: the customer's page shows what they are charged, never the
             * reasoning behind it.
             */
            $priceChanges = $file->priceChanges(
                $req->input('items', []),
                $req->input('customer_amount'),
                $req->input('vendor_amount')
            );

            if ($priceChanges && trim((string) $req->input('price_remark')) === '') {
                return back()->withInput()->withErrors([
                    'price_remark' => 'Say why the price is changing — it is kept for the office and the customer never sees it.',
                ])->with('error', 'This changes a price that was already agreed: '.implode('; ', $priceChanges));
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

            // The works not approved before this save, to tell which it
            // approved; see below.
            $unapprovedBefore = $file->items()
                ->where('status', '<>', WorkFileModel::APPROVED)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            DB::transaction(function () use ($file, $req, $removing, $priceChanges) {
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
                $vendorWas = $file->vendor_id;
                // Read before the save: afterwards the model's own "original"
                // is the new one, and the day each work went out is decided by
                // whether this changed at all.
                $dateWas = $file->vendor_date;

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

                        /*
                         * Kept in-house, or let go of again.
                         *
                         * Give to Vendor is where a work is marked as ours,
                         * because that is the screen it was cluttering. Taking
                         * the mark off is a correction and belongs here, for
                         * the same reason moving a file to another vendor does:
                         * by then the folder has left that list and there is
                         * nowhere there to say it.
                         *
                         * Work already with a vendor is not touched either way.
                         * It is with them, and saying it is being done here
                         * would be a second answer to a settled question.
                         */
                        if (! $item->vendor_id) {
                            $item->kept_in_house_on = empty($correction['in_house'])
                                ? null
                                // Kept already, keep the day it was decided.
                                : ($item->kept_in_house_on ?: now()->toDateString());
                        }

                        $item->save();
                    }
                }

                /*
                 * The vendor boxes above reach the works, when they change.
                 *
                 * Each work carries its own vendor, and this screen still asks
                 * for one — so a vendor typed here is a vendor for the whole
                 * folder. Only when it changes, though: a save that never
                 * touched the boxes must not flatten a folder deliberately
                 * split between two vendors.
                 */
                if ((int) $vendorWas !== (int) $file->vendor_id) {
                    $file->items()
                        ->where('status', '<>', WorkFileModel::CANCELLED)
                        ->update([
                            'vendor_id' => $file->vendor_id,
                            'vendor_date' => $file->vendor_date,
                            'vendor_returned_on' => $file->vendor_id ? $file->vendor_returned_on : null,
                        ]);
                } elseif ($file->vendor_id) {
                    /*
                     * The same vendor, with the day corrected: the works that
                     * carried the old day take the new one, and a work with no
                     * day yet takes it too.
                     *
                     * Not every work of theirs. Found in review: a folder can
                     * go out over two days — half on Monday, the rest on
                     * Tuesday — and flattening them to the folder's day made
                     * Monday's hand-over sheet list papers the vendor took on
                     * Tuesday, and Tuesday's sheet say nothing went at all.
                     * Any save of the file did it, not only one that touched
                     * the date.
                     */
                    $file->items()
                        ->where('status', '<>', WorkFileModel::CANCELLED)
                        ->where('vendor_id', $file->vendor_id)
                        ->where(fn ($q) => $q->where('vendor_date', $dateWas)->orWhereNull('vendor_date'))
                        ->update(['vendor_date' => $file->vendor_date]);
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
                 * What the office paid out on this file.
                 *
                 * Scoped to this file on every write. The ids arrive in the
                 * form body, and an expense id from another file would
                 * otherwise be edited or deleted from a page that has no
                 * business with it.
                 */
                foreach ($req->input('expenses', []) as $id => $paid) {
                    $file->expenses()->where('id', (int) $id)->update([
                        'expense_type_id' => (int) $paid['expense_type_id'],
                        'amount' => (float) $paid['amount'],
                        'spent_on' => $paid['spent_on'],
                        'remark' => trim((string) ($paid['remark'] ?? '')) ?: null,
                        'updated_at' => now(),
                    ]);
                }

                foreach ($req->input('new_expenses', []) as $paid) {
                    if (($paid['expense_type_id'] ?? '') === '' || ($paid['amount'] ?? '') === '') {
                        continue;
                    }

                    $expense = new WorkFileExpenseModel;
                    $expense->work_file_id = $file->id;
                    $expense->expense_type_id = (int) $paid['expense_type_id'];
                    $expense->amount = (float) $paid['amount'];
                    $expense->spent_on = $paid['spent_on'];
                    $expense->remark = trim((string) ($paid['remark'] ?? '')) ?: null;
                    $expense->save();
                }

                if ($dropped = $req->input('remove_expenses', [])) {
                    $file->expenses()->whereIn('id', array_map('intval', $dropped))->delete();
                }

                /*
                 * Papers scanned against the file. Each upload is a new row
                 * and never an overwrite: a corrected form is what supersedes
                 * the earlier one, and replacing it in place would lose the
                 * record of what the office actually sent at the time.
                 */
                foreach ($req->file('documents', []) as $i => $row) {
                    $upload = is_array($row) ? ($row['file'] ?? null) : null;

                    if (! $upload || ! $upload->isValid()) {
                        continue;
                    }

                    // Paired by the row's own index rather than by position in
                    // a second list, so a spare empty row between two filled
                    // ones cannot shift every name after it onto the wrong PDF.
                    $title = trim((string) $req->input("documents.$i.title"));

                    /*
                     * Read before the move, not after.
                     *
                     * storeUpload() moves the temporary file, and everything
                     * the upload can tell you about itself is answered by
                     * stat-ing that file — so asking afterwards throws rather
                     * than returning the size of what was just stored.
                     */
                    $name = mb_substr($upload->getClientOriginalName(), 0, 255);
                    $size = (int) $upload->getSize();

                    $doc = new WorkFileDocumentModel;
                    $doc->work_file_id = $file->id;
                    $doc->path = WorkFileModel::storeUpload(
                        $upload,
                        null,
                        $file->file_no,
                        WorkFileModel::DOC_DIR
                    );
                    // Kept for showing and for sending it back under, never
                    // for building a path: the stored name is generated.
                    $doc->original_name = $name;
                    $doc->title = mb_substr($title, 0, WorkFileDocumentModel::TITLE_MAX);
                    $doc->size = $size;
                    $doc->uploaded_by = Auth::id();
                    $doc->save();
                }

                /*
                 * Names given to documents already here — mostly the ones
                 * uploaded before documents had names at all, which otherwise
                 * reach the customer as whatever the scanner called them.
                 *
                 * Scoped to this file, so an id from somebody else's cannot be
                 * renamed from here. A blank box means leave it alone rather
                 * than clear it: the form sends every box whether or not it was
                 * touched, and a name the office gave is not undone by a field
                 * that happened to be empty.
                 */
                $naming = collect((array) $req->input('document_names', []))
                    ->map(fn ($name) => trim((string) $name))
                    ->filter(fn ($name) => $name !== '');

                if ($naming->isNotEmpty()) {
                    $named = $file->documents()
                        ->whereIn('id', $naming->keys()->map(fn ($id) => (int) $id)->all())
                        ->get();

                    foreach ($named as $doc) {
                        $name = mb_substr($naming[$doc->id], 0, WorkFileDocumentModel::TITLE_MAX);

                        // Untouched boxes arrive holding what was shown in
                        // them; writing that back would change nothing but
                        // updated_at.
                        if ($name !== $doc->displayName()) {
                            $doc->title = $name;
                            $doc->save();
                        }
                    }
                }

                if ($goneDocs = $req->input('remove_documents', [])) {
                    $docs = $file->documents()->whereIn('id', array_map('intval', $goneDocs))->get();

                    foreach ($docs as $doc) {
                        $doc->delete();

                        /*
                         * The file on disk goes only once the row naming it has
                         * safely committed, or a rollback leaves a document
                         * deleted and a row still pointing at it.
                         */
                        DB::afterCommit(function () use ($doc) {
                            $path = public_path($doc->path);

                            if (is_file($path)) {
                                unlink($path);
                            }
                        });
                    }
                }

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

                // What moved and why, on its own entry, under an event no
                // customer page reads.
                if ($priceChanges) {
                    $file->logStatus(
                        $file->status,
                        implode('; ', $priceChanges).' — '.trim((string) $req->input('price_remark')),
                        null,
                        WorkFileModel::PRICE
                    );
                }
            });

            /*
             * Work approved on this screen — a folder of one work, whose status
             * is set here — so the list it lands on offers to tell the
             * customer, as the board and the Work Report do.
             *
             * Decided by the works, as the board decides it, and not by the
             * folder. Found in review: taking the last pending work off a
             * folder turns it Approval Done with nothing approved, and offered
             * a weeks-old approval as news; approving a work while adding
             * another leaves the folder Partly Approved, and offered nothing.
             */
            $approvedNow = $unapprovedBefore
                ? WorkFileItemModel::whereIn('id', $unapprovedBefore)
                    ->where('status', WorkFileModel::APPROVED)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all()
                : [];

            if ($approvedNow) {
                session()->flash('approved', WorkFileModel::approvalNotices($approvedNow));
            }

            // What this save was, so the same save arriving a second time can
            // be told apart from a stale page; see the check above.
            if (is_string($drawn) && $drawn !== '') {
                $req->session()->put('file_saved.'.$file->id, [
                    'from' => $drawn,
                    'to' => $file->fresh()->editFingerprint(),
                    'post' => $print,
                ]);
            }

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
             * What this page is drawn from, posted back so a save can tell the
             * file has changed under it; see edit(). Carried through a save
             * sent back for some other reason, so the page still answers for
             * when it was first drawn rather than for the redraw — the redraw
             * puts the typed values back, the old status among them.
             */
            'drawn' => (string) old('drawn', $file->editFingerprint()),
            'wasStatus' => (string) old('was_status', $file->status),

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
            'screenshotUrl' => $isEdit && $file->approval_screenshot ? route('workfile.approval', $file->id) : '',

            /*
             * Whether the papers have gone back, and the way to take that back.
             * Offered as a link to the screen when the file is ready and they
             * have not: the screen is where the date and the collector are
             * asked for, and one place to do it is enough.
             */
            'handover' => $isEdit && $file->isHandedOver() ? [
                'on' => date('d-m-Y', strtotime($file->handed_over_on)),
                'by' => $file->handedOverBy?->name,
                'collectedBy' => $file->collected_by,
                'undoUrl' => route('workfile.handover.undo', $file->id),
            ] : null,
            /*
             * Where this file's papers stand, and the way to its checklist. The
             * checklist is its own page — it is a list of fifteen lines with a
             * note each, and a side column has no room for it.
             */
            'papers' => $isEdit ? (function () use ($file) {
                $lines = collect($file->paperChecklist());

                if ($lines->isEmpty()) {
                    return null;
                }

                $pending = $lines->where('state', WorkFilePaperModel::PENDING)->pluck('name')->values()->all();
                $unanswered = $lines->whereNull('state')->count();

                return [
                    'state' => $unanswered ? 'to_check' : ($pending ? 'pending' : 'complete'),
                    'pending' => $pending,
                    'toCheck' => $unanswered,
                    'received' => $lines->where('state', WorkFilePaperModel::RECEIVED)->count(),
                    'total' => $lines->count(),
                    'url' => route('workfile.papers', ['id' => $file->id, 'return_to' => route('workfile.edit', $file->id)]),
                ];
            })() : null,
            'handoverUrl' => $isEdit && ! $file->isHandedOver() && $file->status === WorkFileModel::APPROVED
                ? route('workfile.handover', ['q' => $file->file_no])
                : null,

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
                    // null, not an empty string: a rate that was never agreed is
                    // absent rather than blank, and a prop whose type changes with
                    // the data cannot be pinned by ScreenPropsTest. The form
                    // coalesces it to an empty box either way.
                    'vendor_amount' => $item->vendor_amount === null ? null : (float) $item->vendor_amount,
                    'status' => $item->status,
                    'status_label' => WorkFileModel::STATUSES[$item->status] ?? $item->status,
                    // Whether the office said it is doing this one itself, and
                    // whether it is still free to say so: work already with a
                    // vendor is with them.
                    'in_house' => $item->isKeptInHouse(),
                    'has_vendor' => (bool) $item->vendor_id,
                    'screenshot_url' => $item->approval_screenshot ? route('workfile.approval', ['id' => $item->work_file_id, 'item' => $item->id]) : null,
                    'approved_on' => $item->approved_on ? date('d-m-Y', strtotime($item->approved_on)) : null,
                ])->values()
                : [],

            /*
             * What the office has paid out on this file, and what it may be
             * paid out under.
             *
             * Office cash: no party ledger is touched by any of this. It raises
             * what the file cost and therefore lowers the margin, which is the
             * whole point — a challan nobody recorded was a margin nobody could
             * trust.
             */
            'expenses' => $isEdit
                ? $file->expenses()->with('type')->orderBy('spent_on')->orderBy('id')->get()
                    ->map(fn ($paid) => [
                        'id' => (int) $paid->id,
                        'expense_type_id' => (int) $paid->expense_type_id,
                        'type' => $paid->type?->name,
                        'amount' => (float) $paid->amount,
                        'spent_on' => $paid->spent_on?->format('Y-m-d'),
                        'remark' => $paid->remark,
                    ])->values()
                : [],

            /*
             * Retired types are still offered on a file already carrying one,
             * so correcting the amount on an old expense does not force its
             * kind to be changed as well.
             */
            'expenseTypes' => ExpenseTypeModel::selectList(
                $isEdit ? $file->expenses()->pluck('expense_type_id')->all() : null
            )->map(fn ($type) => [
                'id' => (int) $type->id,
                'label' => $type->name.($type->is_active ? '' : ' (retired)'),
                'amount' => $type->default_amount === null ? '' : (float) $type->default_amount,
            ])->values(),

            /*
             * Papers scanned against this file, newest first — which is the
             * order they are read in, because the newest is the one that
             * supersedes the rest and the one the customer is offered.
             */
            'documents' => $isEdit
                ? $file->documents()->orderByDesc('id')->get()->map(fn ($doc) => [
                    'id' => (int) $doc->id,
                    'name' => $doc->displayName(),
                    // What the scanner called it, shown quietly beside the name
                    // so the office can still tell which scan a name was given to.
                    'arrived' => $doc->original_name,
                    'size' => $doc->sizeText(),
                    'uploaded' => $doc->created_at?->format('d-m-Y'),
                    'url' => route('workfile.document', ['id' => $file->id, 'doc' => $doc->id]),
                ])->values()
                : [],

            'today' => date('Y-m-d'),

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
                    'kind' => $entry->kind(),
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
