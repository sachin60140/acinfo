<?php

namespace App\Http\Controllers;

use App\Models\ClientLedgerModel;
use App\Models\ExpenseTypeModel;
use App\Models\PartyLedgerModel;
use App\Models\PartyModel;
use App\Models\WorkFileExpenseModel;
use App\Models\WorkFileModel;
use App\Support\Screen;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'vendor_id' => ['nullable', 'integer', Rule::exists('party', 'id')->where('party_type', 'vendor')],
            'vehicle' => 'nullable|string|max:20',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
        ]);

        $typeId = $req->query('expense_type_id');
        $partyId = $req->query('party_id');
        $from = $req->query('from');
        $to = $req->query('to');

        /*
         * One vendor, and everything their files cost: what was agreed with
         * them, and every expense paid out on the files they were given.
         * Asked for by the owner after the vehicle search (2026-09-23).
         */
        $vendorId = $req->query('vendor_id') ? (int) $req->query('vendor_id') : null;
        $vendorName = $vendorId ? (string) PartyModel::whereKey($vendorId)->value('name') : '';

        /*
         * One vehicle, and everything its work cost.
         *
         * Asked for by registration number because that is what anybody asking
         * has in front of them — a file number is in the office, a number plate
         * is on the vehicle. Stored without spaces or dashes, so it is matched
         * that way and "br 05 as 6323" finds BR05AS6323.
         */
        $vehicle = WorkFileModel::normaliseRegistration($req->query('vehicle'));

        /*
         * The whole number, when it is one. BR05AS632 is a vehicle of its own
         * and BR05AS6321 is another that happens to contain it, so a number
         * typed out in full finds that vehicle and no other. Part of one — the
         * last four, as a plate is usually quoted — finds every vehicle it is
         * in, and the page says how many that was.
         */
        $exact = $vehicle !== '' && WorkFileModel::where('registration_no', $vehicle)->exists();
        $plate = fn ($q, string $column) => $exact
            ? $q->where($column, $vehicle)
            : $q->where($column, 'like', '%'.$vehicle.'%');

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

        if ($vehicle !== '') {
            $plate($query, 'work_file.registration_no');
        }

        /*
         * A vendor's files: any with a live work given to them, or a folder
         * with no works that names them itself — the same test their charges
         * are read by, so an expense is never shown on a file whose charge is
         * not. Everything paid out on such a file counts: a challan is paid
         * for the file, not for either vendor's part of a split folder, so
         * there it counts under both.
         */
        if ($vendorId) {
            $query->where(fn ($q) => $q
                ->whereExists(fn ($work) => $work->select(DB::raw(1))
                    ->from('work_file_item as vi')
                    ->whereColumn('vi.work_file_id', 'work_file.id')
                    ->where('vi.vendor_id', $vendorId)
                    ->where('vi.status', '<>', WorkFileModel::CANCELLED))
                ->orWhere(fn ($folder) => $folder
                    ->where('work_file.vendor_id', $vendorId)
                    ->whereNotExists(fn ($work) => $work->select(DB::raw(1))
                        ->from('work_file_item as ni')
                        ->whereColumn('ni.work_file_id', 'work_file.id'))));
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

        /*
         * Asked about one vehicle, or one vendor, the report answers with what
         * the work cost altogether: what was agreed with the vendor as well as
         * what was paid out on the file. Money the office is owed is no part
         * of a cost, and is not here — the margin is the Profit report's
         * question.
         */
        $costs = $vehicle !== '' || $vendorId;

        $vendorCosts = $costs
            ? self::vendorCosts($vehicle !== '' ? $plate : null, $partyId, $vendorId, $typeId, $from, $to)
            : collect();
        $rows = $rows->concat($vendorCosts);

        // One vehicle: banded by file, so each band subtotals what that file
        // cost. Otherwise by kind, which is what the report is read for.
        $byFile = $vehicle !== '';

        /*
         * File by file, and in each file in the order it happened: the grid
         * bands in the order a file first appears, and an export or a print
         * has no bands at all, so the rows themselves have to read right.
         */
        if ($byFile) {
            $rows = $rows->sortBy([
                fn ($a, $b) => (int) $a->file_id <=> (int) $b->file_id,
                fn ($a, $b) => strtotime((string) $a->spent_on) <=> strtotime((string) $b->spent_on),
                // The vendor's charge before what was spent on the same day.
                fn ($a, $b) => ((int) $a->type_id === 0 ? 0 : 1) <=> ((int) $b->type_id === 0 ? 0 : 1),
                fn ($a, $b) => abs((int) $a->id) <=> abs((int) $b->id),
            ])->values();
        } elseif ($vendorId) {
            /*
             * One vendor, over many files: by kind, as the report is read,
             * with what was agreed with them first and each kind after in
             * the order they happened — the bands follow the rows.
             */
            $rows = $rows->sortBy([
                fn ($a, $b) => ((int) $a->type_id === 0 ? 0 : 1) <=> ((int) $b->type_id === 0 ? 0 : 1),
                fn ($a, $b) => strcmp((string) $a->type_name, (string) $b->type_name),
                fn ($a, $b) => strtotime((string) $a->spent_on) <=> strtotime((string) $b->spent_on),
                fn ($a, $b) => abs((int) $a->id) <=> abs((int) $b->id),
            ])->values();
        }

        $fromText = $from ? date('d-m-Y', strtotime($from)) : 'Beginning';
        $toText = $to ? date('d-m-Y', strtotime($to)) : 'Till date';
        $periodText = $from || $to ? $fromText.' to '.$toText : 'All dates';

        $total = (float) $rows->sum('amount');

        /*
         * What the page is about, said plainly. Part of a number can match
         * more than one vehicle, and a total over two of them read as one
         * vehicle's cost is wrong without anything on the page being wrong.
         * A kind asked for leaves the vendor's charge out, so the page does
         * not call what is left the vehicle's cost.
         */
        $plates = $byFile ? $rows->pluck('registration_no')->filter()->unique()->sort()->values() : collect();
        $withVendor = $costs && ! $typeId;
        $heading = '';

        if ($costs) {
            $on = match (true) {
                ! $byFile => null,
                $plates->count() > 1 => $plates->count().' vehicles matching '.$vehicle,
                $plates->count() === 1 => $plates->first(),
                default => $vehicle,
            };

            $heading = ($withVendor ? 'Costs' : ExpenseTypeModel::whereKey($typeId)->value('name')).' on '.match (true) {
                $on !== null && $vendorId !== null => $on.', given to '.$vendorName,
                $on !== null => $on,
                default => 'files given to '.$vendorName,
            };
        }

        $props = [
            'title' => ($costs ? $heading.' — ' : 'Expenses — ').$periodText,
            'groupBy' => $byFile ? 'file_id' : 'type_id',
            'groupLabel' => $byFile ? 'file_band' : 'type_band',
            'totals' => ['amount' => 'sum'],
            // A kind split across two pages would be banded and subtotalled
            // twice, each time on half its expenses.
            'perPage' => max($rows->count(), 1),
            'sortable' => false,
            'emptyText' => match (true) {
                // A number mistyped reads as a vehicle that cost nothing
                // unless it is told apart from one.
                $rows->isEmpty() && $vehicle !== '' && ! $exact
                    && ! WorkFileModel::where('registration_no', 'like', '%'.$vehicle.'%')->exists() => 'No file has a vehicle number with '.$vehicle.' in it. Check the number.',
                $vehicle !== '' && ($typeId || $partyId || $vendorId || $from || $to) => 'Nothing on '.$vehicle.' matches this report. Try widening the dates, or clearing the kind, the customer or the vendor.',
                $vehicle !== '' => 'Nothing has been paid out on '.$vehicle.', and no vendor rate is agreed on its work.',
                $vendorId && ($typeId || $partyId || $from || $to) => 'Nothing on files given to '.$vendorName.' matches this report. Try widening the dates, or clearing the kind or the customer.',
                (bool) $vendorId => 'Nothing has been paid out on files given to '.$vendorName.', and no rate is agreed with them.',
                (bool) ($typeId || $partyId || $from || $to) => 'No expenses match this report. Try widening the dates, or clearing the kind.',
                default => 'Nothing recorded yet. Expenses are entered on a file, under the works.',
            },
            'columns' => [
                ['key' => 'type_id', 'label' => 'Kind Id', 'hidden' => true],
                // A vendor's rate is agreed on a day, not paid on it.
                ['key' => 'spent_on', 'label' => $costs ? 'Date' : 'Paid On', 'sortBy' => 'spent_sort'],
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
                // Which file it was spent on, for the band a vehicle is read in.
                'file_id' => (int) $one->file_id,
                'file_band' => trim($one->file_no.' · '.($one->registration_no ?: 'no vehicle')),
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
                // A rate reversed is netted into the figure, not another time.
                'count' => $group->where('amount', '>', 0)->count(),
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
            'vendorId' => $vendorId,
            'vehicle' => $vehicle,
            'heading' => $heading,
            'plates' => $plates,
            'withVendor' => $withVendor,
            'total' => $total,
            'count' => $rows->count(),
            'fileCount' => $rows->pluck('file_id')->unique()->count(),
            'base' => route('report.expenses'),
        ], [
            'types' => ExpenseTypeModel::orderBy('name')->get(['id', 'name']),
            'customers' => PartyModel::selectList('customer', $partyId),
            'vendors' => PartyModel::selectList('vendor', $vendorId),
            'byType' => $byType,
        ])->toResponse($req);
    }

    /**
     * What was agreed with the vendors on one vehicle's work, as expense rows.
     *
     * A vendor's rate is a cost of the file exactly as a challan is, and the
     * question "what did this vehicle cost us" is not answered without it. It
     * is not in work_file_expense, so it is read from the works and shaped like
     * the rows beside it rather than given a table of its own on the page.
     *
     * By the work, because that is how a rate is agreed: a folder split between
     * two vendors has two of them. A folder with no works carries its rate on
     * itself, and is read from there. Cancelled work charges nobody. A kind
     * filter is a filter on kinds of expense, so these are left out under one.
     *
     * A file the vendor handed back undone has its rate reversed, in whole or
     * in part, and that is a row of its own on the day it came back: the rate
     * stands as agreed and the reversal takes it off, so a file adds up to
     * exactly what WorkFileModel::SPENT says it cost — the Profit report and
     * the file's own screen — and the reader can see why.
     *
     * Each row is dated, and filtered by the dates asked for, on the day it is
     * shown under, so the period, the grid and the total always agree.
     *
     * Asked of one vendor, only what was agreed with them: their works, a
     * folder with no works that names them, and what they handed back of a
     * folder that is theirs. A folder split with another vendor shows each
     * their own works.
     *
     * @param  (\Closure(mixed, string): mixed)|null  $plate  narrows a query to the vehicle, when one was asked for
     * @return \Illuminate\Support\Collection<int, object>
     */
    private static function vendorCosts(?\Closure $plate, $partyId, ?int $vendorId, $typeId, $from, $to)
    {
        if ($typeId) {
            return collect();
        }

        $files = fn ($q, string $day) => $q
            ->leftJoin('party as customer', 'customer.id', '=', 'f.customer_id')
            // As a yes or no: when() calls a closure it is given as its condition.
            ->when($plate !== null, fn ($q) => $plate($q, 'f.registration_no'))
            ->where('f.status', '<>', WorkFileModel::CANCELLED)
            ->when($partyId, fn ($q) => $q->where('f.customer_id', $partyId))
            ->when($from, fn ($q) => $q->whereRaw($day.' >= ?', [$from]))
            ->when($to, fn ($q) => $q->whereRaw($day.' <= ?', [$to]))
            ->addSelect([
                DB::raw($day.' as day'),
                'f.id as file_id',
                'f.file_no',
                'f.registration_no',
                'f.status',
                'customer.name as customer_name',
            ]);

        $row = fn ($one, int $id, float $amount, string $remark) => (object) [
            // Apart from an expense id: the two are different rows in one
            // list, and the grid keys on this.
            'id' => $id,
            'amount' => $amount,
            'spent_on' => $one->day,
            'remark' => $remark,
            'file_id' => (int) $one->file_id,
            'file_no' => $one->file_no,
            'registration_no' => $one->registration_no,
            'status' => $one->status,
            'type_id' => 0,
            'type_name' => 'Vendor charge',
            'customer_name' => $one->customer_name,
        ];

        // The day it went out, or the day the papers came in if it has not
        // gone yet: a rate agreed is a cost from then.
        $works = DB::table('work_file_item as i')
            ->join('work_file as f', 'f.id', '=', 'i.work_file_id')
            ->leftJoin('work_type as t', 't.id', '=', 'i.work_type_id')
            ->leftJoin('party as v', 'v.id', '=', 'i.vendor_id')
            ->where('i.status', '<>', WorkFileModel::CANCELLED)
            ->where('i.vendor_amount', '>', 0)
            ->when($vendorId, fn ($q) => $q->where('i.vendor_id', $vendorId))
            ->select('i.id', 'i.vendor_amount as amount', 't.name as work', 'v.name as vendor_name')
            ->tap(fn ($q) => $files($q, 'COALESCE(i.vendor_date, f.received_date)'))
            ->get()
            ->map(fn ($one) => $row($one, -1 * (int) $one->id, (float) $one->amount, self::givenOut($one->work, $one->vendor_name)));

        $folders = DB::table('work_file as f')
            ->leftJoin('work_type as t', 't.id', '=', 'f.work_type_id')
            ->leftJoin('party as v', 'v.id', '=', 'f.vendor_id')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('work_file_item')
                ->whereColumn('work_file_item.work_file_id', 'f.id'))
            ->where('f.vendor_amount', '>', 0)
            ->when($vendorId, fn ($q) => $q->where('f.vendor_id', $vendorId))
            ->select('f.vendor_amount as amount', 't.name as work', 'v.name as vendor_name')
            ->tap(fn ($q) => $files($q, 'COALESCE(f.vendor_date, f.received_date)'))
            ->get()
            ->map(fn ($one) => $row($one, -1_000_000_000 - (int) $one->file_id, (float) $one->amount, self::givenOut($one->work, $one->vendor_name)));

        // What the vendor handed back is gated on the folder, as SPENT is:
        // the folder is back when every work on it is. Asked of one vendor,
        // a folder that is theirs — which a folder handed back is, the return
        // screen taking back only a folder with one vendor.
        $reversed = DB::table('work_file as f')
            ->leftJoin('party as v', 'v.id', '=', 'f.vendor_id')
            ->whereNotNull('f.vendor_returned_on')
            ->where('f.vendor_amount', '>', 0)
            ->when($vendorId, fn ($q) => $q->where('f.vendor_id', $vendorId))
            ->select(
                DB::raw('LEAST(COALESCE(f.vendor_returned_amount, f.vendor_amount), f.vendor_amount) as amount'),
                'v.name as vendor_name'
            )
            ->tap(fn ($q) => $files($q, 'f.vendor_returned_on'))
            ->get()
            ->filter(fn ($one) => (float) $one->amount > 0)
            ->map(fn ($one) => $row(
                $one,
                -2_000_000_000 - (int) $one->file_id,
                -1 * (float) $one->amount,
                'Handed back'.($one->vendor_name ? ' by '.$one->vendor_name : ' by the vendor').' · rate reversed'
            ));

        return $works->concat($folders)->concat($reversed)->values();
    }

    /** "TR · Parwez Ji · given out", or that it has not gone to anybody yet. */
    private static function givenOut(?string $work, ?string $vendor): string
    {
        return ($work ?: 'Work').($vendor ? ' · '.$vendor.' · given out' : ' · rate agreed, not given out yet');
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
            // What a refused save held, so the dialog opens again with it.
            'restore' => \App\Support\UpdateDialog::restore(),

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

    /**
     * Every customer who owes, the longest-owed first, each with a reminder.
     *
     * Not Yet Collected is file by file and finished work only, for the
     * conversation about particular jobs. This is the list to ring or message
     * down: one row a customer, what their statement says they owe — opening
     * balances, charges typed by hand and work still in progress included —
     * and since when. "Since" is the oldest charge still unpaid once their
     * payments have settled what they were adjusted against and then the
     * oldest charges first.
     *
     * The message is the statement's balance reminder, the same words from
     * either place, with no file, no vendor and no office note in it.
     */
    public function collection(Request $req)
    {
        $customers = PartyModel::withBalance('customer');
        $owing = $customers->filter(fn ($party) => (float) $party->current_balance > 0.005)->keyBy('id');

        $ids = $owing->keys()->map(fn ($id) => (int) $id)->all();
        $bills = PartyLedgerModel::dueBills($ids);

        $fileIds = collect($bills)->flatten(1)->pluck('file_id')->filter()->unique()->values()->all();
        $finishedFiles = $fileIds
            ? DB::table('work_file')->whereIn('id', $fileIds)
                ->whereIn('status', [WorkFileModel::APPROVED, WorkFileModel::RETURNED])
                ->pluck('id')->flip()->all()
            : [];

        // Balances carried from the old Client Ledger are dated the day they
        // were carried, so a row says so rather than look like a new debt.
        $looseIds = collect($bills)->flatten(1)->pluck('entry_id')->filter()->values()->all();
        $brought = $looseIds
            ? DB::table('party_ledger')->whereIn('id', $looseIds)
                ->where('particular', CloseClientLedgerController::BROUGHT)
                ->pluck('id')->flip()->all()
            : [];

        // What the office owes the same person as a vendor, where the two
        // accounts are linked — worth knowing before asking them for money.
        $vendorSide = collect(PartyModel::counterparts('customer'))->keyBy('own_id');

        $today = now()->startOfDay();

        $rows = $owing->map(function ($party) use ($bills, $finishedFiles, $brought, $vendorSide, $today) {
            $mine = $bills[(int) $party->id] ?? [];
            $owes = round((float) $party->current_balance, 2);
            $since = $mine ? $mine[0]['since'] : $today->toDateString();
            $days = max(0, (int) Carbon::parse($since)->startOfDay()->diffInDays($today));

            $files = array_filter($mine, fn ($bill) => $bill['file_id'] !== null);
            $loose = array_filter($mine, fn ($bill) => $bill['file_id'] === null);
            $old = round(array_sum(array_map(fn ($bill) => isset($brought[$bill['entry_id']]) ? $bill['due'] : 0, $loose)), 2);
            $typed = round(array_sum(array_column($loose, 'due')) - $old, 2);

            /*
             * A write-off whose bill has since gone takes nothing from any file
             * but still comes off the balance, so what the files say is due can
             * be more than the customer owes. What can be asked for is never
             * more than that.
             */
            $finished = min($owes, round(array_sum(array_map(
                fn ($bill) => isset($finishedFiles[$bill['file_id']]) ? $bill['due'] : 0,
                $files
            )), 2));

            $vendor = $vendorSide[(int) $party->id] ?? null;

            return [
                'id' => (int) $party->id,
                'customer' => (string) $party->name,
                'statement_url' => route('party.statement', $party->id),
                'inactive_note' => $party->is_active ? '' : 'Inactive',
                'setoff_note' => $vendor && $vendor['balance'] < -0.005
                    ? 'You owe them '.number_format(-$vendor['balance'], 2, '.', ',').' as a vendor'
                    : '',
                'mobile' => (string) $party->mobile,
                'whatsapp' => (string) ($party->whatsapp ?: $party->mobile),
                'owes' => $owes,
                'since' => date('d-m-Y', strtotime($since)),
                'since_raw' => $since,
                'days_text' => match (true) {
                    $days === 0 => 'today',
                    $days === 1 => '1 day',
                    default => $days.' days',
                },
                'days' => $days,
                'files' => count($files),
                // File by file, on Not Yet Collected — where there are files.
                'files_url' => $files ? route('report.uncollected', ['party_id' => $party->id, 'show' => 'all']) : null,
                'loose_note' => implode(' · ', array_filter([
                    $old > 0.005 ? number_format($old, 2, '.', ',').' from the old Client Ledger' : null,
                    $typed > 0.005 ? number_format($typed, 2, '.', ',').' not on a file' : null,
                ])),
                'finished' => $finished,
                'remind' => 'Remind',
            ];
        })
            // Longest owed first; of the same day, the most owed.
            ->sort(fn ($a, $b) => [$a['since_raw'], -$a['owes'], $a['customer']] <=> [$b['since_raw'], -$b['owes'], $b['customer']])
            ->values();

        $props = [
            'title' => 'Collection List',
            'perPage' => 100,
            'emptyText' => 'Nobody owes anything. Every customer is settled or has paid in advance.',
            'totals' => ['owes' => 'sum', 'finished' => 'sum'],
            'todayLabel' => now()->format('d-m-Y'),
            'columns' => [
                ['key' => 'customer', 'label' => 'Customer', 'type' => 'link', 'linkTo' => 'statement_url',
                    'note' => 'setoff_note', 'sub' => 'inactive_note'],
                ['key' => 'mobile', 'label' => 'Mobile'],
                ['key' => 'owes', 'label' => 'Owes', 'type' => 'money', 'class' => 'dr fw-bold'],
                ['key' => 'since', 'label' => 'Oldest Unpaid', 'sortBy' => 'since_raw', 'sub' => 'days_text'],
                ['key' => 'files', 'label' => 'Files', 'type' => 'link', 'linkTo' => 'files_url', 'sub' => 'loose_note'],
                ['key' => 'finished', 'label' => 'On Finished Work', 'type' => 'money'],
                ['key' => 'remind', 'label' => 'Remind', 'type' => 'action', 'icon' => 'bi-whatsapp', 'class' => 'cl-remind',
                    'sortable' => false, 'searchable' => false, 'exportable' => false],
                // Found by searching, as on the customer list.
                ['key' => 'inactive_note', 'label' => 'Status', 'hidden' => true],
            ],
            'rows' => $rows,
        ];

        $unbilled = WorkFileModel::pendingCounts()['customer'];

        return Screen::make('admin.reports.collection', 'vue-collection-list', $props, [
            'totals' => [
                'owes' => round((float) $rows->sum('owes'), 2),
                'customers' => $rows->count(),
                'oldest' => (int) $rows->max('days'),
                'finished' => round((float) $rows->sum('finished'), 2),
            ],
            // Money in the other direction, so it is not forgotten either.
            'inAdvance' => $customers->filter(fn ($party) => (float) $party->current_balance < -0.005)->count(),
            // Owed and on no list yet.
            'unbilled' => (int) $unbilled,
            'unbilledUrl' => route('workfile.index', ['pending' => 'customer']),
            'oldBook' => ClientLedgerModel::hasOpenBalances(),
            'oldBookUrl' => route('client.closebook'),
        ])->toResponse($req);
    }
}