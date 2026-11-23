@extends('admin.layouts.app')

@section('title', 'Paper Returns | Ac Info')

@section('style')
    @include('admin.layouts._statement-style')
    @include('admin.party._style')

    <style>
        .report-filter .form-label {
            color: #334155;
            font-size: 0.78rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .report-filter .form-control,
        .report-filter .form-select {
            min-height: 40px;
        }

        .type-switch a {
            border: 1px solid #dee2e6;
            border-radius: 5px;
            color: #4154f1;
            display: inline-block;
            font-size: 0.85rem;
            font-weight: 600;
            padding: 0.35rem 0.9rem;
            text-decoration: none;
        }

        .type-switch a.active {
            background: #4154f1;
            border-color: #4154f1;
            color: #fff;
        }

        @media print {
            .pagetitle nav,
            .report-filter,
            .type-switch,
            .no-print {
                display: none !important;
            }
        }
    </style>
@endsection

@section('content')

    <div class="pagetitle">
        <h1>Paper Returns</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item active">Paper Returns</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        <div class="card no-print">
            <div class="card-body pt-4">
                <div class="type-switch mb-3">
                    {{-- Switching side drops the party: an id from the other side matches nothing. --}}
                    @foreach (\App\Models\PartyModel::TYPES as $key => $label)
                        <a href="{{ $typeUrls[$key] }}" class="{{ $partyType === $key ? 'active' : '' }}">{{ $label }}-wise</a>
                    @endforeach
                </div>

                <form method="GET" action="{{ $base }}" class="row g-2 align-items-end report-filter">
                    <input type="hidden" name="party_type" value="{{ $partyType }}">

                    <div class="col-md-4">
                        <label for="party_id" class="form-label">{{ $partyLabel }}</label>
                        <select class="form-select" id="party_id" name="party_id">
                            <option value="">All {{ strtolower($partyLabel) }}s</option>
                            @foreach ($parties as $party)
                                <option value="{{ $party->id }}" {{ $partyId === (int) $party->id ? 'selected' : '' }}>{{ $party->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- The day the papers went back, whenever the file came in. --}}
                    <div class="col-md-3">
                        <label for="from_display" class="form-label">Returned from</label>
                        @include('partials._datefield', ['name' => 'from', 'value' => $from, 'max' => now()->toDateString()])
                    </div>

                    <div class="col-md-3">
                        <label for="to_display" class="form-label">Returned to</label>
                        @include('partials._datefield', ['name' => 'to', 'value' => $to, 'max' => now()->toDateString()])
                    </div>

                    <div class="col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-fill">Apply</button>
                        <a href="{{ route('report.paperreturns', ['party_type' => $partyType]) }}" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body pt-4">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                    <div>
                        <h5 class="card-title p-0 m-0">
                            {{ $partyType === 'customer' ? 'Papers returned to customers' : 'Papers returned by vendors' }}
                        </h5>
                        <div class="statement-period">
                            {{ $totals['files'] }} {{ $partyType === 'customer' ? Str::plural('file', $totals['files']) : Str::plural('return', $totals['files']) }}
                            &middot; {{ $period ? 'returned '.$period : 'all time' }}
                        </div>
                    </div>
                </div>

                <div class="statement-summary mb-3">
                    @if ($partyType === 'customer')
                        <div class="stat">
                            <span class="label">Charged</span>
                            <span class="value">{{ number_format($totals['charged'], 2) }}</span>
                        </div>
                        <div class="stat">
                            <span class="label">Refunded</span>
                            <span class="value cr">{{ number_format($totals['refunded'], 2) }}</span>
                        </div>
                        <div class="stat closing">
                            <span class="label">Kept</span>
                            <span class="value">{{ number_format($totals['kept'], 2) }}</span>
                            <span class="stat-note">for fees paid or trips made</span>
                        </div>
                    @else
                        <div class="stat">
                            <span class="label">Booked</span>
                            <span class="value">{{ number_format($totals['booked'], 2) }}</span>
                        </div>
                        <div class="stat closing">
                            <span class="label">Reversed</span>
                            <span class="value">{{ number_format($totals['reversed'], 2) }}</span>
                            <span class="stat-note">taken off what is owed to vendors</span>
                        </div>
                    @endif
                </div>

                <div class="alert alert-light border small mb-3">
                    <i class="bi bi-info-circle"></i>
                    @if ($partyType === 'customer')
                        Every file handed back to its customer, dated the day it went back. <strong>Refunded</strong>
                        is what their statement was credited; <strong>Kept</strong> is what of the charge stayed with
                        the office. A file taken off Paper Returned is no longer listed, and its refund is no longer on
                        their statement.
                    @else
                        Every vendor's work handed back, dated the day the last of it came back. <strong>Booked</strong>
                        is what their statement was credited for that work, and <strong>Reversed</strong> what was taken
                        back off it. A file shared between vendors is listed once for each.
                    @endif
                    Each {{ strtolower($partyLabel) }}'s sums are under their files, and everyone's are at the foot.
                </div>

                <div data-vue="vue-paper-returns" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>
            </div>
        </div>
    </section>
@endsection

@section('script')
@endsection
