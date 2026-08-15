@extends('admin.layouts.app')

@section('title', 'View Ledger | Ac Info')

@section('style')
    @include('admin.layouts._statement-style')

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
        $base = url('admin/client/statement/' . $clientId);

        // Written once and used twice: on screen, and as the heading a printed or
        // exported statement carries, which is useless without its period.
        $fromText = $from ? date('d-m-Y', strtotime($from)) : 'Beginning';
        $toText = $to ? date('d-m-Y', strtotime($to)) : 'Till date';
        $periodText = $from || $to ? $fromText.' to '.$toText : 'All transactions';

        /*
         * The running balance is accumulated here, in the order the server sent,
         * carried forward from the opening balance. That order is the reason the
         * grid is handed sortable: false — re-ordering the rows would leave every
         * figure in the Balance column standing against the wrong transaction.
         */
        $bal = (float) $opening;
        $rows = [];

        foreach ($getRecords as $item) {
            $amount = (float) $item->amount;
            $bal += $amount;

            $rows[] = [
                'id' => (int) $item->id,
                'txn_date' => date('d-m-Y', strtotime($item->txn_date)),
                'particular' => $item->particular,
                'payment_type' => $item->payment_type,
                // Null rather than zero on the side an entry does not fall on, so
                // the export leaves the cell empty the way the old one did.
                'receipt' => $amount > 0 ? $amount : null,
                'payment' => $amount < 0 ? abs($amount) : null,
                /*
                 * Negated deliberately. A receipt credits the client and is stored
                 * positive, so a positive balance is money held for the client — a
                 * credit — while balance() prints a negative as Cr. Passing the
                 * raw figure would name every side backwards.
                 */
                'balance' => round(-$bal, 2),
                'entry_date' => $item->created_at ? date('d-m-Y', strtotime($item->created_at)) : '',
            ];
        }

        $statement = [
            // Also the export filename and the heading on the PDF and the printout.
            'title' => $clientName.' Statement '.$periodText,
            'columns' => [
                ['key' => 'id', 'label' => '#'],
                ['key' => 'txn_date', 'label' => 'Txn Date'],
                ['key' => 'particular', 'label' => 'Details', 'width' => '14rem'],
                ['key' => 'payment_type', 'label' => 'Mode'],
                // Coloured for money in and money out, which is how this screen
                // has always been read; the cell dims itself on the side an entry
                // did not fall on.
                ['key' => 'receipt', 'label' => 'Receipt', 'type' => 'money', 'class' => 'ui-money--dr'],
                ['key' => 'payment', 'label' => 'Payment', 'type' => 'money', 'class' => 'ui-money--cr'],
                ['key' => 'balance', 'label' => 'Balance', 'type' => 'balance', 'class' => 'ui-money--strong'],
                ['key' => 'entry_date', 'label' => 'Entry Date'],
            ],
            'rows' => $rows,
            'perPage' => 50,
            /*
             * Never sortable. Balance is a running total carried forward from the
             * opening balance, so re-ordering the rows detaches every figure from
             * the row it belongs to and the statement is quietly wrong.
             */
            'sortable' => false,
            // Not the balance: summing a running total produces a figure that
            // means nothing.
            'totals' => ['receipt' => 'sum', 'payment' => 'sum'],
            'emptyText' => 'No transactions in this period.',
        ];
    @endphp

    <div class="pagetitle">
        <h1>Client Statement</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item"><a href="{{ route('viewclient') }}">Clients</a></li>
                <li class="breadcrumb-item active">Statement</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section">
        <div class="row">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-body pt-4">

                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                            <div>
                                <h5 class="card-title p-0 m-0">{{ $clientName }}</h5>
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
                                <label for="from" class="form-label">From</label>
                                @include('partials._datefield', ['name' => 'from', 'value' => $from, 'max' => $today->toDateString()])
                            </div>
                            <div class="col-sm-auto">
                                <label for="to" class="form-label">To</label>
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
                                <span class="value {{ $opening < 0 ? 'neg' : '' }}">{{ number_format((float) $opening, 2, '.', ',') }}</span>
                            </div>
                            <div class="stat">
                                <span class="label">Receipts</span>
                                <span class="value pos">{{ number_format((float) $receipts, 2, '.', ',') }}</span>
                            </div>
                            <div class="stat">
                                <span class="label">Payments</span>
                                <span class="value neg">{{ number_format((float) $payments, 2, '.', ',') }}</span>
                            </div>
                            <div class="stat closing">
                                <span class="label">Closing Balance</span>
                                <span class="value {{ $closing < 0 ? 'neg' : '' }}">{{ number_format((float) $closing, 2, '.', ',') }}</span>
                            </div>
                        </div>

                        {{--
                            Rendered by Vue. Search, paging and the Copy/CSV/Excel/
                            PDF/Print exports are the grid's, so the DataTables
                            stack this page used to pull from a CDN is gone. The
                            figures are the same ones the server already computed —
                            only the rendering moved.
                        --}}
                        <div class="ui" data-vue="vue-client-statement" data-props="{{ json_encode($statement) }}"></div>

                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

@section('script')
@endsection
