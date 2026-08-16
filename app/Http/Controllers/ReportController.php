<?php

namespace App\Http\Controllers;

use App\Models\PartyLedgerModel;
use App\Models\PartyModel;
use App\Models\WorkFileModel;
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

        // Grouped once here so the view only lays out what it is given.
        $groups = [];
        $totals = ['files' => 0, 'billed' => 0.0, 'cost' => 0.0, 'margin' => 0.0];

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
            $groups[$key]['margin'] += $line['margin'];
            $groups[$key]['open'] += in_array($row->status, WorkFileModel::OPEN_STATUSES, true) ? 1 : 0;
            $groups[$key]['rows'][] = [
                'row' => $row,
                'totals' => $line,
                'remark' => $remarks[$row->id] ?? null,
            ];

            $totals['files']++;
            $totals['billed'] += $line['billed'];
            $totals['cost'] += $line['cost'];
            $totals['margin'] += $line['margin'];
        }

        return view('admin.reports.files', [
            'partyType' => $partyType,
            'partyLabel' => PartyModel::label($partyType),
            'partyId' => $partyId ? (int) $partyId : null,
            'status' => $status,
            'from' => $from,
            'to' => $to,
            'groups' => array_values($groups),
            'totals' => $totals,
            'statuses' => WorkFileModel::STATUSES,
            'parties' => PartyModel::selectList($partyType, $partyId),
        ]);
    }
}
