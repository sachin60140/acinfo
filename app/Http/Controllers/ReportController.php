<?php

namespace App\Http\Controllers;

use App\Models\ExpenseTypeModel;
use App\Models\PartyLedgerModel;
use App\Models\PartyModel;
use App\Models\WorkFileExpenseModel;
use App\Models\WorkFileModel;
use App\Support\Screen;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Party-wise work file reporting.
 *
 * Read-only: it never writes, so every figure here is derived from the same
 * helpers the ledger and the dashboard use rather than recomputed. A report
 * that quietly disagrees with the statement it summarises is worse than none.
 *
 * Exports are built in the browser, by the grid the report renders through.
 * That is deliberate and it constrains this class: the production server runs a
 * pinned vendor/ directory that must not be rebuilt, so a report cannot
 * introduce a composer dependency for Excel or PDF. It therefore returns rows
 * and totals, and nothing here formats a file.
 */
class ReportController extends Controller
{
    /**
     * What the work earned, cut whichever way is being asked.
     *
     * By month and by year for the shape of the business over time; by work
     * type for which service is worth doing; by vendor and by customer for who
     * the money comes from and goes to.
     *
     * Every figure comes from WorkFileModel, from the same expressions the
     * dashboard and the files list read, so this cannot disagree with either
     * about the same file. Files still waiting on a price are counted and kept
     * out of the margin: a difference between a figure and a blank is not a
     * margin, and each row says how many it left out.
     */
    public function profit(Request $req)
    {
        $req->validate([
            'group' => ['nullable', Rule::in(array_keys(WorkFileModel::PROFIT_GROUPS))],
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
        ]);

        $group = $req->query('group', 'month');
        $from = $req->query('from');
        $to = $req->query('to');

        $rows = WorkFileModel::profitBy($group, $from, $to);

        $totals = [
            'files' => (int) $rows->sum('files'),
            'billed' => (float) $rows->sum('billed'),
            'cost' => (float) $rows->sum('cost'),
            'margin' => (float) $rows->sum('margin'),
            'unpriced' => (int) $rows->sum('unpriced'),
        ];

        $periodText = ($from || $to)
            ? ($from ? date('d-m-Y', strtotime($from)) : 'Beginning').' to '.($to ? date('d-m-Y', strtotime($to)) : date('d-m-Y'))
            : 'All dates';

        $label = WorkFileModel::PROFIT_GROUPS[$group];

        /*
         * A percentage is only honest where every file in the row has both
         * figures. Where one does not, the margin covers fewer files than the
         * billed beside it, and a ratio of the two would be a number nobody
         * could act on.
         */
        $rows = $rows->map(fn ($row) => [
            'id' => (string) $row->group_key,
            'label' => $row->group_label,
            'label_note' => $row->note ?? null,
            'files' => (int) $row->files,
            'billed' => (float) $row->billed,
            'cost' => (float) $row->cost,
            'margin' => (int) $row->unpriced ? null : (float) $row->margin,
            'rate' => ((int) $row->unpriced || (float) $row->billed <= 0)
                ? null
                : round((float) $row->margin / (float) $row->billed * 100, 1).'%',
            'unpriced' => (int) $row->unpriced ? (int) $row->unpriced.' awaiting a price' : null,
        ])->values();

        $props = [
            'title' => $label.'-wise Profit — '.$periodText,
            'perPage' => 100,
            'emptyText' => ($from || $to)
                ? 'No work in this period. Try widening the dates.'
                : 'No work has been booked yet.',
            'totals' => ['files' => 'sum', 'billed' => 'sum', 'cost' => 'sum', 'margin' => 'sum'],
            'columns' => [
                // The counter-expenses line is the only one that has anything to
                // add here, and it needs to: a row with a cost that charges
                // nobody reads as a mistake until it says why.
                ['key' => 'label', 'label' => $label, 'sub' => 'label_note'],
                ['key' => 'files', 'label' => $group === 'work_type' ? 'Works' : 'Files', 'type' => 'count'],
                ['key' => 'billed', 'label' => 'Billed', 'type' => 'money', 'class' => 'dr'],
                ['key' => 'cost', 'label' => 'Cost', 'type' => 'money', 'class' => 'cr'],
                // A margin has a side: earned reads Dr, lost reads Cr, and
                // neither needs a minus sign to be read correctly.
                ['key' => 'margin', 'label' => 'Margin', 'type' => 'balance', 'class' => 'fw-bold',
                    'sub' => 'unpriced'],
                ['key' => 'rate', 'label' => 'Margin %', 'sortable' => false],
            ],
            'rows' => $rows,
        ];

        return Screen::make('admin.reports.profit', 'vue-profit-report', $props, [
            'group' => $group,
            'groups' => WorkFileModel::PROFIT_GROUPS,
            'groupLabel' => $label,
            'from' => $from,
            'to' => $to,
            'periodText' => $periodText,
            'totals' => $totals,
            'maxDate' => now()->toDateString(),
            'base' => route('report.profit'),

            // Each cut keeps the period already chosen.
            'groupUrls' => collect(WorkFileModel::PROFIT_GROUPS)
                ->map(fn ($text, $key) => route('report.profit', array_filter([
                    'group' => $key,
                    'from' => $from,
                    'to' => $to,
                ])))
                ->all(),
        ])->toResponse($req);
    }

