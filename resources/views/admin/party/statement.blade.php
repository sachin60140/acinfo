@extends('admin.layouts.app')

@section('title', $party->name . ' Statement | Ac Info')

@section('style')
    @include('admin.layouts._statement-style')
    @include('admin.party._style')

    <style>
        /* The shared statement styles hide the DataTables furniture from the
           browser's own print. The grid's search, exports and pager are the same
           kind of thing and want the same treatment — its Print button opens a
           clean document of its own, so this only covers Ctrl+P on the page. */
        @media print {
            .grid__bar,
            .grid__pages {
                display: none !important;
            }
        }
    </style>
@endsection

@section('content')
    @php
        $today = now();
        $fyStart = $today->copy()->month >= 4
            ? $today->copy()->startOfYear()->addMonths(3)
            : $today->copy()->subYear()->startOfYear()->addMonths(3);
        $base = route('party.statement', $party->id);
        $wa = $party->whatsapp ?: $party->mobile;

        $fromText = $from ? date('d-m-Y', strtotime($from)) : 'Beginning';
        $toText = $to ? date('d-m-Y', strtotime($to)) : 'Till date';
        $periodText = $from || $to ? $fromText.' to '.$toText : 'All transactions';

        /*
         * The running balance is accumulated here, in the order the server sent,
         * carried forward from the opening balance. That order is the only order
         * in which these figures mean anything, which is why the grid below is
         * mounted with sortable => false.
         */
        $running = (float) $opening;
        $entries = [];

        foreach ($getRecords as $entry) {
            $running += $entry->signedAmount();
            $isDebit = $entry->entry_type === 'debit';

            $entries[] = [
                'id' => $entry->id,
                'txn_date' => date('d-m-Y', strtotime($entry->txn_date)),
                'particular' => $entry->particular,
                'payment_mode' => $entry->payment_mode,
                'ref_no' => $entry->ref_no,
                // Entries a work file generated link back to it; entries typed
                // straight into the ledger just carry whatever reference was given.
                'ref_url' => $entry->work_file_id ? route('workfile.edit', $entry->work_file_id) : null,
                // The side an entry does not fall on stays null, so it exports as
                // a blank cell the way the old table did rather than as 0.00.
                'debit' => $isDebit ? (float) $entry->amount : null,
                'credit' => $isDebit ? null : (float) $entry->amount,
                'balance' => round($running, 2),
            ];
        }

        $statement = [
            // Also the export filename and the heading on the PDF and the printout.
            'title' => $party->name.' Statement '.$periodText,
            'columns' => [
                ['key' => 'id', 'label' => '#'],
                ['key' => 'txn_date', 'label' => 'Txn Date'],
                ['key' => 'particular', 'label' => 'Particulars', 'width' => '14rem'],
                ['key' => 'payment_mode', 'label' => 'Mode'],
                // The work file opens in a new tab because a statement is read
                // through rather than clicked out of: following the reference in
                // this tab costs the reader their place in the run and the period
                // they filtered to, both of which have to be set up again.
                ['key' => 'ref_no', 'label' => 'Ref No.', 'type' => 'link', 'linkTo' => 'ref_url', 'newTab' => true],
                // The column colour says which side of the ledger it is; the cell
                // dims itself on the side an entry did not fall on.
                ['key' => 'debit', 'label' => 'Debit', 'type' => 'money', 'class' => 'ui-money--dr'],
                ['key' => 'credit', 'label' => 'Credit', 'type' => 'money', 'class' => 'ui-money--cr'],
                ['key' => 'balance', 'label' => 'Balance', 'type' => 'balance', 'class' => 'ui-money--strong'],
            ],
            'rows' => $entries,
            'perPage' => 50,
            /*
             * Never sortable. Balance is a running total carried forward from the
             * opening balance, so re-ordering the rows detaches every figure from
             * the row it belongs to and the statement is quietly wrong.
             */
            'sortable' => false,
            'totals' => ['debit' => 'sum', 'credit' => 'sum'],
            'emptyText' => 'No transactions in this period.',
        ];
    @endphp

    <div class="pagetitle">
        <h1>{{ $label }} Statement</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item"><a href="{{ route('party.index', $type) }}">{{ $label }}s</a></li>
                <li class="breadcrumb-item active">Statement</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section party-page">
        <div class="row">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-body pt-4">

                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                            <div>
                                <h5 class="card-title p-0 m-0">{{ $party->name }}</h5>
                                <div class="statement-period">
                                    {{ $label }}
                                    &nbsp;&middot;&nbsp; <a href="tel:{{ $party->mobile }}" class="link-primary">{{ $party->mobile }}</a>
                                    &nbsp;&middot;&nbsp; <a href="https://wa.me/91{{ $wa }}" target="_blank" rel="noopener" class="wa-link"><i class="bi bi-whatsapp"></i> {{ $wa }}</a>
                                    @if ($party->address)
                                        <br>{{ $party->address }}
                                    @endif
                                </div>
                                <div class="statement-period">
                                    @if ($from || $to)
                                        Period: {{ $fromText }} &ndash; {{ $toText }}
                                    @else
                                        Period: All transactions
                                    @endif
                                    &nbsp;&middot;&nbsp; {{ $getRecords->count() }} {{ Str::plural('entry', $getRecords->count()) }}
                                </div>
                            </div>
                            <div class="quick-ranges">
                                <a href="{{ $base }}?from={{ $today->copy()->startOfMonth()->toDateString() }}&to={{ $today->copy()->endOfMonth()->toDateString() }}">This Month</a>
                                <a href="{{ $base }}?from={{ $fyStart->toDateString() }}&to={{ $fyStart->copy()->addYear()->subDay()->toDateString() }}">This FY</a>
                                <a href="{{ $base }}">All</a>
                            </div>
                        </div>

                        <form method="GET" action="{{ $base }}" class="row g-2 align-items-end statement-filter mb-3">
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

                        @if ($errors->any())
                            <div class="alert alert-danger">
                                <ul class="mb-0 ps-3">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        {{-- Opening and closing stay outside the table: the Balance
                             column starts from the opening figure and the table can
                             be searched and paged, so neither belongs to a row. --}}
                        <div class="statement-summary mb-3">
                            <div class="stat">
                                <span class="label">Opening Balance</span>
                                <span class="value {{ $opening < 0 ? 'cr' : 'dr' }}">{{ \App\Models\PartyLedgerModel::formatBalance($opening) }}</span>
                            </div>
                            <div class="stat">
                                <span class="label">Total Debit</span>
                                <span class="value dr">{{ number_format((float) $debits, 2, '.', ',') }}</span>
                            </div>
                            <div class="stat">
                                <span class="label">Total Credit</span>
                                <span class="value cr">{{ number_format((float) $credits, 2, '.', ',') }}</span>
                            </div>
                            <div class="stat closing">
                                <span class="label">Closing Balance</span>
                                <span class="value {{ $closing < 0 ? 'cr' : 'dr' }}">{{ \App\Models\PartyLedgerModel::formatBalance($closing) }}</span>
                            </div>
                        </div>

                        {{--
                            Rendered by Vue. Search, paging and the Copy/CSV/Excel/
                            PDF/Print exports are the grid's, so the DataTables
                            stack this page used to pull from a CDN is gone. The
                            figures are the same ones the server already computed —
                            only the rendering moved.
                        --}}
                        <div class="ui" data-vue="vue-party-statement" data-props="{{ \App\Support\VueProps::encode($statement) }}"></div>

                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
