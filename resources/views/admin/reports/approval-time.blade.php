@extends('admin.layouts.app')

@section('title', 'Approval Time | Ac Info')

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
        <h1>Approval Time</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item active">Approval Time</li>
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

                    {{-- The day it was approved: what was approved last month,
                         however long ago it went out. --}}
                    <div class="col-md-3">
                        <label for="from_display" class="form-label">Approved from</label>
                        @include('partials._datefield', ['name' => 'from', 'value' => $from, 'max' => now()->toDateString()])
                    </div>

                    <div class="col-md-3">
                        <label for="to_display" class="form-label">Approved to</label>
                        @include('partials._datefield', ['name' => 'to', 'value' => $to, 'max' => now()->toDateString()])
                    </div>

                    <div class="col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-fill">Apply</button>
                        <a href="{{ route('report.approvaltime', ['party_type' => $partyType]) }}" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body pt-4">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                    <div>
                        <h5 class="card-title p-0 m-0">Days from dispatch to approval, {{ strtolower($partyLabel) }}-wise</h5>
                        <div class="statement-period">
                            {{ $totals['files'] }} approved {{ Str::plural('file', $totals['files']) }}
                        </div>
                    </div>
                </div>

                <div class="statement-summary mb-3">
                    <div class="stat closing">
                        <span class="label">Average</span>
                        <span class="value">{{ $totals['average'] === null ? '—' : number_format($totals['average'], 1).' days' }}</span>
                    </div>
                    <div class="stat">
                        <span class="label">Fastest</span>
                        <span class="value">{{ $totals['fastest'] === null ? '—' : $totals['fastest'].' '.Str::plural('day', $totals['fastest']) }}</span>
                    </div>
                    <div class="stat">
                        <span class="label">Slowest</span>
                        <span class="value {{ ($totals['slowest'] ?? 0) >= 30 ? 'cr' : '' }}">
                            {{ $totals['slowest'] === null ? '—' : $totals['slowest'].' '.Str::plural('day', $totals['slowest']) }}
                        </span>
                        @if ($totals['slowestFile'])
                            <span class="stat-note">{{ $totals['slowestFile'] }}</span>
                        @endif
                    </div>
                </div>

                <div class="alert alert-light border small mb-3">
                    <i class="bi bi-info-circle"></i>
                    Counted from the day the work was <strong>dispatched</strong> to the day it was
                    <strong>approved</strong> — for a file of several works, the day the last of them was.
                    Vendor-wise, only that vendor's works count. Each {{ strtolower($partyLabel) }}'s average is under
                    their files, and everyone's is at the foot.
                </div>

                {{-- Approved and not counted, said rather than hidden. --}}
                @if ($inHouse || $undated || $misdated)
                    <div class="alert alert-warning small mb-3 no-print">
                        @if ($inHouse)
                            <div>
                                <i class="bi bi-house"></i>
                                {{ $inHouse }} approved {{ Str::plural('file', $inHouse) }} {{ $inHouse === 1 ? 'was' : 'were' }}
                                done in-house and never dispatched — not counted.
                            </div>
                        @endif
                        @if ($undated)
                            <div>
                                <i class="bi bi-calendar"></i>
                                {{ $undated }} approved {{ Str::plural('file', $undated) }} {{ $undated === 1 ? 'was' : 'were' }}
                                given to a vendor with no dispatch date entered — not counted until one is.
                            </div>
                        @endif
                        @if ($misdated)
                            <div>
                                <i class="bi bi-calendar-x"></i>
                                {{ $misdated }} {{ Str::plural('file', $misdated) }} {{ $misdated === 1 ? 'shows' : 'show' }}
                                an approval dated before {{ $misdated === 1 ? 'its' : 'their' }} dispatch — a date typed
                                wrong; not counted until it is corrected.
                            </div>
                        @endif
                    </div>
                @endif

                <div data-vue="vue-approval-time" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>
            </div>
        </div>
    </section>
@endsection

@section('script')
@endsection