    /**
     * What the office has paid out, and on which files.
     *
     * Every other report on this screen answers what the work earned. This one
     * answers where the money went that nobody was tracking until now — a line
     * per expense, banded by the kind it was, so "how much do challans cost us
     * a month" has an answer rather than an impression.
     *
     * Office cash throughout: none of this appears on a party's statement,
     * because none of it is owed by or to anybody.
     */
    public function expenses(Request $req)
    {
        $req->validate([
            'expense_type_id' => 'nullable|integer|exists:expense_type,id',
            'party_id' => 'nullable|integer|exists:party,id',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
        ]);

        $typeId = $req->query('expense_type_id');
        $partyId = $req->query('party_id');
        $from = $req->query('from');
        $to = $req->query('to');

        $query = WorkFileExpenseModel::query()
            ->join('work_file', 'work_file.id', '=', 'work_file_expense.work_file_id')
            ->join('expense_type', 'expense_type.id', '=', 'work_file_expense.expense_type_id')
            ->leftJoin('party as customer', 'customer.id', '=', 'work_file.customer_id')
            ->select(
                'work_file_expense.id',
                'work_file_expense.amount',
                'work_file_expense.spent_on',
                'work_file_expense.remark',
                'work_file.id as file_id',
                'work_file.file_no',
                'work_file.registration_no',
                'work_file.status',
                'expense_type.id as type_id',
                'expense_type.name as type_name',
                'customer.name as customer_name'
            );

        if ($typeId) {
            $query->where('expense_type.id', $typeId);
        }

        if ($partyId) {
            $query->where('work_file.customer_id', $partyId);
        }

        // On the day the money left, not the day somebody entered it.
        if ($from) {
            $query->whereDate('work_file_expense.spent_on', '>=', $from);
        }

        if ($to) {
            $query->whereDate('work_file_expense.spent_on', '<=', $to);
        }

        $rows = $query
            ->orderBy('expense_type.name')
            ->orderBy('work_file_expense.spent_on')
            ->orderBy('work_file_expense.id')
            ->get();

        $fromText = $from ? date('d-m-Y', strtotime($from)) : 'Beginning';
        $toText = $to ? date('d-m-Y', strtotime($to)) : 'Till date';
        $periodText = $from || $to ? $fromText.' to '.$toText : 'All dates';

        $total = (float) $rows->sum('amount');

        $props = [
            'title' => 'Expenses — '.$periodText,
            // Banded by kind, so each band subtotals what that kind costs.
            'groupBy' => 'type_id',
            'groupLabel' => 'type_band',
            'totals' => ['amount' => 'sum'],
            // A kind split across two pages would be banded and subtotalled
            // twice, each time on half its expenses.
            'perPage' => max($rows->count(), 1),
            'sortable' => false,
            'emptyText' => ($typeId || $partyId || $from || $to)
                ? 'No expenses match this report. Try widening the dates, or clearing the kind.'
                : 'Nothing recorded yet. Expenses are entered on a file, under the works.',
            'columns' => [
                ['key' => 'type_id', 'label' => 'Kind Id', 'hidden' => true],
                ['key' => 'spent_on', 'label' => 'Paid On', 'sortBy' => 'spent_sort'],
                ['key' => 'type_name', 'label' => 'Kind'],
                ['key' => 'file_no', 'label' => 'File No.', 'type' => 'link', 'linkTo' => 'file_url'],
                ['key' => 'registration_no', 'label' => 'Vehicle'],
                ['key' => 'customer_name', 'label' => 'Customer'],
                ['key' => 'remark', 'label' => 'What For'],
                ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'],
            ],
            'rows' => $rows->map(fn ($one) => [
                'id' => (int) $one->id,
                'type_id' => (int) $one->type_id,
                'type_band' => $one->type_name,
                'type_name' => $one->type_name,
                'spent_on' => date('d-m-Y', strtotime($one->spent_on)),
                // Sorted on separately: dd-mm-yyyy compared as text orders by
                // day of the month.
                'spent_sort' => date('Y-m-d', strtotime($one->spent_on)),
                'file_no' => $one->file_no,
                'file_url' => route('workfile.edit', $one->file_id),
                'registration_no' => $one->registration_no,
                'customer_name' => $one->customer_name,
                'remark' => $one->remark,
                'amount' => (float) $one->amount,
            ])->values(),
        ];

        // What each kind came to, for the tiles above the table.
        $byType = $rows->groupBy('type_name')
            ->map(fn ($group) => [
                'count' => $group->count(),
                'total' => (float) $group->sum('amount'),
            ])
            ->sortByDesc('total');

        return Screen::make('admin.reports.expenses', 'vue-expense-report', $props, [
            'periodText' => $periodText,
            'from' => $from,
            'to' => $to,
            'maxDate' => now()->toDateString(),
            'typeId' => $typeId ? (int) $typeId : null,
            'partyId' => $partyId ? (int) $partyId : null,
            'total' => $total,
            'count' => $rows->count(),
            'fileCount' => $rows->pluck('file_id')->unique()->count(),
            'base' => route('report.expenses'),
        ], [
            'types' => ExpenseTypeModel::orderBy('name')->get(['id', 'name']),
            'customers' => PartyModel::selectList('customer', $partyId),
            'byType' => $byType,
        ])->toResponse($req);
    }

