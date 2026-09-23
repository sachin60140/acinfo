@extends('admin.layouts.app')

@section('title', 'Vendor Payments | Ac Info')

@section('style')
    @include('admin.layouts._statement-style')
    @include('admin.party._style')

    <style>
        .owed-summary {
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
        <h1>Vendor Payments</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item active">Vendor Payments</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        <div class="card">
            <div class="card-body pt-4">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                    <div>
                        <h5 class="card-title p-0 m-0">What you owe each vendor, the longest-waiting first</h5>
                        <div class="statement-period">
                            {{ $totals['bills'] }} unpaid {{ Str::plural('bill', $totals['bills']) }}
                            across {{ $totals['vendors'] }} {{ Str::plural('vendor', $totals['vendors']) }}
                            @if ($inAdvance)
                                · {{ $inAdvance }} {{ Str::plural('vendor', $inAdvance) }} paid in advance
                            @endif
                            · <strong>office only</strong> — it shows every vendor's dues, so it is not one to hand to a vendor
                        </div>
                    </div>
                </div>

                <div class="statement-summary owed-summary">
                    <div class="stat closing">
                        <span class="label">Owed</span>
                        <span class="value cr">{{ number_format($totals['owed'], 2, '.', ',') }}</span>
                    </div>
                    <div class="stat">
                        <span class="label">On Finished Work</span>
                        <span class="value">{{ number_format($totals['finished'], 2, '.', ',') }}</span>
                    </div>
                    <div class="stat">
                        <span class="label">Waiting Longest</span>
                        <span class="value {{ $totals['oldest'] >= 30 ? 'cr' : '' }}">
                            {{ $totals['bills'] ? $totals['oldest'].' '.Str::plural('day', $totals['oldest']) : '—' }}
                        </span>
                        @if ($totals['oldest'] >= 30)
                            <span class="stat-note">a month or more unpaid</span>
                        @endif
                    </div>
                </div>

                {{--
                    Said out loud, because two of these are conventions: which
                    bill a payment nobody adjusted went to, and that a vendor is
                    owed from the day the work went to them.
                --}}
                <div class="alert alert-light border small mb-3">
                    <i class="bi bi-info-circle"></i>
                    A vendor is owed for a file from the day the work was <strong>given</strong> to them, so work they
                    are still doing is here too; <strong>On Finished Work</strong> is the part for work of theirs that
                    is approved or handed back. A payment adjusted against files settles those files; one that was
                    not adjusted settles the oldest bill first. Work a vendor gives back comes off its own file.
                    <strong>Record payment</strong> opens the Vendor Ledger entry with the vendor already picked.
                </div>

                @if ($unpriced)
                    <div class="alert alert-warning small mb-3 no-print">
                        <i class="bi bi-tag"></i>
                        {{ $unpriced }} {{ Str::plural('file', $unpriced) }} {{ $unpriced === 1 ? 'has' : 'have' }}
                        work given out with no vendor rate agreed — that work is not counted here until it is.
                        <a href="{{ $unpricedUrl }}">See {{ $unpriced === 1 ? 'it' : 'them' }}</a>.
                    </div>
                @endif

                <div data-vue="vue-vendor-payments" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>
            </div>
        </div>
    </section>
@endsection

@section('script')
@endsection
