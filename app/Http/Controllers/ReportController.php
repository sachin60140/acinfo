<?php

namespace App\Http\Controllers;

use App\Models\PartyLedgerModel;
use App\Models\PartyModel;
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
                $split = WorkFileModel::workSplit($breakdown[$row->id] ?? []);

                $reportRows[] = [
                    'id' => (int) $row->id,
                    // Banded on the id, never the name: only (party_type, mobile)
                    // is unique on a party, so two parties may share a name, and
                    // grouping on it merged them into one band with their money
                    // added together.
                    'party_id' => (int) $group['id'],
                    'party_band' => $band,
                    'party_name' => $group['name'],
                    'file_no' => $row->file_no,
                    'registration_no' => $row->registration_no,
                    'received' => date('d-m-Y', strtotime($row->received_date)),
                    // Sorted on separately, never shown: dd-mm-yyyy compared as
                    // text orders by day of the month, putting the 2nd of March
                    // above the 1st of December.
                    'received_sort' => date('Y-m-d', strtotime($row->received_date)),
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
                    'works_done' => $split['done'],
                    'works_approved_on' => $split['approved_on'],
                    'works_pending' => $split['pending'],
                    // The latest note against the file: what is pending, or why it
                    // stands where it does.
                    'remark' => $line['remark'],
                    'billed' => (float) $line['totals']['billed'],
                    'cost' => (float) $line['totals']['cost'],
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
            'groupBy' => 'party_id',
            'groupLabel' => 'party_band',
            'totals' => ['billed' => 'sum', 'cost' => 'sum', 'margin' => 'sum'],
            // Paging off in all but name: a party split across two pages would be
            // banded twice and subtotalled twice, each time on half its files.
            'perPage' => max(count($reportRows), 1),
            'emptyText' => ($partyId || $status || $from || $to)
                ? 'No work files match this report. Try widening the dates, or clearing the status.'
                : 'No work files yet. Receive one and it will appear here.',
            'columns' => [
                ['key' => 'party_id', 'label' => $partyLabel.' Id', 'hidden' => true],
                ['key' => 'party_name', 'label' => $partyLabel],
                ['key' => 'file_no', 'label' => 'File No.'],
                ['key' => 'registration_no', 'label' => 'Vehicle'],
                // Sorted on the ISO date carried alongside it, so the order is
                // chronological rather than by day of the month.
                ['key' => 'received', 'label' => 'Received', 'sortBy' => 'received_sort'],
                ['key' => 'work_type', 'label' => 'Work Type'],
                ['key' => 'description', 'label' => 'Details'],
                ['key' => 'counterparty', 'label' => $counterpartyLabel],
                ['key' => 'status', 'label' => 'Status', 'type' => 'badge'],
                // Exported, never drawn: the report is already thirteen
                // columns wide. See exportOnly in DataGrid.
                ['key' => 'works_done', 'label' => 'Approved Works', 'exportOnly' => true],
                ['key' => 'works_approved_on', 'label' => 'Approved On', 'exportOnly' => true],
                ['key' => 'works_pending', 'label' => 'Pending Works', 'exportOnly' => true],
                ['key' => 'remark', 'label' => 'Remarks'],
                ['key' => 'billed', 'label' => 'Billed', 'type' => 'money'],
                ['key' => 'cost', 'label' => 'Cost', 'type' => 'money'],
                ['key' => 'margin', 'label' => 'Margin', 'type' => 'money'],
            ],
            'rows' => $reportRows,
        ];

        return Screen::make('admin.reports.files', 'vue-work-report', $props, [
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
}
