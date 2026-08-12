@extends('user.layouts.app')

@section('title', 'My Statement | Ac Info')

@section('style')
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">
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
                                        {{ $from ? date('d-M-Y', strtotime($from)) : 'Beginning' }}
                                        &ndash;
                                        {{ $to ? date('d-M-Y', strtotime($to)) : 'Till date' }}
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
                                <input type="date" class="form-control" id="from" name="from" value="{{ $from }}" max="{{ $today->toDateString() }}">
                            </div>
                            <div class="col-sm-auto">
                                <label for="to" class="form-label">To</label>
                                <input type="date" class="form-control" id="to" name="to" value="{{ $to }}" max="{{ $today->toDateString() }}">
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

                        <div class="table-responsive">
                            <table class="table display statement-table" id="example">
                                <thead>
                                    <tr>
                                        <th scope="col">#</th>
                                        <th scope="col">Txn Date</th>
                                        <th scope="col">Details</th>
                                        <th scope="col">Mode</th>
                                        <th scope="col" class="text-end">Receipt</th>
                                        <th scope="col" class="text-end">Payment</th>
                                        <th scope="col" class="text-end">Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @php $bal = (float) $opening; @endphp

                                    @forelse ($getRecords as $items)
                                        @php $bal += $items->amount; @endphp
                                        <tr>
                                            <td>{{ $items->id }}</td>
                                            <td data-order="{{ $items->txn_date }}">{{ date('d-M-Y', strtotime($items->txn_date)) }}</td>
                                            <td>{{ $items->particular }}</td>
                                            <td>{{ $items->payment_type }}</td>
                                            <td class="text-end pos">{{ $items->amount > 0 ? number_format((float) $items->amount, 2, '.', ',') : '' }}</td>
                                            <td class="text-end neg">{{ $items->amount < 0 ? number_format((float) abs($items->amount), 2, '.', ',') : '' }}</td>
                                            <td class="text-end fw-bold {{ $bal < 0 ? 'neg' : '' }}">{{ number_format((float) $bal, 2, '.', ',') }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="7" class="text-center">No transactions in this period.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <th colspan="4" class="text-end">Period Totals</th>
                                        <th class="text-end pos">{{ number_format((float) $receipts, 2, '.', ',') }}</th>
                                        <th class="text-end neg">{{ number_format((float) abs($payments), 2, '.', ',') }}</th>
                                        <th class="text-end {{ $closing < 0 ? 'neg' : '' }}">{{ number_format((float) $closing, 2, '.', ',') }}</th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                    </div>
                </div>
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

    <script>
        $(document).ready(function() {
            $('#example').DataTable({
                dom: 'Bfrtip',
                buttons: ['copyHtml5', 'excelHtml5', 'csvHtml5', 'pdfHtml5', 'print'],
                "pageLength": 50,
                // Keep the order the server sent (oldest transaction first). Balance is a
                // running total accumulated in that order and carried forward from the
                // opening balance, so re-sorting would detach each figure from its row.
                order: [],
                columnDefs: [{
                    orderable: false,
                    targets: [4, 5, 6]
                }]
            });
        });
    </script>
@endsection
