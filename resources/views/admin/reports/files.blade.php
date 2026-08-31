@extends('admin.layouts.app')

@section('title', $partyLabel . ' Report | Ac Info')

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

        .grand-total-bar {
            align-items: baseline;
            background: #012970;
            border-radius: 7px;
            color: #fff;
            display: flex;
            flex-wrap: wrap;
            font-weight: 700;
            gap: 1rem;
            justify-content: space-between;
            margin-top: 0.9rem;
            padding: 0.75rem 1rem;
        }

        .grand-total-bar .figures {
            display: flex;
            flex-wrap: wrap;
            font-variant-numeric: tabular-nums;
            gap: 1.5rem;
        }

        /* The grid prints itself into a clean window, but Ctrl+P on the page
           still has to produce something a person can hand over. */
        @media print {
            .pagetitle nav,
            .report-filter,
            .type-switch,
            .no-print {
                display: none !important;
            }

            .card {
                border: 0 !important;
                box-shadow: none !important;
            }
        }
    </style>
@endsection

@section('content')

    <div class="pagetitle">
        <h1>{{ $partyLabel }}-wise Work Report</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item active">Reports</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">

        <div class="card no-print">
            <div class="card-body pt-4">
                <div class="type-switch mb-3">
                    @foreach (\App\Models\PartyModel::TYPES as $key => $label)
                        {{-- Switching side drops the party filter: an id from the
                             other side would match nothing and read as "no work". --}}
                        <a href="{{ route('report.files', array_filter(['party_type' => $key, 'status' => $status, 'from' => $from, 'to' => $to])) }}"
                           class="{{ $partyType === $key ? 'active' : '' }}">{{ $label }}-wise</a>
                    @endforeach
                </div>

                {{-- The three states anyone actually asks for. Everything else
                     is still in the status list below. --}}
                <div class="filter-row mb-3">
                    <span class="filter-key">Show</span>
                    @foreach ($views as $key => $label)
                        <a href="{{ $viewUrls[$key] }}"
                           class="chip {{ (string) $status === (string) $key ? 'active' : '' }}">{{ $label }}</a>
                    @endforeach
                </div>

                <form method="GET" action="{{ route('report.files') }}" class="row g-2 align-items-end report-filter">
                    <input type="hidden" name="party_type" value="{{ $partyType }}">

                    <div class="col-md-3">
                        <label for="party_id" class="form-label">{{ $partyLabel }}</label>
                        <select class="form-select" id="party_id" name="party_id">
                            <option value="">All {{ strtolower($partyLabel) }}s</option>
                            @foreach ($parties as $party)
                                <option value="{{ $party->id }}" {{ $partyId === (int) $party->id ? 'selected' : '' }}>{{ $party->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="">All statuses</option>
                            <option value="open" {{ $status === 'open' ? 'selected' : '' }}>Work in hand</option>
                            @foreach ($statuses as $key => $text)
                                <option value="{{ $key }}" {{ $status === $key ? 'selected' : '' }}>{{ $text }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label for="from_display" class="form-label">From</label>
                        @include('partials._datefield', ['name' => 'from', 'value' => $from, 'max' => $maxDate])
                    </div>

                    <div class="col-md-2">
                        <label for="to_display" class="form-label">To</label>
                        @include('partials._datefield', ['name' => 'to', 'value' => $to, 'max' => $maxDate])
                    </div>

                    <div class="col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-fill">Apply</button>
                        <a href="{{ route('report.files', ['party_type' => $partyType]) }}" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body pt-4">

                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                    <div>
                        <h5 class="card-title p-0 m-0">{{ $partyLabel }}-wise Work Report</h5>
                        <div class="statement-period">
                            {{ $periodText }} &nbsp;&middot;&nbsp; {{ $statusText }}
                            &nbsp;&middot;&nbsp; {{ $groupCount }} {{ Str::plural(strtolower($partyLabel), $groupCount) }}
                            &nbsp;&middot;&nbsp; {{ $totals['files'] }} {{ Str::plural('file', $totals['files']) }}
                        </div>
                    </div>
                </div>

                <div class="statement-summary mb-3">
                    <div class="stat">
                        <span class="label">Files</span>
                        <span class="value">{{ $totals['files'] }}</span>
                    </div>
                    <div class="stat">
                        <span class="label">Billed</span>
                        <span class="value dr">{{ number_format($totals['billed'], 2, '.', ',') }}</span>
                    </div>
                    <div class="stat">
                        <span class="label">Vendor Cost</span>
                        <span class="value cr">{{ number_format($totals['cost'], 2, '.', ',') }}</span>
                    </div>
                    <div class="stat closing">
                        <span class="label">Margin</span>
                        <span class="value {{ $totals['margin'] < 0 ? 'cr' : 'dr' }}">{{ number_format($totals['margin'], 2, '.', ',') }}</span>
                        {{-- A margin needs both figures, so files still awaiting a
                             price are not in this total. Saying how many keeps it
                             from reading as a figure covering every file above. --}}
                        @if ($totals['unpriced'])
                            <span class="stat-note">on {{ $totals['files'] - $totals['unpriced'] }} of {{ $totals['files'] }} files &mdash; {{ $totals['unpriced'] }} awaiting a price</span>
                        @endif
                    </div>
                </div>

                @if (! $groupCount)
                    <div class="alert alert-info mb-0">
                        {{-- An empty report means one of two things, and the advice
                             for each is the opposite of the other. --}}
                        @if ($filtered)
                            No work files match this report. Try widening the dates, or clearing the status.
                        @else
                            No work files yet.
                            <a href="{{ route('workfile.receive') }}" class="alert-link">Receive one</a>
                            and it will be reported here.
                        @endif
                    </div>
                @else
                    {{-- Rendered by DataGrid: the banding, the per-party subtotals,
                         the search and the exports are all its own. --}}
                    <div class="ui" data-vue="vue-work-report" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>

                    {{-- The report's own total, from the server, whatever the reader
                         has since searched for — the grid's footer follows the
                         filter, and someone reading a narrowed table still needs to
                         see what the whole report comes to. --}}
                    <div class="grand-total-bar">
                        <span>Grand Total &mdash; {{ $totals['files'] }} {{ Str::plural('file', $totals['files']) }}</span>
                        <span class="figures">
                            <span>Billed {{ number_format($totals['billed'], 2, '.', ',') }}</span>
                            <span>Cost {{ number_format($totals['cost'], 2, '.', ',') }}</span>
                            <span>Margin {{ number_format($totals['margin'], 2, '.', ',') }}@if ($totals['unpriced']) <small>({{ $totals['unpriced'] }} awaiting a price)</small>@endif</span>
                        </span>
                    </div>
                @endif

            </div>
        </div>
    </section>
@endsection

@section('script')
@endsection