    public function files(Request $req)
    {
        $req->validate([
            'party_type' => ['nullable', Rule::in(array_keys(PartyModel::TYPES))],
            'party_id' => 'nullable|integer|exists:party,id',
            'status' => 'nullable|string',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
        ]);

        $partyType = $req->query('party_type', 'customer');
        $partyId = $req->query('party_id');
        $status = $req->query('status');
        $from = $req->query('from');
        $to = $req->query('to');

        if ($status && $status !== 'open' && ! array_key_exists($status, WorkFileModel::STATUSES)) {
            $status = null;
        }

        // A party filter belonging to the other side would silently return
        // nothing, which reads as "no work" rather than "wrong filter".
        if ($partyId && PartyModel::whereKey($partyId)->value('party_type') !== $partyType) {
            $partyId = null;
        }

        $rows = WorkFileModel::report($partyType, $partyId, $status, $from, $to);

        $balances = PartyLedgerModel::balancesFor($rows->pluck('party_id')->unique()->filter()->all());

        // The latest note against each file — what is pending, or why it stands
        // where it does. Fetched for the whole report in one query.
        $remarks = WorkFileModel::latestRemarks($rows->pluck('id')->all());

        // The works behind each file, for the columns that reach a
        // spreadsheet. One query for the whole report.
        $breakdown = WorkFileModel::workBreakdown($rows->pluck('id')->all());

        // Grouped once here so the view only lays out what it is given.
        $groups = [];
        /*
         * unpriced counts the files whose margin cannot be known yet, so the
         * margin total can say what it is a total of. Summing them as zero and
         * saying nothing would report a figure that quietly covers fewer files
         * than the one beside it.
         */
        $totals = ['files' => 0, 'billed' => 0.0, 'cost' => 0.0, 'margin' => 0.0, 'unpriced' => 0];

        foreach ($rows as $row) {
            $line = WorkFileModel::rowTotals($row);
            $key = $row->party_id;

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'id' => $row->party_id,
                    'name' => $row->party_name,
                    'mobile' => $row->party_mobile,
                    'balance' => $balances[$row->party_id] ?? 0.0,
                    'files' => 0,
                    'billed' => 0.0,
                    'cost' => 0.0,
                    'margin' => 0.0,
                    'open' => 0,
                    'rows' => [],
                ];
            }

