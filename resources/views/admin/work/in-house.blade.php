@extends('admin.layouts.app')

@section('title', 'In-house Work | Ac Info')

@section('style')
    @include('admin.layouts._statement-style')
    @include('admin.party._style')

    <style>
        .inhouse-summary {
            display: grid;
            gap: var(--s-3);
            grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
            margin-bottom: var(--s-4);
        }
    </style>
@endsection

@section('content')

    <div class="pagetitle">
        <h1>In-house Work</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item active">In-house Work</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        <div class="card">
            <div class="card-body pt-4">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                    <div>
                        <h5 class="card-title p-0 m-0">Work the office is doing itself</h5>
                        <div class="statement-period">
                            {{ $totals['works'] }} {{ Str::plural('work', $totals['works']) }}
                            on {{ $totals['files'] }} {{ Str::plural('file', $totals['files']) }}, oldest first
                        </div>
                    </div>

                    {{-- Where the work on this list is moved along; nothing is done from here. --}}
                    <a href="{{ route('workfile.status') }}" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-flag"></i> Update Status
                    </a>
                </div>

                <div class="inhouse-summary">
                    <div class="stat">
                        <span class="label">Pending Works</span>
                        <span class="value">{{ $totals['works'] }}</span>
                    </div>
                    <div class="stat">
                        <span class="label">Charged</span>
                        <span class="value">{{ number_format($totals['charged'], 2, '.', ',') }}</span>
                    </div>
                    <div class="stat">
                        <span class="label">Waiting Longest</span>
                        <span class="value {{ $totals['oldest'] >= 30 ? 'cr' : '' }}">
                            {{ $totals['works'] ? ($totals['oldest'] === 1 ? '1 day' : $totals['oldest'].' days') : '—' }}
                        </span>
                        @if ($totals['oldest'] >= 30)
                            <span class="stat-note">a month since the papers came in</span>
                        @endif
                    </div>
                </div>

                <div data-vue="vue-inhouse-work" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>
            </div>
        </div>
    </section>
@endsection

@section('script')
@endsection
