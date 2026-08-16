@extends('user.layouts.app')

@section('title', 'My Statement | Ac Info')

@section('style')
    @include('admin.layouts._statement-style')
@endsection

@section('content')
    @php
        $today = now();
        $fyStart = $today->copy()->month >= 4
            ? $today->copy()->startOfYear()->addMonths(3)
            : $today->copy()->subYear()->startOfYear()->addMonths(3);
        $base = route('userstatement');
    @endphp

    <div class="pagetitle">
        <h1>My Statement</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('userdashboard') }}">Home</a></li>
                <li class="breadcrumb-item active">My Statement</li>
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
                                        Period:
                                        {{ $from ? date('d-m-Y', strtotime($from)) : 'Beginning' }}
                                        &ndash;
                                        {{ $to ? date('d-m-Y', strtotime($to)) : 'Till date' }}
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

                        @php
                            /*
                             * The running balance is accumulated here, in the order the
                             * server sent, exactly as the table did. It is carried into
                             * the row rather than recomputed in the browser, because a
                             * balance only means anything in sequence.
                             */
                            $bal = (float) $opening;
                            $statementRows = [];

                            foreach ($getRecords as $items) {
                                $bal += $items->amount;

                                $statementRows[] = [
                                    'id' => $items->id,
                                    'txn_date' => date('d-m-Y', strtotime($items->txn_date)),
                                    // Sorting is off on this screen, but the raw date is
                                    // what any future sort has to use: dd-mm-yyyy compared
                                    // as text orders by day of the month.
                                    'txn_date_raw' => $items->txn_date,
                                    'particular' => $items->particular,
                                    'payment_type' => $items->payment_type,
                                    'receipt' => $items->amount > 0 ? (float) $items->amount : null,
                                    'payment' => $items->amount < 0 ? (float) abs($items->amount) : null,
                                    'balance' => (float) $bal,
                                ];
                            }

                            $statement = [
                                'title' => 'My Statement',
                                'emptyText' => 'No transactions in this period.',
                                'perPage' => 50,
                                /*
                                 * Never sortable. Every balance above is the sum of the
                                 * rows before it, so re-ordering leaves each figure sitting
                                 * against a row it does not belong to — a statement that
                                 * looks entirely normal and is wrong from the first line.
                                 */
                                'sortable' => false,
                                'totals' => ['receipt' => 'sum', 'payment' => 'sum'],
                                'columns' => [
                                    ['key' => 'id', 'label' => '#'],
                                    ['key' => 'txn_date', 'label' => 'Txn Date', 'sortBy' => 'txn_date_raw'],
                                    ['key' => 'particular', 'label' => 'Details', 'width' => '14rem'],
                                    ['key' => 'payment_type', 'label' => 'Mode'],
                                    ['key' => 'receipt', 'label' => 'Receipt', 'type' => 'money'],
                                    ['key' => 'payment', 'label' => 'Payment', 'type' => 'money'],
                                    ['key' => 'balance', 'label' => 'Balance', 'type' => 'money', 'class' => 'ui-money--strong'],
                                ],
                                'rows' => $statementRows,
                            ];
                        @endphp

                        {{-- Rendered by DataGrid. The closing balance stays on the tile
                             above: a column of running balances cannot be summed, so the
                             footer totals receipts and payments only. --}}
                        <div class="ui" data-vue="vue-user-statement" data-props="{{ \App\Support\VueProps::encode($statement) }}"></div>

                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

@section('script')
@endsection
