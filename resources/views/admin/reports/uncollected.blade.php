@extends('admin.layouts.app')

@section('title', 'Not Yet Collected | Ac Info')

@section('style')
    @include('admin.layouts._statement-style')
    @include('admin.party._style')

    <style>
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
        <h1>Not Yet Collected</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item active">Not Yet Collected</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        <div class="card no-print">
            <div class="card-body pt-4">
                <div class="filter-row mb-3">
                    <span class="filter-key">Show</span>
                    <a href="{{ $showUrls['finished'] }}"
                       class="chip {{ $show === 'finished' ? 'active' : '' }}">Finished work</a>
                    <a href="{{ $showUrls['all'] }}"
                       class="chip {{ $show === 'all' ? 'active' : '' }}">Everything owing</a>
                </div>

                <form method="GET" action="{{ $base }}" class="row g-2 align-items-end report-filter">
                    @if ($show !== 'finished')
                        <input type="hidden" name="show" value="{{ $show }}">
                    @endif

                    <div class="col-12 col-md-4">
                        <label for="party_id" class="form-label">Customer</label>
                        <select name="party_id" id="party_id" class="form-select">
                            <option value="">Everyone</option>
                            @foreach ($parties as $party)
                                <option value="{{ $party->id }}" {{ $partyId === $party->id ? 'selected' : '' }}>
                                    {{ $party->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-12 col-md-auto d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Apply</button>
                        <a href="{{ route('report.uncollected') }}" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body pt-4">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                    <div>
                        <h5 class="card-title p-0 m-0">Work done and not paid for</h5>
                        <div class="statement-period">
                            {{ $totals['files'] }} {{ Str::plural('file', $totals['files']) }}
                            across {{ $totals['customers'] }} {{ Str::plural('customer', $totals['customers']) }}
                        </div>
                    </div>
                </div>

                <div class="owed-summary">
                    <div class="stat closing">
                        <span class="label">Outstanding</span>
                        <span class="value dr">{{ number_format($totals['outstanding'], 2, '.', ',') }}</span>
                    </div>
                    <div class="stat">
                        <span class="label">Files</span>
                        <span class="value">{{ $totals['files'] }}</span>
                    </div>
                    <div class="stat">
                        <span class="label">Longest Owed</span>
                        <span class="value {{ $totals['oldest'] >= 30 ? 'cr' : '' }}">
                            {{ $totals['oldest'] ? $totals['oldest'].' days' : '—' }}
                        </span>
                        @if ($totals['oldest'] >= 30)
                            <span class="stat-note">a month since the work was finished</span>
                        @endif
                    </div>
                </div>

                {{--
                    Said out loud, because it is a convention rather than a fact:
                    a receipt does not name the files it covers, so this screen
                    settles the oldest charge first. A customer who meant a
                    payment for one particular file will disagree, and the
                    statement is where that gets settled.
                --}}
                <div class="alert alert-light border small mb-3">
                    <i class="bi bi-info-circle"></i>
                    Money is received against the account, not against a file, so the oldest charge is
                    treated as paid first. Refunds are the exception: they sit against their own file.
                </div>

                <div data-vue="vue-uncollected-report" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>
            </div>
        </div>
    </section>
@endsection

@section('script')
@endsection
