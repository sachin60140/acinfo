@extends('admin.layouts.app')

@section('title', 'Collection List | Ac Info')

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
        <h1>Collection List</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item active">Collection List</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        <div class="card">
            <div class="card-body pt-4">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                    <div>
                        <h5 class="card-title p-0 m-0">Customers who owe, the longest-owed first</h5>
                        <div class="statement-period">
                            {{ $totals['customers'] }} {{ Str::plural('customer', $totals['customers']) }}
                            @if ($inAdvance)
                                · {{ $inAdvance }} {{ Str::plural('customer', $inAdvance) }} paid in advance
                            @endif
                        </div>
                    </div>
                </div>

                <div class="statement-summary owed-summary">
                    <div class="stat closing">
                        <span class="label">To Collect</span>
                        <span class="value dr">{{ number_format($totals['owes'], 2, '.', ',') }}</span>
                    </div>
                    <div class="stat">
                        <span class="label">On Finished Work</span>
                        <span class="value">{{ number_format($totals['finished'], 2, '.', ',') }}</span>
                    </div>
                    <div class="stat">
                        <span class="label">Owed Longest</span>
                        <span class="value {{ $totals['oldest'] >= 30 ? 'cr' : '' }}">
                            {{ $totals['customers'] ? $totals['oldest'].' '.Str::plural('day', $totals['oldest']) : '—' }}
                        </span>
                        @if ($totals['oldest'] >= 30)
                            <span class="stat-note">a month or more unpaid</span>
                        @endif
                    </div>
                </div>

                {{--
                    Said out loud, because two of these are conventions and the
                    third is easy to misread: the age here is not the age Not Yet
                    Collected shows for the same customer.
                --}}
                <div class="alert alert-light border small mb-3">
                    <i class="bi bi-info-circle"></i>
                    <strong>Owes</strong> is the balance on the customer's statement. <strong>Oldest Unpaid</strong>
                    is the day of the oldest charge still not paid — a file is charged the day its papers came in,
                    so work still being done counts too; <strong>On Finished Work</strong> is the part for work that
                    is approved or handed back. A payment adjusted against files settles those files; money that
                    was not adjusted settles the oldest charge first. A balance carried from the old Client Ledger
                    is dated from the old book's own charges, not the day it was carried. <strong>Remind</strong>
                    opens WhatsApp with the balance reminder filled in — nothing is sent until you press Send.
                </div>

                {{-- What this list cannot count yet. --}}
                @if ($oldBook || $unbilled)
                    <div class="alert alert-warning small mb-3 no-print">
                        @if ($oldBook)
                            <div>
                                <i class="bi bi-journal-x"></i>
                                Some balances are still in the old Client Ledger and are not on this list.
                                <a href="{{ $oldBookUrl }}">Carry them to Customers</a>.
                            </div>
                        @endif
                        @if ($unbilled)
                            <div>
                                <i class="bi bi-tag"></i>
                                {{ $unbilled }} {{ Str::plural('file', $unbilled) }} {{ $unbilled === 1 ? 'has' : 'have' }}
                                work not yet priced for the customer — that work is not counted here until it is.
                                <a href="{{ $unbilledUrl }}">See {{ $unbilled === 1 ? 'it' : 'them' }}</a>.
                            </div>
                        @endif
                    </div>
                @endif

                <div data-vue="vue-collection-list" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>
            </div>
        </div>
    </section>
@endsection

@section('script')
@endsection
