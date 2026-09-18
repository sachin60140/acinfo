@extends('admin.layouts.app')

@section('title', 'Vendor Report | Ac Info')

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

        /* What is out there now, before the rows that say who has it. */
        .vendor-summary {
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
        <h1>Vendor Report</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item active">Vendors</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        <div class="card no-print">
            <div class="card-body pt-4">
                {{-- The period is the day the work went out, so a row follows
                     one batch through rather than mixing what was sent this
                     month with what came back in it. --}}
                <form method="GET" action="{{ $base }}" class="row g-2 align-items-end report-filter">
                    <div class="col-6 col-md-3">
                        <label for="from_display" class="form-label">Given out from</label>
                        @include('partials._datefield', ['name' => 'from', 'value' => $from, 'max' => $maxDate])
                    </div>

                    <div class="col-6 col-md-3">
                        <label for="to_display" class="form-label">To</label>
                        @include('partials._datefield', ['name' => 'to', 'value' => $to, 'max' => $maxDate])
                    </div>

                    <div class="col-12 col-md-auto d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Apply</button>
                        <a href="{{ route('report.vendors') }}" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body pt-4">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                    <div>
                        <h5 class="card-title p-0 m-0">How long each vendor takes</h5>
                        <div class="statement-period">
                            {{ $periodText }}
                            &nbsp;&middot;&nbsp; {{ $totals['vendors'] }} {{ Str::plural('vendor', $totals['vendors']) }}
                        </div>
                    </div>
                </div>

                <div class="vendor-summary">
                    <div class="stat">
                        <span class="label">Files Out Now</span>
                        <span class="value">{{ $totals['out_now'] }}</span>
                    </div>
                    <div class="stat">
                        <span class="label">Finished</span>
                        <span class="value">{{ $totals['finished'] }}</span>
                    </div>
                    <div class="stat closing">
                        {{-- The one number to act on today: everything else on
                             this screen is history. --}}
                        <span class="label">Longest Waiting</span>
                        <span class="value {{ $totals['oldest'] >= 21 ? 'cr' : '' }}">
                            {{ $totals['oldest'] ? $totals['oldest'].' days' : '—' }}
                        </span>
                        @if ($totals['oldest'] >= 21)
                            <span class="stat-note">three weeks with a vendor &mdash; worth a phone call</span>
                        @endif
                    </div>
                </div>

                {{--
                    Rendered by Vue: the grid carries the search, the sorting and
                    every export from the rows the controller already sent — no
                    second query, and one definition of how a figure is written.
                --}}
                <div data-vue="vue-vendor-report" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>
            </div>
        </div>
    </section>
@endsection

@section('script')
@endsection