            $groups[$key]['files']++;
            $groups[$key]['billed'] += $line['billed'];
            $groups[$key]['cost'] += $line['cost'];
            // Null is a margin that cannot be worked out yet, not a zero.
            $groups[$key]['margin'] += $line['margin'] ?? 0.0;
            $groups[$key]['open'] += in_array($row->status, WorkFileModel::OPEN_STATUSES, true) ? 1 : 0;
            $groups[$key]['rows'][] = [
                'row' => $row,
                'totals' => $line,
                'remark' => $remarks[$row->id] ?? null,
            ];

            $totals['files']++;
            $totals['billed'] += $line['billed'];
            $totals['cost'] += $line['cost'];
            $totals['margin'] += $line['margin'] ?? 0.0;
            $totals['unpriced'] += $line['margin'] === null ? 1 : 0;
        }

        $partyLabel = PartyModel::label($partyType);
        $statuses = WorkFileModel::STATUSES;

        $periodText = ($from || $to)
            ? ($from ? date('d-m-Y', strtotime($from)) : 'Beginning').' to '.($to ? date('d-m-Y', strtotime($to)) : date('d-m-Y'))
            : 'All dates';

        $statusText = $status === 'open' ? 'Work in hand' : ($status ? $statuses[$status] : 'All statuses');

        // The other side of the file, named for the side it holds so it cannot be
        // mistaken for the party the report is grouped by.
        $counterpartyLabel = $partyType === 'vendor' ? 'Received From' : 'Given To';

        /*
         * Rows are flattened out of the groups built above, so every figure here
         * is the one already computed — nothing is recalculated and nothing is
         * fetched again.
         */
        $reportRows = [];

        foreach ($groups as $group) {
            /*
             * A band carries one line of text, so the heading is composed here.
             * Prefixed with the party label because a bare name could belong to
             * either side, and the balance is written through formatBalance() so
             * it reads "1,200.00 Cr" rather than carrying a minus sign.
             *
             * No counts in it. This text is fixed the moment the page is drawn,
             * while the grid drops rows as the reader searches, so a heading
             * counting files ended up standing over a subtotal covering fewer of
             * them — "4 files" above one row. What is left holds under any
             * search: the party is the party, and the ledger balance is the
             * party's whole balance rather than anything summed from these rows.
             * The rows shown are answered for by the subtotal beneath them,
             * which the grid recomputes from exactly those rows.
             */
            $band = $partyLabel.' — '.$group['name']
                .' · Ledger balance '.PartyLedgerModel::formatBalance($group['balance']);

            foreach ($group['rows'] as $line) {
                $row = $line['row'];

                /*
                 * The works this row is about: all of the folder's, or — for a
                 * folder split between vendors, drawn once under each — only the
                 * ones this vendor holds. What the row lists, what its status
                 * note says and what its Update button moves all come from here.
                 */
                $works = $breakdown[$row->id] ?? [];

                if (isset($row->split_item_ids)) {
                    $works = array_values(array_filter(
                        $works,
                        fn ($work) => in_array((int) $work->id, $row->split_item_ids, true)
                    ));
                }

                $split = WorkFileModel::workSplit($works);

                $reportRows[] = [
                    'id' => (int) $row->id,
                    // Banded on the id, never the name: only (party_type, mobile)
                    // is unique on a party, so two parties may share a name, and
                    // grouping on it merged them into one band with their money
                    // added together.
                    'party_id' => (int) $group['id'],
                    'party_band' => $band,
                    'party_name' => $group['name'],
                    // Not a column: drawn nowhere and exported nowhere. The band's
                    // WhatsApp button reads it off the first row under it.
                    'party_mobile' => $group['mobile'],
                    'file_no' => $row->file_no,
                    'registration_no' => $row->registration_no,
                    'received' => date('d-m-Y', strtotime($row->received_date)),
                    // Sorted on separately, never shown: dd-mm-yyyy compared as
                    // text orders by day of the month, putting the 2nd of March
                    // above the 1st of December.
                    'received_sort' => date('Y-m-d', strtotime($row->received_date)),

                    // The day it went to the vendor, and how long it has been there.
                    'dispatched' => $row->vendor_date ? date('d-m-Y', strtotime($row->vendor_date)) : null,
                    'dispatched_sort' => $row->vendor_date ? date('Y-m-d', strtotime($row->vendor_date)) : null,
                    'days_out' => WorkFileModel::daysOutText($row->vendor_date, $row->status, $row->finished_on),
                    'work_type' => $row->work_type,
                    'description' => $row->description,
                    'counterparty' => $partyType === 'vendor' ? $row->customer_name : ($row->vendor_name ?: 'In-house'),
                    'status' => $statuses[$row->status] ?? $row->status,
                    'status_key' => $row->status,

                    /*
                     * Which works are through and which are not, as fields a
                     * spreadsheet can sort and filter. A status of "Partly
                     * Approved" says they disagree and never which way.
                     */
                    // "HPA approved · HPT, TR pending", under the works it is
                    // about. A status of Partly Approved says they disagree
                    // and never which way.
                    'works_note' => WorkFileModel::workNote($works),

                    'works_done' => $split['done'],
                    'works_approved_on' => $split['approved_on'],
                    'works_pending' => $split['pending'],

                    /*
                     * The works themselves, for the dialog that moves them
                     * along. Statuses belong to works and not to folders — a
                     * transfer can be approved on Tuesday with the
                     * hypothecation addition still pending on Friday — so this
                     * is what the update posts against.
                     */
                    'items' => array_map(fn ($work) => [
                        'id' => (int) $work->id,
                        'work_type' => $work->name,
                        'status' => $work->status,
                        'status_label' => WorkFileModel::STATUSES[$work->status] ?? $work->status,
                        'approved_on_iso' => $work->approved_on ? date('Y-m-d', strtotime($work->approved_on)) : null,
                        // Whether an approval already has evidence behind it,
                        // never where it is: the dialog only needs to know
                        // whether to insist on another.
                        'has_screenshot' => (bool) $work->approval_screenshot,
                    ], $works),

                    // The word on the button, and nothing on a folder with no
                    // works to move.
                    'update' => ($works) ? 'Update' : null,
                    // The latest note against the file: what is pending, or why it
                    // stands where it does.
                    'remark' => $line['remark'],
                    'billed' => (float) $line['totals']['billed'],
                    'cost' => (float) $line['totals']['cost'],
                    // What the office paid out of its own till, included in the
                    // cost beside it and broken out so a margin can be read.
                    'expenses' => $line['totals']['expenses'] > 0 ? (float) $line['totals']['expenses'] : null,
                    // Left null so the cell is empty rather than stating a loss
                    // on work nobody has priced.
                    'margin' => $line['totals']['margin'] === null ? null : (float) $line['totals']['margin'],
                ];
            }
        }

        /*
         * Everything except the internal grouping key is exported.
         *
         * The seven required columns — File No., Received, Work Type, Details,
         * the counterparty, Status, Remarks — are all in here, but they cannot
         * be the whole export: on screen the rows are banded by party, and a
         * spreadsheet has no bands. Export only those seven from a
         * customer-wise report and the single party column left in the file is
         * the vendor, so every row arrives detached from the customer it
         * belongs to and the file reads as a vendor report. That exact
         * confusion was reported once already on screen; it must not come back
         * in the export.
         */
        $props = [
            'title' => $partyLabel.'-wise Work Report — '.$periodText.' · '.$statusText,

            /*
             * What the update dialog needs. The statuses and the rules come
             * from the model and the status screen rather than being restated
             * here: this dialog posts to that controller, and a second copy of
             * the list is a second thing to keep in step with the first.
             */
            'action' => route('workfile.status'),
            'csrf' => csrf_token(),
            // Back to this report, filtered and sorted as the reader left it.
            'returnTo' => $req->fullUrl(),
            'jobStatuses' => WorkFileModel::JOB_STATUSES,
            'pendencyKey' => WorkFileModel::PAPER_PENDENCY,
            'cancelledKey' => WorkFileModel::CANCELLED,
            'approvedKey' => WorkFileModel::APPROVED,
            'reasonKeys' => [WorkFileModel::CANCELLED, WorkFileModel::RETURNED],
            'today' => now()->toDateString(),

            /*
             * Which report this is, because only the vendor-wise one offers to
             * send its list. A customer is never told a file went to a vendor —
             * see WorkFileModel::CUSTOMER_STATUSES — and a customer's list of
             * dispatch dates and days out would tell them exactly that.
             */
            'partyType' => $partyType,
            // The date a sent list is "as of".
            'todayLabel' => now()->format('d-m-Y'),
            'groupBy' => 'party_id',
            'groupLabel' => 'party_band',
            'totals' => ['billed' => 'sum', 'cost' => 'sum', 'expenses' => 'sum', 'margin' => 'sum'],
            // Paging off in all but name: a party split across two pages would be
            // banded twice and subtotalled twice, each time on half its files.
            'perPage' => max(count($reportRows), 1),
            'emptyText' => ($partyId || $status || $from || $to)
                ? 'No work files match this report. Try widening the dates, or clearing the status.'
                : 'No work files yet. Receive one and it will appear here.',
            /*
             * What else this report can say, offered rather than shown.
             *
             * Fifteen columns wide, it answered the question it is opened for —
             * what this party's work was, and what it billed — underneath four
             * columns of free text and derived figures that nobody reads on the
             * way past. Those are grouped by the question they answer now and
             * turned on when it is being asked, and the choice is remembered.
             *
             * Nothing leaves the exports: a spreadsheet has room for all of it
             * and no reason to hide any, which is what lets the screen be short.
             */
            'groups' => [
                ['key' => 'dispatch', 'label' => 'Dispatch'],
                ['key' => 'margin', 'label' => 'Expenses & margin'],
                ['key' => 'detail', 'label' => 'Details & remarks'],
            ],
            'columns' => [
                ['key' => 'party_id', 'label' => $partyLabel.' Id', 'hidden' => true],
                /*
                 * Exported, never drawn. The rows are banded by party and the
                 * band above them already reads "Customer — Car4Sales · Ledger
                 * balance ...", so a column repeating that name on every row
                 * underneath it said nothing the reader could not already see.
                 *
                 * It stays searchable, because search runs over what a column
                 * exports rather than what it draws — typing a party's name
                 * still narrows the report to them.
                 */
                ['key' => 'party_name', 'label' => $partyLabel, 'exportOnly' => true],
                ['key' => 'file_no', 'label' => 'File No.'],
                ['key' => 'registration_no', 'label' => 'Vehicle'],
                // Sorted on the ISO date carried alongside it, so the order is
                // chronological rather than by day of the month.
                ['key' => 'received', 'label' => 'Received', 'sortBy' => 'received_sort'],
                // When it went out, and how long it has been out. Newest first
                // on the first click; see the files list.
                ['key' => 'dispatched', 'label' => 'Dispatched', 'sortBy' => 'dispatched_sort',
                    'sortDesc' => true, 'sub' => 'days_out', 'group' => 'dispatch'],
                ['key' => 'work_type', 'label' => 'Work Type', 'note' => 'works_note'],
                ['key' => 'description', 'label' => 'Details', 'group' => 'detail'],
                ['key' => 'counterparty', 'label' => $counterpartyLabel],
                ['key' => 'status', 'label' => 'Status', 'type' => 'badge'],
                // Exported, never drawn: a work a row apiece is what a
                // spreadsheet is for. See exportOnly in DataGrid.
                ['key' => 'works_done', 'label' => 'Approved Works', 'exportOnly' => true],
                ['key' => 'works_approved_on', 'label' => 'Approved On', 'exportOnly' => true],
                ['key' => 'works_pending', 'label' => 'Pending Works', 'exportOnly' => true],
                ['key' => 'remark', 'label' => 'Remarks', 'group' => 'detail'],
                // Billed and cost are the two sides of the file and stay. What
                // the office paid out of its own till, and what was left after
                // it, are a further question.
                ['key' => 'billed', 'label' => 'Billed', 'type' => 'money'],
                ['key' => 'cost', 'label' => 'Cost', 'type' => 'money'],
                ['key' => 'expenses', 'label' => 'Expenses', 'type' => 'money', 'group' => 'margin'],
                ['key' => 'margin', 'label' => 'Margin', 'type' => 'money', 'group' => 'margin'],

                /*
                 * Moving a file along without leaving the report. Kept out of
                 * the exports and the search text for the reason every action
                 * column is: a column of the word "Update" is not data, and
                 * searched, every row matches anyone typing it.
                 */
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
            'rows' => $reportRows,
        ];

        /*
         * The three states anyone actually asks for — what is through, what is
         * still being chased, and what has not left the office. They are all
         * reachable from the status list beside them; these are the ones asked
         * for often enough to be worth a click rather than three.
         */
        $carry = array_filter([
            'party_type' => $partyType,
            'party_id' => $partyId,
            'from' => $from,
            'to' => $to,
        ]);

        $views = [
            '' => 'Everything',
            WorkFileModel::APPROVED => 'Approved',
            'open' => 'Still pending',
            'in_office' => 'In office',
        ];

        return Screen::make('admin.reports.files', 'vue-work-report', $props, [
            'views' => $views,
            'viewUrls' => collect($views)
                ->map(fn ($label, $key) => route('report.files', $key === '' ? $carry : $carry + ['status' => $key]))
                ->all(),
            // What an empty report means, and therefore what it should advise.
            'filtered' => (bool) ($partyId || $status || $from || $to),
            'partyType' => $partyType,
            'partyLabel' => $partyLabel,
            'partyId' => $partyId ? (int) $partyId : null,
            'status' => $status,
            'statusText' => $statusText,
            'periodText' => $periodText,
            'from' => $from,
            'to' => $to,
            'totals' => $totals,
            'statuses' => $statuses,
            'parties' => PartyModel::selectList($partyType, $partyId),
            'maxDate' => now()->toDateString(),
            'groupCount' => count($groups),
        ])->toResponse($req);
    }
    /**
     * How long each vendor takes.
     *
     * Batches are handed out on a memory of who was quick last time. This is
     * the same judgement with the numbers behind it: what each vendor is
     * sitting on now, how long the oldest of it has waited, and how long the
     * work they did finish actually took.
     *
     * The period is the day the work was given out, so a row follows one batch
     * through instead of mixing what went out this month with what came back.
     */
    public function vendors(Request $req)
    {
        $req->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
        ]);

        $from = $req->query('from');
        $to = $req->query('to');

        $rows = WorkFileModel::vendorPerformance($from, $to);

        $periodText = ($from || $to)
            ? 'Given out '.($from ? date('d-m-Y', strtotime($from)) : 'from the beginning')
                .' to '.($to ? date('d-m-Y', strtotime($to)) : date('d-m-Y'))
            : 'All work ever given out';

        $totals = [
            'vendors' => $rows->count(),
            'out_now' => (int) $rows->sum('out_now'),
            'oldest' => (int) $rows->max('longest_out'),
            'finished' => (int) $rows->sum('finished'),
        ];

        $props = [
            'title' => 'Vendors — '.$periodText,
            'perPage' => 100,
            'emptyText' => ($from || $to)
                ? 'No work went out to a vendor in this period. Try widening the dates.'
                : 'No work has been given to a vendor yet.',
            'totals' => ['files' => 'sum', 'out_now' => 'sum', 'finished' => 'sum'],
            'columns' => [
                // The statement is where the money side of the same vendor is.
                ['key' => 'vendor', 'label' => 'Vendor', 'type' => 'link', 'linkTo' => 'vendor_url'],
                ['key' => 'files', 'label' => 'Files', 'type' => 'count'],
                ['key' => 'out_now', 'label' => 'Out Now', 'type' => 'count', 'sub' => 'out_now_note'],
                /*
                 * The one figure to act on: the oldest thing this vendor is
                 * still holding. Everything else on the row is history.
                 */
                ['key' => 'longest_out', 'label' => 'Waiting', 'type' => 'count', 'class' => 'fw-bold'],
                ['key' => 'finished', 'label' => 'Finished', 'type' => 'count'],
                ['key' => 'average_days', 'label' => 'Average', 'type' => 'count', 'sub' => 'average_note'],
                ['key' => 'slowest', 'label' => 'Slowest', 'type' => 'count'],
            ],
            'rows' => $rows->map(fn ($row) => [
                'id' => (int) $row->vendor_id,
                'vendor' => $row->vendor_name,
                'vendor_url' => route('party.statement', $row->vendor_id),
                'files' => (int) $row->files,
                'out_now' => (int) $row->out_now,
                // Days, said once under the count rather than in every cell.
                'out_now_note' => (int) $row->out_now ? 'still with them' : null,
                'longest_out' => $row->longest_out === null ? null : (int) $row->longest_out,
                'finished' => (int) $row->finished,
                'average_days' => $row->average_days === null ? null : (int) $row->average_days,
                /*
                 * An average over two files is not a record. Said on the row,
                 * because a vendor judged on one lucky week is judged wrongly.
                 */
                'average_note' => (int) $row->finished > 0 && (int) $row->finished < 3
                    ? 'on '.$row->finished.' '.($row->finished == 1 ? 'file' : 'files')
                    : null,
                'slowest' => $row->slowest === null ? null : (int) $row->slowest,
            ])->values(),
        ];

        return Screen::make('admin.reports.vendors', 'vue-vendor-report', $props, [
            'from' => $from,
            'to' => $to,
            'periodText' => $periodText,
            'totals' => $totals,
            'maxDate' => now()->toDateString(),
            'base' => route('report.vendors'),
        ])->toResponse($req);
    }
    /**
     * Work that is done and not paid for.
     *
     * The ledger says what each customer owes in one figure. What it does not
     * say is which jobs that figure is made of, and "you owe 62,000" is an
     * argument where "these four files, the oldest from July" is a
     * conversation. Money arrives against the account rather than against a
     * file, so the oldest charge is treated as settled first — said on the
     * screen, because it is a convention and not a fact.
     *
     * Finished work only, unless asked otherwise: a file that came in
     * yesterday is not money nobody collected, it is work in progress.
     */
    public function uncollected(Request $req)
    {
        $req->validate([
            'party_id' => 'nullable|integer|exists:party,id',
            'show' => ['nullable', Rule::in(['finished', 'all'])],
        ]);

        $partyId = $req->query('party_id');
        $show = $req->query('show', 'finished');

        $rows = WorkFileModel::uncollected($partyId, $show === 'all');

        $totals = [
            'files' => $rows->count(),
            'customers' => $rows->pluck('customer_id')->unique()->count(),
            'outstanding' => (float) $rows->sum('outstanding'),
            'oldest' => (int) $rows->max('days'),
        ];

        $props = [
            'title' => 'Not Yet Collected'.($show === 'all' ? ' — every file with something owing' : ''),
            'perPage' => 100,
            'emptyText' => $show === 'all'
                ? 'Nothing is owed on any file. Every charge on the ledger has been paid.'
                : 'Nothing finished is waiting to be paid for.',
            'totals' => ['charged' => 'sum', 'outstanding' => 'sum'],
            /*
             * One band per customer, each with what that customer owes under
             * it, because the list is read to decide who to ring — and each
             * band carries the message to send them instead.
             */
            'groupBy' => 'customer_id',
            'groupLabel' => 'customer',
            'todayLabel' => now()->format('d-m-Y'),
            'columns' => [
                ['key' => 'file_no', 'label' => 'File No.', 'type' => 'link', 'linkTo' => 'edit_url'],
                ['key' => 'registration_no', 'label' => 'Vehicle'],
                ['key' => 'works', 'label' => 'Work'],
                ['key' => 'customer', 'label' => 'Customer', 'type' => 'link', 'linkTo' => 'customer_url'],
                // The day the work finished, and how long the money has been
                // outstanding since — sorted on the ISO date beside it.
                ['key' => 'finished', 'label' => 'Finished', 'sortBy' => 'finished_raw', 'sortDesc' => true,
                    'sub' => 'days_text'],
                /*
                 * Papers already handed back are the ones to chase first: the
                 * customer has what they came for and the office has nothing
                 * left to hold.
                 */
                ['key' => 'handed_over', 'label' => 'Papers', 'sortBy' => 'handed_over_raw'],
                ['key' => 'charged', 'label' => 'Charged', 'type' => 'money'],
                ['key' => 'outstanding', 'label' => 'Outstanding', 'type' => 'money', 'class' => 'dr fw-bold',
                    'sub' => 'part_paid'],
            ],
            'rows' => $rows->values(),
        ];

        return Screen::make('admin.reports.uncollected', 'vue-uncollected-report', $props, [
            'show' => $show,
            'partyId' => $partyId ? (int) $partyId : null,
            'parties' => PartyModel::selectList('customer', $partyId),
            'totals' => $totals,
            'base' => route('report.uncollected'),
            'showUrls' => [
                'finished' => route('report.uncollected', array_filter(['party_id' => $partyId])),
                'all' => route('report.uncollected', array_filter(['party_id' => $partyId, 'show' => 'all'])),
            ],
        ])->toResponse($req);
    }
}