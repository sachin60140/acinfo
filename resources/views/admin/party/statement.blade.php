@extends('admin.layouts.app')

@section('title', $party->name . ' Statement | Ac Info')

@section('style')
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">
    @include('admin.layouts._statement-style')
    @include('admin.party._style')
@endsection

@section('content')
    @php
        $today = now();
        $fyStart = $today->copy()->month >= 4
            ? $today->copy()->startOfYear()->addMonths(3)
            : $today->copy()->subYear()->startOfYear()->addMonths(3);
        $base = route('party.statement', $party->id);
        $wa = $party->whatsapp ?: $party->mobile;
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

                        <div class="table-responsive">
                            <table class="table display statement-table rt" id="example">
                                <thead>
                                    <tr>
                                        <th scope="col">#</th>
                                        <th scope="col">Txn Date</th>
                                        <th scope="col">Particulars</th>
                                        <th scope="col">Mode</th>
                                        <th scope="col">Ref No.</th>
                                        <th scope="col" class="text-end">Debit</th>
                                        <th scope="col" class="text-end">Credit</th>
                                        <th scope="col" class="text-end">Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @php $bal = (float) $opening; @endphp

                                    @forelse ($getRecords as $items)
                                        @php $bal += $items->signedAmount(); @endphp
                                        <tr>
                                            <td data-label="#">{{ $items->id }}</td>
                                            {{-- data-order keeps the raw Y-m-d so any sort stays chronological
                                                 while the reader sees dd-mm-yyyy. --}}
                                            <td data-label="Date" data-order="{{ $items->txn_date }}">{{ date('d-m-Y', strtotime($items->txn_date)) }}</td>
                                            <td data-label="Particulars">{{ $items->particular }}</td>
                                            <td data-label="Mode">{{ $items->payment_mode }}</td>
                                            <td>
                                                {{-- Entries a work file generated link back to it; entries typed
                                                     straight into the ledger just show whatever reference was given. --}}
                                                @if ($items->work_file_id)
                                                    <a href="{{ route('workfile.edit', $items->work_file_id) }}" class="link-primary">{{ $items->ref_no }}</a>
                                                @else
                                                    {{ $items->ref_no }}
                                                @endif
                                            </td>
                                            <td data-label="Debit" class="text-end dr">{{ $items->entry_type === 'debit' ? number_format((float) $items->amount, 2, '.', ',') : '' }}</td>
                                            <td data-label="Credit" class="text-end cr">{{ $items->entry_type === 'credit' ? number_format((float) $items->amount, 2, '.', ',') : '' }}</td>
                                            <td data-label="Balance" class="text-end fw-bold {{ $bal < 0 ? 'cr' : 'dr' }}">{{ \App\Models\PartyLedgerModel::formatBalance($bal) }}</td>
                                        </tr>
                                    @empty
                                        {{-- No placeholder row here on purpose: a colspan cell has
                                             fewer cells than the header, which stops DataTables
                                             initialising with "Incorrect column count". DataTables
                                             draws its own message from language.emptyTable below. --}}
                                    @endforelse
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <th colspan="5" class="text-end">Period Totals</th>
                                        <th data-label="Total Debit" class="text-end dr">{{ number_format((float) $debits, 2, '.', ',') }}</th>
                                        <th data-label="Total Credit" class="text-end cr">{{ number_format((float) $credits, 2, '.', ',') }}</th>
                                        <th data-label="Closing" class="text-end {{ $closing < 0 ? 'cr' : 'dr' }}">{{ \App\Models\PartyLedgerModel::formatBalance($closing) }}</th>
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
                language: { emptyTable: 'No transactions in this period.' },
                dom: 'Bfrtip',
                buttons: ['copyHtml5', 'excelHtml5', 'csvHtml5', 'pdfHtml5', 'print'],
                "pageLength": 50,
                // Keep the order the server sent (oldest transaction first). Balance is a
                // running total accumulated in that order and carried forward from the
                // opening balance, so re-sorting would detach each figure from its row.
                order: [],
                columnDefs: [{
                    orderable: false,
                    targets: [5, 6, 7]
                }]
            });
        });
    </script>
@endsection
