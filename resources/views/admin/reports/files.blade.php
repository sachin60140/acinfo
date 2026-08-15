@extends('admin.layouts.app')

@section('title', $partyLabel . ' Report | Ac Info')

@section('style')
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/rowgroup/1.4.1/css/rowGroup.dataTables.min.css">
    @include('admin.layouts._statement-style')
    @include('admin.party._style')

    <style>
        .report-table {
            font-size: 12.5px;
        }

        .report-table th {
            color: #64748b;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .report-table td,
        .report-table th {
            font-variant-numeric: tabular-nums;
            vertical-align: middle;
        }

        /* A remark is a sentence, not a figure — let it wrap rather than
           stretching the row off the side of the page. */
        .remark-cell {
            color: #475569;
            max-width: 16rem;
            white-space: normal;
        }

        /* One band per party, so the eye can find where a party starts. These
           rows are drawn by RowGroup, not written into the markup. */
        tr.dtrg-start td {
            background: #f6f9ff;
            border-top: 2px solid #4154f1 !important;
            padding-top: 0.6rem !important;
            padding-bottom: 0.6rem !important;
        }

        tr.dtrg-end td {
            background: #fbfcfe;
            border-bottom: 2px solid #dbe3ef !important;
            font-weight: 700;
        }

        .party-key {
            background: #4154f1;
            border-radius: 4px;
            color: #fff;
            font-size: 0.62rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            padding: 0.12rem 0.4rem;
            text-transform: uppercase;
            vertical-align: middle;
        }

        .party-name {
            color: #012970;
            font-size: 0.95rem;
            font-weight: 700;
        }

        .party-meta {
            color: #64748b;
            font-size: 0.75rem;
        }

        .group-total {
            display: flex;
            gap: 1.4rem;
            justify-content: flex-end;
        }

        .group-total span {
            min-width: 6.5rem;
            text-align: right;
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

        /* The printed report should look like a report, not a web page. */
        @media print {
            .pagetitle nav,
            .report-filter,
            .type-switch,
            .dt-buttons,
            .dataTables_filter,
            .dataTables_paginate,
            .dataTables_info,
            .dataTables_length,
            .no-print {
                display: none !important;
            }

            .card {
                border: 0 !important;
                box-shadow: none !important;
            }

            .party-head {
                break-inside: avoid;
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
                    {{--
                        Every row carries the same number of cells, party included.
                        DataTables counts cells per row and has no notion of
                        colspan in a body, so grouping headers written as merged
                        cells stop the table initialising altogether. The grouping
                        is drawn by RowGroup instead, from the hidden first column.
                    --}}
                    <div class="table-responsive">
                        <table class="table report-table" id="report">
                            <thead>
                                <tr>
                                    {{-- Grouped on the id, not the name: two parties may share a
                                         name, and grouping on it merged them into one band with
                                         their money added together. Hidden and never exported. --}}
                                    <th>Party Id</th>
                                    {{-- The party this report is grouped by, spelled out. --}}
                                    <th>{{ $partyLabel }}</th>
                                    <th>File No.</th>
                                    <th>Vehicle</th>
                                    <th>Received</th>
                                    <th>Work Type</th>
                                    <th>Details</th>
                                    {{-- The other side of the file, named so it cannot be mistaken
                                         for the party the report is grouped by. --}}
                                    <th>{{ $partyType === 'vendor' ? 'Received From' : 'Given To' }}</th>
                                    <th>Status</th>
                                    <th>Remarks</th>
                                    <th class="text-end">Billed</th>
                                    <th class="text-end">Cost</th>
                                    <th class="text-end">Margin</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($groups as $group)
                                    @foreach ($group['rows'] as $line)
                                        @php $row = $line['row']; @endphp
                                        <tr>
                                            <td>{{ $group['id'] }}</td>
                                            <td>{{ $group['name'] }}</td>
                                            <td>{{ $row->file_no }}</td>
                                            <td>{{ $row->registration_no }}</td>
                                            <td data-order="{{ $row->received_date }}">{{ date('d-m-Y', strtotime($row->received_date)) }}</td>
                                            <td>{{ $row->work_type }}</td>
                                            <td>{{ $row->description }}</td>
                                            <td>{{ $partyType === 'vendor' ? $row->customer_name : ($row->vendor_name ?: 'In-house') }}</td>
                                            <td>
                                                <span class="badge {{ $work::STATUS_BADGES[$row->status] ?? 'bg-secondary' }}">
                                                    {{ $work::STATUSES[$row->status] ?? $row->status }}
                                                </span>
                                            </td>
                                            {{-- The latest note against the file: what is pending, or why
                                                 it stands where it does. Exported alongside the rest. --}}
                                            <td class="remark-cell">{{ $line['remark'] }}</td>
                                            <td class="text-end dr" data-order="{{ $line['totals']['billed'] }}">{{ number_format($line['totals']['billed'], 2, '.', ',') }}</td>
                                            <td class="text-end cr" data-order="{{ $line['totals']['cost'] }}">{{ $line['totals']['cost'] ? number_format($line['totals']['cost'], 2, '.', ',') : '' }}</td>
                                            <td class="text-end {{ $line['totals']['margin'] < 0 ? 'cr' : 'dr' }}" data-order="{{ $line['totals']['margin'] }}">{{ number_format($line['totals']['margin'], 2, '.', ',') }}</td>
                                        </tr>
                                    @endforeach
                                @endforeach
                            </tbody>
                        </table>
                    </div>

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
    <script src="https://code.jquery.com/jquery-3.7.0.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
    <script src="https://cdn.datatables.net/rowgroup/1.4.1/js/dataTables.rowGroup.min.js"></script>

    <script>
        $(document).ready(function () {
            const partyLabel = @json($partyLabel);
            const reportTitle = @json($partyLabel . '-wise Work Report');
            const subTitle = @json($periodText . '  ·  ' . $statusText);
            const fileName = @json('acinfo-' . $partyType . '-report-' . now()->format('d-m-Y'));
            // Subtotals come from the server rather than being parsed back out of
            // formatted cells, so the bands can never disagree with the ledger.
            const meta = @json($groupMeta);
            const grandTotal = @json($grandTotalText);

            const money = function (value) {
                return (Number(value) || 0).toLocaleString('en-IN', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            };

            const balance = function (value) {
                const parsed = Number(value) || 0;
                const shown = money(Math.abs(parsed));

                if (Math.abs(parsed) < 0.005) {
                    return shown;
                }

                return shown + (parsed < 0 ? ' Cr' : ' Dr');
            };

            /*
             * Paging is off so a party is never cut in half, and orderFixed keeps
             * the party column as the primary sort — RowGroup draws a band only
             * where consecutive rows share a value, so any other primary sort
             * would scatter one party into several bands. Sorting within a group
             * still works.
             */
            $('#report').DataTable({
                dom: 'Bfrtip',
                paging: false,
                info: false,
                order: [[0, 'asc'], [2, 'asc']],
                orderFixed: { pre: [[0, 'asc']] },
                /*
                 * Only the grouping key is hidden. The party being reported on
                 * stays a visible column: with it hidden, a customer-wise report
                 * showed one party column headed "Vendor" full of vendor names,
                 * and read as a vendor report. The band heading alone was not
                 * enough to say whose report it was.
                 */
                columnDefs: [{ targets: 0, visible: false }],
                rowGroup: {
                    dataSrc: 0,
                    startRender: function (rows, group) {
                        const info = meta[group] || {};
                        const name = info.name || rows.data()[0][1];
                        const files = info.files || rows.count();
                        // Prefixed so a band says whose report this is, not just
                        // a bare name that could belong to either side.
                        let text = '<span class="party-key">' + partyLabel + '</span> '
                            + '<span class="party-name">' + name + '</span>'
                            + '<span class="party-meta"> &middot; ' + files + (files === 1 ? ' file' : ' files');

                        if (info.open) {
                            text += ' &middot; ' + info.open + ' in hand';
                        }

                        text += ' &middot; Ledger balance <strong>' + balance(info.balance) + '</strong></span>';

                        return $('<tr/>').append('<td colspan="12">' + text + '</td>');
                    },
                    endRender: function (rows, group) {
                        const info = meta[group] || { billed: 0, cost: 0, margin: 0 };
                        const name = info.name || rows.data()[0][1];

                        return $('<tr/>').append(
                            '<td colspan="9" class="text-end">' + name + ' total</td>'
                            + '<td class="text-end dr">' + money(info.billed) + '</td>'
                            + '<td class="text-end cr">' + money(info.cost) + '</td>'
                            + '<td class="text-end">' + money(info.margin) + '</td>'
                        );
                    }
                },
                buttons: [
                    {
                        extend: 'excelHtml5',
                        text: '<i class="bi bi-file-earmark-excel"></i> Excel',
                        className: 'btn-report',
                        title: reportTitle,
                        messageTop: subTitle,
                        messageBottom: grandTotal,
                        filename: fileName,
                        exportOptions: { columns: [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12] }
                    },
                    {
                        extend: 'pdfHtml5',
                        text: '<i class="bi bi-file-earmark-pdf"></i> PDF',
                        className: 'btn-report',
                        title: reportTitle,
                        messageBottom: grandTotal,
                        filename: fileName,
                        // Ten columns of figures do not fit upright.
                        orientation: 'landscape',
                        pageSize: 'A4',
                        exportOptions: { columns: [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12] },
                        customize: function (doc) {
                            doc.pageMargins = [20, 46, 20, 32];
                            doc.defaultStyle.fontSize = 8;
                            doc.styles.tableHeader.fontSize = 8;
                            doc.styles.tableHeader.fillColor = '#012970';
                            doc.styles.tableHeader.color = '#ffffff';
                            doc.styles.title.fontSize = 14;
                            doc.styles.title.alignment = 'left';
                            doc.styles.title.color = '#012970';

                            // Let the wordy columns breathe and keep the money
                            // columns narrow so the figures line up down the page.
                            doc.content[1].table.widths = [64, 34, 54, 40, 54, '*', 58, 50, '*', 44, 44, 44];
                            doc.content[1].layout = {
                                hLineWidth: function (i, node) {
                                    return (i === 0 || i === node.table.body.length) ? 0.8 : 0.4;
                                },
                                vLineWidth: function () { return 0; },
                                hLineColor: function () { return '#dbe3ef'; },
                                paddingTop: function () { return 3; },
                                paddingBottom: function () { return 3; }
                            };

                            // Right-align the three money columns throughout.
                            doc.content[1].table.body.forEach(function (row, index) {
                                [9, 10, 11].forEach(function (col) {
                                    if (row[col]) {
                                        row[col].alignment = 'right';
                                    }
                                });

                                if (index > 0) {
                                    const label = (row[0] && row[0].text) ? String(row[0].text) : '';
                                    // Subtotal and grand-total rows read as headings.
                                    if (label.indexOf('total') !== -1 || label.indexOf('Total') !== -1) {
                                        row.forEach(function (cell) { cell.bold = true; });
                                    }
                                }
                            });

                            doc['header'] = function () {
                                return {
                                    columns: [
                                        { text: 'Ac Info', style: 'title', margin: [20, 16, 0, 0] },
                                        { text: subTitle, alignment: 'right', fontSize: 8, color: '#64748b', margin: [0, 22, 20, 0] }
                                    ]
                                };
                            };

                            doc['footer'] = function (page, pages) {
                                return {
                                    columns: [
                                        { text: 'Generated ' + @json(now()->format('d-m-Y h:i A')), fontSize: 7, color: '#94a3b8', margin: [20, 0, 0, 0] },
                                        { text: 'Page ' + page + ' of ' + pages, alignment: 'right', fontSize: 7, color: '#94a3b8', margin: [0, 0, 20, 0] }
                                    ],
                                    margin: [0, 12]
                                };
                            };
                        }
                    },
                    {
                        extend: 'print',
                        text: '<i class="bi bi-printer"></i> Print',
                        className: 'btn-report',
                        title: reportTitle,
                        messageTop: subTitle,
                        messageBottom: grandTotal,
                        exportOptions: { columns: [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12] }
                    },
                    {
                        extend: 'copyHtml5',
                        text: '<i class="bi bi-clipboard"></i> Copy',
                        className: 'btn-report',
                        title: reportTitle
                    }
                ]
            });
        });
    </script>
@endsection
