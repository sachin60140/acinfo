@extends('admin.layouts.app')

@section('title', $partyName . ' Statement | Ac Info')

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
                                <h5 class="card-title p-0 m-0">{{ $partyName }}</h5>
                                <div class="statement-period">
                                    {{ $label }}
                                    &nbsp;&middot;&nbsp; <a href="tel:{{ $partyMobile }}" class="link-primary">{{ $partyMobile }}</a>
                                    &nbsp;&middot;&nbsp; <a href="https://wa.me/91{{ $wa }}" target="_blank" rel="noopener" class="wa-link"><i class="bi bi-whatsapp"></i> {{ $wa }}</a>
                                    @if ($partyAddress)
                                        <br>{{ $partyAddress }}
                                    @endif
                                </div>
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
                        <div class="ui" data-vue="vue-party-statement" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>

                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
