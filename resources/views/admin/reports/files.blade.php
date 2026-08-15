@extends('admin.layouts.app')

@section('title', $partyLabel . ' Report | Ac Info')

@section('style')
    @include('admin.layouts._statement-style')
    @include('admin.party._style')

    <style>
        .report-filter .form-label {
            color: #334155;
            font-size: 0.78rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .report-filter .form-control,
        .report-filter .form-select {
            min-height: 40px;
        }

        .type-switch a {
            border: 1px solid #dee2e6;
            border-radius: 5px;
            color: #4154f1;
            display: inline-block;
            font-size: 0.85rem;
            font-weight: 600;
            padding: 0.35rem 0.9rem;
            text-decoration: none;
        }

        .type-switch a.active {
            background: #4154f1;
            border-color: #4154f1;
            color: #fff;
        }

        .grand-total-bar {
            align-items: baseline;
            background: #012970;
            border-radius: 7px;
            color: #fff;
            display: flex;
            flex-wrap: wrap;
            font-weight: 700;
            gap: 1rem;
            justify-content: space-between;
            margin-top: 0.9rem;
            padding: 0.75rem 1rem;
        }

        .grand-total-bar .figures {
            display: flex;
            flex-wrap: wrap;
            font-variant-numeric: tabular-nums;
            gap: 1.5rem;
        }

        /* The grid prints itself into a clean window, but Ctrl+P on the page
           still has to produce something a person can hand over. */
        @media print {
            .pagetitle nav,
            .report-filter,
            .type-switch,
            .no-print {
                display: none !important;
            }

            .card {
                border: 0 !important;
                box-shadow: none !important;
            }
        }
    </style>
@endsection

@section('content')
    @php
        $today = now();
        $periodText = ($from || $to)
            ? ($from ? date('d-m-Y', strtotime($from)) : 'Beginning') . ' to ' . ($to ? date('d-m-Y', strtotime($to)) : date('d-m-Y'))
            : 'All dates';
        $statusText = $status === 'open' ? 'Work in hand' : ($status ? $statuses[$status] : 'All statuses');
        $work = \App\Models\WorkFileModel::class;

        // The other side of the file, named for the side it holds so it cannot be
        // mistaken for the party the report is grouped by.
        $counterpartyLabel = $partyType === 'vendor' ? 'Received From' : 'Given To';

        /*
         * Rows are flattened out of the groups the controller already built, so
         * every figure here is the one it computed — nothing is recalculated and
         * nothing is fetched again.
         */
        $reportRows = [];

        foreach ($groups as $group) {
            /*
             * A band carries one line of text, so the heading is composed here.
             * Prefixed with the party label because a bare name could belong to
             * either side, and the balance is written through formatBalance() so
             * it reads "1,200.00 Cr" rather than carrying a minus sign.
             */
            $band = $partyLabel . ' — ' . $group['name']
                . ' · ' . $group['files'] . ' ' . Str::plural('file', $group['files'])
                . ($group['open'] ? ' · ' . $group['open'] . ' in hand' : '')
                . ' · Ledger balance ' . \App\Models\PartyLedgerModel::formatBalance($group['balance']);

            foreach ($group['rows'] as $line) {
                $row = $line['row'];

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
                    'work_type' => $row->work_type,
                    'description' => $row->description,
                    'counterparty' => $partyType === 'vendor' ? $row->customer_name : ($row->vendor_name ?: 'In-house'),
                    'status' => $work::STATUSES[$row->status] ?? $row->status,
                    'status_key' => $row->status,
                    // The latest note against the file: what is pending, or why it
                    // stands where it does.
                    'remark' => $line['remark'],
                    'billed' => (float) $line['totals']['billed'],
                    'cost' => (float) $line['totals']['cost'],
                    'margin' => (float) $line['totals']['margin'],
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
        $gridProps = [
            'title' => $partyLabel . '-wise Work Report — ' . $periodText . ' · ' . $statusText,
            'groupBy' => 'party_id',
            'groupLabel' => 'party_band',
            'totals' => ['billed' => 'sum', 'cost' => 'sum', 'margin' => 'sum'],
            // Paging off in all but name: a party split across two pages would be
            // banded twice and subtotalled twice, each time on half its files.
            'perPage' => max(count($reportRows), 1),
            'emptyText' => 'No work files match this report.',
            'columns' => [
                ['key' => 'party_id', 'label' => $partyLabel . ' Id', 'hidden' => true],
                ['key' => 'party_name', 'label' => $partyLabel],
                ['key' => 'file_no', 'label' => 'File No.'],
                ['key' => 'registration_no', 'label' => 'Vehicle'],
                // Unsortable: the grid sorts on the value it shows, and dd-mm-yyyy
                // sorted as text orders by day of the month. Left alone, the rows
                // stand in the order the server sent — party, then date, then id.
                ['key' => 'received', 'label' => 'Received', 'sortable' => false],
                ['key' => 'work_type', 'label' => 'Work Type'],
                ['key' => 'description', 'label' => 'Details'],
                ['key' => 'counterparty', 'label' => $counterpartyLabel],
                ['key' => 'status', 'label' => 'Status', 'type' => 'badge'],
                ['key' => 'remark', 'label' => 'Remarks'],
                ['key' => 'billed', 'label' => 'Billed', 'type' => 'money'],
                ['key' => 'cost', 'label' => 'Cost', 'type' => 'money'],
                ['key' => 'margin', 'label' => 'Margin', 'type' => 'money'],
            ],
            'rows' => $reportRows,
        ];
    @endphp

    <div class="pagetitle">
        <h1>{{ $partyLabel }}-wise Work Report</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item active">Reports</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">

        <div class="card no-print">
            <div class="card-body pt-4">
                <div class="type-switch mb-3">
                    @foreach (\App\Models\PartyModel::TYPES as $key => $label)
                        {{-- Switching side drops the party filter: an id from the
                             other side would match nothing and read as "no work". --}}
                        <a href="{{ route('report.files', array_filter(['party_type' => $key, 'status' => $status, 'from' => $from, 'to' => $to])) }}"
                           class="{{ $partyType === $key ? 'active' : '' }}">{{ $label }}-wise</a>
                    @endforeach
                </div>

                <form method="GET" action="{{ route('report.files') }}" class="row g-2 align-items-end report-filter">
                    <input type="hidden" name="party_type" value="{{ $partyType }}">

                    <div class="col-md-3">
                        <label for="party_id" class="form-label">{{ $partyLabel }}</label>
                        <select class="form-select" id="party_id" name="party_id">
                            <option value="">All {{ strtolower($partyLabel) }}s</option>
                            @foreach ($parties as $party)
                                <option value="{{ $party->id }}" {{ $partyId === (int) $party->id ? 'selected' : '' }}>{{ $party->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="">All statuses</option>
                            <option value="open" {{ $status === 'open' ? 'selected' : '' }}>Work in hand</option>
                            @foreach ($statuses as $key => $text)
                                <option value="{{ $key }}" {{ $status === $key ? 'selected' : '' }}>{{ $text }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label for="from_display" class="form-label">From</label>
                        @include('partials._datefield', ['name' => 'from', 'value' => $from, 'max' => $today->toDateString()])
                    </div>

                    <div class="col-md-2">
                        <label for="to_display" class="form-label">To</label>
                        @include('partials._datefield', ['name' => 'to', 'value' => $to, 'max' => $today->toDateString()])
                    </div>

                    <div class="col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-fill">Apply</button>
                        <a href="{{ route('report.files', ['party_type' => $partyType]) }}" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body pt-4">

                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                    <div>
                        <h5 class="card-title p-0 m-0">{{ $partyLabel }}-wise Work Report</h5>
                        <div class="statement-period">
                            {{ $periodText }} &nbsp;&middot;&nbsp; {{ $statusText }}
                            &nbsp;&middot;&nbsp; {{ count($groups) }} {{ Str::plural(strtolower($partyLabel), count($groups)) }}
                            &nbsp;&middot;&nbsp; {{ $totals['files'] }} {{ Str::plural('file', $totals['files']) }}
                        </div>
                    </div>
                </div>

                <div class="statement-summary mb-3">
                    <div class="stat">
                        <span class="label">Files</span>
                        <span class="value">{{ $totals['files'] }}</span>
                    </div>
                    <div class="stat">
                        <span class="label">Billed</span>
                        <span class="value dr">{{ number_format($totals['billed'], 2, '.', ',') }}</span>
                    </div>
                    <div class="stat">
                        <span class="label">Vendor Cost</span>
                        <span class="value cr">{{ number_format($totals['cost'], 2, '.', ',') }}</span>
                    </div>
                    <div class="stat closing">
                        <span class="label">Margin</span>
                        <span class="value {{ $totals['margin'] < 0 ? 'cr' : 'dr' }}">{{ number_format($totals['margin'], 2, '.', ',') }}</span>
                    </div>
                </div>

                @if (! count($groups))
                    <div class="alert alert-info mb-0">
                        No work files match this report. Try widening the dates or the status.
                    </div>
                @else
                    {{-- Rendered by DataGrid: the banding, the per-party subtotals,
                         the search and the exports are all its own. --}}
                    <div class="ui" data-vue="vue-work-report" data-props="{{ json_encode($gridProps) }}"></div>

                    {{-- The report's own total, from the server, whatever the reader
                         has since searched for — the grid's footer follows the
                         filter, and someone reading a narrowed table still needs to
                         see what the whole report comes to. --}}
                    <div class="grand-total-bar">
                        <span>Grand Total &mdash; {{ $totals['files'] }} {{ Str::plural('file', $totals['files']) }}</span>
                        <span class="figures">
                            <span>Billed {{ number_format($totals['billed'], 2, '.', ',') }}</span>
                            <span>Cost {{ number_format($totals['cost'], 2, '.', ',') }}</span>
                            <span>Margin {{ number_format($totals['margin'], 2, '.', ',') }}</span>
                        </span>
                    </div>
                @endif

            </div>
        </div>
    </section>
@endsection

@section('script')
@endsection
