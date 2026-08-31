@extends('admin.layouts.app')

@section('title', 'Profit Report | Ac Info')

@section('style')
    @include('admin.layouts._statement-style')
    @include('admin.party._style')

    <style>
        /* The date boxes on the filter row, sized as the other reports size
           them. Defined here rather than borrowed: a class reachable only from
           a screen this one does not include is a class it does not have. */
        .report-filter .form-label {
            color: #64748b;
            font-size: 0.78rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .report-filter .form-control,
        .report-filter .form-select {
            min-height: 40px;
        }

        /* Where the money went, before the rows that say it in detail. */
        .profit-summary {
            display: grid;
            gap: var(--s-3);
            grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
            margin-bottom: var(--s-4);
        }

        @media print {
            .no-print {
                display: none !important;
            }
        }
    </style>
@endsection

@section('content')

    <div class="pagetitle">
        <h1>Profit Report</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item active">Profit</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        <div class="card no-print">
            <div class="card-body pt-4">
                {{-- The cuts, each keeping the period already chosen. --}}
                <div class="filter-row mb-3">
                    <span class="filter-key">By</span>
                    @foreach ($groups as $key => $text)
                        <a href="{{ $groupUrls[$key] }}"
                           class="chip {{ $group === $key ? 'active' : '' }}">{{ $text }}</a>
                    @endforeach
                </div>

                <form method="GET" action="{{ $base }}" class="row g-2 align-items-end report-filter">
                    <input type="hidden" name="group" value="{{ $group }}">

                    <div class="col-6 col-md-3">
                        <label for="from_display" class="form-label">From</label>
                        @include('partials._datefield', ['name' => 'from', 'value' => $from, 'max' => $maxDate])
                    </div>

                    <div class="col-6 col-md-3">
                        <label for="to_display" class="form-label">To</label>
                        @include('partials._datefield', ['name' => 'to', 'value' => $to, 'max' => $maxDate])
                    </div>

                    <div class="col-12 col-md-auto d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Apply</button>
                        <a href="{{ route('report.profit', ['group' => $group]) }}" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body pt-4">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                    <div>
                        <h5 class="card-title p-0 m-0">{{ $groupLabel }}-wise</h5>
                        <div class="statement-period">
                            {{ $periodText }}
                            &nbsp;&middot;&nbsp; {{ $totals['files'] }} {{ Str::plural('file', $totals['files']) }}
                        </div>
                    </div>
                </div>

                <div class="profit-summary">
                    <div class="stat">
                        <span class="label">Billed to Customers</span>
                        <span class="value dr">{{ number_format($totals['billed'], 2, '.', ',') }}</span>
                    </div>
                    <div class="stat">
                        <span class="label">Vendor Cost</span>
                        <span class="value cr">{{ number_format($totals['cost'], 2, '.', ',') }}</span>
                    </div>
                    <div class="stat closing">
                        <span class="label">Margin</span>
                        <span class="value {{ $totals['margin'] < 0 ? 'cr' : 'dr' }}">{{ number_format($totals['margin'], 2, '.', ',') }}</span>
                        {{--
                            A file with a price still to be agreed has no margin
                            yet, so it is not in this figure. Said out loud,
                            because a total that quietly covers fewer files than
                            the two beside it reads as the whole answer.
                        --}}
                        @if ($totals['unpriced'])
                            <span class="stat-note">on {{ $totals['files'] - $totals['unpriced'] }} of {{ $totals['files'] }} files &mdash; {{ $totals['unpriced'] }} awaiting a price</span>
                        @endif
                    </div>
                </div>

                {{--
                    Rendered by Vue: the grid carries the search, the sorting and
                    every export from the rows the controller already sent — no
                    second query, and one definition of how a figure is written.
                --}}
                <div data-vue="vue-profit-report" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>
            </div>
        </div>
    </section>
@endsection

@section('script')
@endsection
