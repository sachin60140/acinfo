@extends('admin.layouts.app')

@section('title', 'Update Work Status | Ac Info')

@section('style')
    @include('admin.party._style')

    <style>
        /* ---- Filter bar ------------------------------------------------- */

        .filter-bar {
            background: #fbfcfe;
            border: 1px solid #eef1f6;
            border-radius: 8px;
            padding: 0.7rem 0.85rem;
        }

        /* ---- Board ------------------------------------------------------ */

        .board {
            font-size: 13px;
            margin-bottom: 0;
        }

        .board thead th {
            background: #f8fafc;
            border-bottom: 1px solid #e5e9f2 !important;
            color: #8b95a5;
            font-size: 0.66rem;
            font-weight: 700;
            letter-spacing: 0.07em;
            padding: 0.55rem 0.6rem;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .board tbody td {
            border-bottom: 1px solid #f1f5f9;
            padding: 0.7rem 0.6rem;
            vertical-align: middle;
        }

        .board tbody tr:hover td {
            background: #fbfcfe;
        }

        /* A colour down the left edge says what state the row is in before you
           read anything. Set from the live dropdown value, not the stored one. */
        .board tbody tr td:first-child {
            border-left: 3px solid transparent;
        }

        .board tbody tr[data-state="in_office"] td:first-child { border-left-color: #94a3b8; }
        .board tbody tr[data-state="paper_pendency"] td:first-child { border-left-color: #f59e0b; }
        .board tbody tr[data-state="file_dispatch"] td:first-child { border-left-color: #0ea5e9; }
        .board tbody tr[data-state="part_pesi_required"] td:first-child { border-left-color: #f59e0b; }
        .board tbody tr[data-state="under_verification"] td:first-child { border-left-color: #4154f1; }
        .board tbody tr[data-state="approval_done"] td:first-child { border-left-color: #198754; }
        .board tbody tr[data-state="paper_returned"] td:first-child { border-left-color: #0f172a; }
        .board tbody tr[data-state="cancelled"] td:first-child { border-left-color: #dc3545; }

        .board tr.changed td {
            background: #fffbeb !important;
        }

        .board tr.noted td {
            background: #f8fafc !important;
        }

        /* Two facts per cell: the one you scan for, and the one you confirm with. */
        .lead-line {
            color: #012970;
            font-weight: 700;
        }

        .sub-line {
            color: #94a3b8;
            font-size: 0.72rem;
            margin-top: 0.1rem;
        }

        .file-link {
            color: #4154f1;
            font-weight: 700;
            text-decoration: none;
        }

        .file-link:hover {
            text-decoration: underline;
        }

        .route {
            color: #475569;
        }

        .route .arrow {
            color: #cbd5e1;
            margin: 0 0.3rem;
        }

        .charged {
            color: #0f172a;
            font-variant-numeric: tabular-nums;
            font-weight: 700;
            white-space: nowrap;
        }

        .board .form-select,
        .board .form-control {
            border-color: #dbe3ef;
            font-size: 0.82rem;
            min-height: 36px;
        }

        .status-extra {
            font-size: 0.72rem;
            margin-top: 0.35rem;
        }

        .status-extra .shot-label {
            color: #334155;
            display: block;
            font-weight: 700;
            margin-bottom: 0.15rem;
        }

        .status-extra .shot-have {
            color: #64748b;
            margin-top: 0.2rem;
        }

        .last-remark {
            color: #94a3b8;
            font-size: 0.7rem;
            margin-top: 0.25rem;
        }

        /* ---- Save bar --------------------------------------------------- */

        /* Sticks to the bottom so it is reachable with a long board on screen. */
        .save-bar {
            align-items: center;
            background: #fff;
            border-top: 1px solid #e5e9f2;
            bottom: 0;
            display: flex;
            flex-wrap: wrap;
            gap: 0.6rem;
            justify-content: space-between;
            margin: 0 -1.25rem -1.25rem;
            padding: 0.85rem 1.25rem;
            position: sticky;
            z-index: 5;
        }

        .save-bar.dirty {
            background: #fffbeb;
            border-top-color: #fcd34d;
        }

        .save-note {
            color: #64748b;
            font-size: 0.82rem;
        }

        .save-note.warn {
            color: #dc3545;
            font-weight: 700;
        }

        @media (max-width: 991.98px) {
            .filter-key {
                flex-basis: 100%;
                margin-bottom: 0.15rem;
            }

            .save-bar {
                margin: 0 -0.9rem -0.9rem;
                padding: 0.75rem 0.9rem;
            }

            .save-bar .btn {
                flex: 1 1 auto;
            }
        }
    </style>
@endsection

@section('content')

    <div class="pagetitle">
        <h1>Update Work Status</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item"><a href="{{ route('workfile.index') }}">Work Files</a></li>
                <li class="breadcrumb-item active">Status</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        <div class="card">
            <div class="card-body pt-4">

                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                    <div>
                        <h5 class="card-title p-0 m-0">Work Status</h5>
                        <div class="side-hint">
                            Change as many as you like, then save once &mdash; only the rows you touched are written.
                        </div>
                    </div>
                    <a href="{{ route('workfile.index') }}" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-folder2-open me-1"></i> All Files
                    </a>
                </div>

                <div class="filter-bar mb-3">
                    <div class="filter-row">
                        <span class="filter-key">Status</span>
                        @foreach ($tabs as $key => $text)
                            @php $count = $statusCounts[$key] ?? 0; @endphp
                            {{-- The work type carries across, so changing status
                                 never silently widens what you are looking at. --}}
                            <a href="{{ route('workfile.status', array_filter(['status' => $key, 'work_type' => $workTypeId, 'vendor' => $vendorId])) }}"
                               class="chip {{ $filter === $key ? 'active' : '' }} {{ $count === 0 ? 'empty' : '' }}">
                                {{ $text }} <span class="n">{{ $count }}</span>
                            </a>
                        @endforeach
                    </div>

                    <div class="filter-row">
                        <span class="filter-key">Work Type</span>
                        <a href="{{ route('workfile.status', array_filter(['status' => $filter, 'vendor' => $vendorId])) }}"
                           class="chip {{ $workTypeId === null ? 'active' : '' }}">
                            All <span class="n">{{ $statusCounts[$filter] ?? 0 }}</span>
                        </a>
                        @forelse ($workTypeCounts as $type)
                            <a href="{{ route('workfile.status', array_filter(['status' => $filter, 'work_type' => $type->id, 'vendor' => $vendorId])) }}"
                               class="chip {{ $workTypeId === (int) $type->id ? 'active' : '' }}">
                                {{ $type->name }} <span class="n">{{ $type->total }}</span>
                            </a>
                        @empty
                            <span class="side-hint">Nothing under this status.</span>
                        @endforelse
                    </div>

                    {{-- Chasing work is usually chasing one vendor: you have them
                         on the phone and want their files, not everybody's. --}}
                    <div class="filter-row">
                        <span class="filter-key">Vendor</span>
                        <a href="{{ route('workfile.status', array_filter(['status' => $filter, 'work_type' => $workTypeId])) }}"
                           class="chip {{ $vendorId === null ? 'active' : '' }}">
                            All <span class="n">{{ $statusCounts[$filter] ?? 0 }}</span>
                        </a>
                        @forelse ($vendorCounts as $party)
                            @php $key = $party->id ? (string) $party->id : $inHouseKey; @endphp
                            <a href="{{ route('workfile.status', array_filter(['status' => $filter, 'work_type' => $workTypeId, 'vendor' => $key])) }}"
                               class="chip {{ (string) $vendorId === $key ? 'active' : '' }}">
                                {{ $party->name }} <span class="n">{{ $party->total }}</span>
                            </a>
                        @empty
                            <span class="side-hint">Nothing under this status.</span>
                        @endforelse
                    </div>
                </div>

                @if (! $fileCount)
                    <div class="alert alert-info mb-0">
                        @if ($vendorId)
                            No files under this status for that vendor.
                            <a href="{{ route('workfile.status', array_filter(['status' => $filter, 'work_type' => $workTypeId])) }}" class="alert-link">Show every vendor</a>.
                        @elseif ($workTypeId)
                            No files under this status for that work type.
                            <a href="{{ route('workfile.status', ['status' => $filter]) }}" class="alert-link">Show all work types</a>.
                        @else
                            @if ($anyFiles)
                                No files under this status.
                                <a href="{{ route('workfile.status', ['status' => 'all']) }}" class="alert-link">Show every status</a>.
                            @else
                                No files have been received yet.
                                <a href="{{ route('workfile.receive') }}" class="alert-link">Receive the first one</a> to get started.
                            @endif
                        @endif
                    </div>
                @else
                    {{--
                        Rendered by Vue. The field names are the ones
                        WorkFileController::status() already validates, so the form
                        still posts normally and the server still checks every
                        value — which is what makes converting a screen on a live
                        ledger safe: only the rendering moves.
                    --}}
                    <div data-vue="vue-status-board" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>
                @endif

            </div>
        </div>
    </section>
@endsection

@section('script')
@endsection
