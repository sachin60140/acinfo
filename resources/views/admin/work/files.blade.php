@extends('admin.layouts.app')

@section('title', 'Work Files | Ac Info')

@section('style')
    @include('admin.layouts._statement-style')
    @include('admin.party._style')

@endsection

@section('content')
    @php
        $today = now();
        $base = route('workfile.index');

        // Totals follow what each file actually earned and cost once its status is
        // taken into account — cancelled charged nobody, returned charged and gave
        // it straight back. Counting the face figures would make this disagree
        // with the statements it is supposed to summarise.
        $work = \App\Models\WorkFileModel::class;

        $billed = 0.0;
        $cost = 0.0;
        $closedCount = 0;
        $rows = [];

        foreach ($files as $f) {
            // Decided once and read twice: a closed file is kept out of the count
            // above and greyed in the list below.
            $isClosed = in_array($f->status, [$work::CANCELLED, $work::RETURNED], true);

            if ($isClosed) {
                $closedCount++;
            }

            // Part refunds and vendor returns both change what a file really
            // earned and cost. Leaving those out made this page disagree with
            // the statements and the dashboard it is meant to summarise.
            $netCustomer = $work::netCustomer($f->status, $f->customer_amount, $f->returned_amount);
            $netVendor = $work::netVendor($f->status, $f->vendor_amount, $f->vendor_returned_on !== null, $f->vendor_returned_amount);

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

                'status' => $work::STATUSES[$f->status] ?? $f->status,
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
            'emptyText' => 'No files in this view — use Receive Files above.',
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
    @endphp

    <div class="pagetitle">
        <h1>Work Files</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item active">Work Files</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        <div class="row">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-body pt-4">

                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                            <div>
                                <h5 class="card-title p-0 m-0">Files Received</h5>
                                <div class="statement-period">
                                    {{ $files->count() }} {{ Str::plural('file', $files->count()) }}
                                    @if ($closedCount)
                                        &nbsp;&middot;&nbsp; {{ $closedCount }} cancelled or returned, not counted as billed
                                    @endif
                                    @if ($status)
                                        {{-- 'open' is a view of several statuses, not one of them,
                                             so it has no entry in the list to look up. --}}
                                        &nbsp;&middot;&nbsp; Status: {{ $status === 'open' ? 'Work in hand' : ($work::STATUSES[$status] ?? $status) }}
                                    @endif
                                </div>
                            </div>
                            <div class="d-flex flex-wrap gap-2 card-actions">
                                <a href="{{ route('worktype.index') }}" class="btn btn-outline-secondary btn-sm">
                                    <i class="bi bi-briefcase me-1"></i> Work Types
                                </a>
                                <a href="{{ route('workfile.status') }}" class="btn btn-outline-secondary btn-sm">
                                    <i class="bi bi-flag me-1"></i> Update Status
                                </a>
                                <a href="{{ route('workfile.assign') }}" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-truck me-1"></i> Give to Vendor
                                </a>
                                <a href="{{ route('workfile.receive') }}" class="btn btn-primary btn-sm">
                                    <i class="bi bi-folder-plus me-1"></i> Receive Files
                                </a>
                            </div>
                        </div>

                        <form method="GET" action="{{ $base }}" class="row g-2 align-items-end statement-filter mb-3">
                            <div class="col-sm-auto">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="">All</option>
                                    <option value="open" {{ $status === 'open' ? 'selected' : '' }}>Work in hand</option>
                                    @foreach (\App\Models\WorkFileModel::STATUSES as $key => $text)
                                        <option value="{{ $key }}" {{ $status === $key ? 'selected' : '' }}>{{ $text }}</option>
                                    @endforeach

                                </select>
                            </div>
                            <div class="col-sm-auto">
                                <label for="from_display" class="form-label">From</label>
                                @include('partials._datefield', ['name' => 'from', 'value' => $from, 'max' => $today->toDateString()])
                            </div>
                            <div class="col-sm-auto">
                                <label for="to_display" class="form-label">To</label>
                                @include('partials._datefield', ['name' => 'to', 'value' => $to, 'max' => $today->toDateString()])
                            </div>
                            <div class="col-sm-auto">
                                <button type="submit" class="btn btn-primary">Apply</button>
                                <a href="{{ $base }}" class="btn btn-outline-secondary">Reset</a>
                            </div>
                        </form>

                        <div class="statement-summary mb-3">
                            <div class="stat">
                                <span class="label">Billed to Customers</span>
                                <span class="value dr">{{ number_format($billed, 2, '.', ',') }}</span>
                            </div>
                            <div class="stat">
                                <span class="label">Vendor Cost</span>
                                <span class="value cr">{{ number_format($cost, 2, '.', ',') }}</span>
                            </div>
                            <div class="stat closing">
                                <span class="label">Margin</span>
                                <span class="value {{ $billed - $cost < 0 ? 'cr' : 'dr' }}">{{ number_format($billed - $cost, 2, '.', ',') }}</span>
                            </div>
                        </div>

                        {{--
                            Rendered by Vue. The grid carries the search, the
                            paging and every export the CDN stack used to, from the
                            rows the controller already sent — no second query, and
                            one definition of how a figure is written on screen and
                            in the file that comes out of it.
                        --}}
                        <div data-vue="vue-files-list" data-props="{{ \App\Support\VueProps::encode($props) }}"></div>

                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
