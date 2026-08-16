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
                                <label for="from" class="form-label">From</label>
                                @include('partials._datefield', ['name' => 'from', 'value' => $from, 'max' => $maxDate])
                            </div>
                            <div class="col-sm-auto">
                                <label for="to" class="form-label">To</label>
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
                        <div class="ui" data-vue="vue-client-statement" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>

                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

@section('script')
@endsection
