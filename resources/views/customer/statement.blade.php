@extends('customer.layouts.app')

@section('title', 'My Statement | Ac Info')

@section('style')
    @include('admin.layouts._statement-style')

    <style>
        /* The grid's own furniture is not part of a printed statement. Its
           Print button opens a clean document; this only covers Ctrl+P. */
        @media print {
            .grid__bar,
            .grid__pages,
            .statement-filter,
            .quick-ranges {
                display: none !important;
            }
        }
    </style>
@endsection

@section('content')

    <div class="pagetitle">
        <h1>My Statement</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('customer.dashboard') }}">Home</a></li>
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
                                <h5 class="card-title p-0 m-0">{{ $customerName }}</h5>
                                <div class="statement-period">
                                    Period: {{ $periodText }}
                                    &nbsp;&middot;&nbsp; {{ $entryCount }} {{ Str::plural('entry', $entryCount) }}
                                </div>
                            </div>
                            <div class="quick-ranges">
                                <a href="{{ $base }}?from={{ $monthStart }}&to={{ $monthEnd }}">This Month</a>
                                <a href="{{ $base }}?from={{ $fyStart }}&to={{ $fyEnd }}">This FY</a>
                                <a href="{{ $base }}">All</a>
                            </div>
                        </div>

                        <form method="GET" action="{{ $base }}" class="row g-2 align-items-end statement-filter mb-3">
                            <div class="col-sm-auto">
                                <label for="from_display" class="form-label">From</label>
                                @include('partials._datefield', ['name' => 'from', 'value' => $from, 'max' => $maxDate])
                            </div>
                            <div class="col-sm-auto">
                                <label for="to_display" class="form-label">To</label>
                                @include('partials._datefield', ['name' => 'to', 'value' => $to, 'max' => $maxDate])
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
                             column starts from the opening figure, and the table can
                             be searched and paged, so neither belongs to a row.

                             The closing figure is said in words as well as in
                             figures. Dr and Cr are the office's shorthand, and this
                             screen is read by someone who has no reason to know it. --}}
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
                                <span class="label">{{ $balanceLabel }}</span>
                                <span class="value {{ $balanceTone === 'settled' ? '' : $balanceTone }}">{{ $balanceText }}</span>
                            </div>
                        </div>

                        {{--
                            Rendered by Vue: the same grid the office statement
                            uses, with the same search, paging and Copy/CSV/Excel/
                            PDF/Print exports. The figures are the ones the server
                            already computed.
                        --}}
                        <div data-vue="vue-customer-statement" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>

                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
