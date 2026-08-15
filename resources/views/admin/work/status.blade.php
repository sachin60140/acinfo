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

        .filter-row {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: 0.3rem;
        }

        .filter-row + .filter-row {
            border-top: 1px dashed #e5e9f2;
            margin-top: 0.6rem;
            padding-top: 0.6rem;
        }

        .filter-key {
            color: #8b95a5;
            flex: 0 0 5.2rem;
            font-size: 0.66rem;
            font-weight: 700;
            letter-spacing: 0.07em;
            text-transform: uppercase;
        }

        .chip {
            align-items: center;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 999px;
            color: #475569;
            display: inline-flex;
            font-size: 0.78rem;
            font-weight: 600;
            gap: 0.35rem;
            line-height: 1;
            padding: 0.34rem 0.62rem;
            text-decoration: none;
            transition: background .12s, border-color .12s;
        }

        .chip:hover {
            background: #f6f9ff;
            border-color: #c7d2fe;
            color: #4154f1;
        }

        .chip .n {
            background: #f1f5f9;
            border-radius: 999px;
            color: #64748b;
            font-size: 0.68rem;
            font-weight: 700;
            min-width: 1.15rem;
            padding: 0.05rem 0.3rem;
            text-align: center;
        }

        .chip.active {
            background: #4154f1;
            border-color: #4154f1;
            color: #fff;
        }

        .chip.active .n {
            background: rgba(255, 255, 255, .22);
            color: #fff;
        }

        /* Nothing behind it — reachable, but it stops competing for attention. */
        .chip.empty {
            border-style: dashed;
            color: #b6bfcc;
        }

        .chip.empty .n {
            background: transparent;
            color: #c5ccd6;
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
    @php
        $tabs = ['open' => 'In Hand'] + $statuses + ['all' => 'All'];
        $work = \App\Models\WorkFileModel::class;
    @endphp

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
                            <a href="{{ route('workfile.status', array_filter(['status' => $key, 'work_type' => $workTypeId])) }}"
                               class="chip {{ $filter === $key ? 'active' : '' }} {{ $count === 0 ? 'empty' : '' }}">
                                {{ $text }} <span class="n">{{ $count }}</span>
                            </a>
                        @endforeach
                    </div>

                    <div class="filter-row">
                        <span class="filter-key">Work Type</span>
                        <a href="{{ route('workfile.status', array_filter(['status' => $filter])) }}"
                           class="chip {{ $workTypeId === null ? 'active' : '' }}">
                            All <span class="n">{{ $statusCounts[$filter] ?? 0 }}</span>
                        </a>
                        @forelse ($workTypeCounts as $type)
                            <a href="{{ route('workfile.status', ['status' => $filter, 'work_type' => $type->id]) }}"
                               class="chip {{ $workTypeId === (int) $type->id ? 'active' : '' }}">
                                {{ $type->name }} <span class="n">{{ $type->total }}</span>
                            </a>
                        @empty
                            <span class="side-hint">Nothing under this status.</span>
                        @endforelse
                    </div>
                </div>

                @if ($files->isEmpty())
                    <div class="alert alert-info mb-0">
                        @if ($workTypeId)
                            No files under this status for that work type.
                            <a href="{{ route('workfile.status', ['status' => $filter]) }}" class="alert-link">Show all work types</a>.
                        @else
                            Nothing here.
                            <a href="{{ route('workfile.receive') }}" class="alert-link">Receive files</a> to get started.
                        @endif
                    </div>
                @else
                    <form action="{{ route('workfile.status') }}" method="POST" id="status_form" enctype="multipart/form-data">
                        @csrf

                        <div class="table-responsive">
                            <table class="table board align-middle rt-form">
                                <thead>
                                    <tr>
                                        <th>File</th>
                                        <th>Vehicle</th>
                                        <th>Work</th>
                                        <th>Customer &rarr; Vendor</th>
                                        <th class="text-end">Charged</th>
                                        <th style="min-width: 11rem;">Status</th>
                                        <th style="min-width: 13rem;">Remark</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($files as $file)
                                        <tr data-state="{{ $file->status }}">
                                            {{-- The number is what you look for; the date is what
                                                 you confirm with. Both in one cell keeps the row
                                                 short enough to scan. --}}
                                            <td data-label="File">
                                                <a href="{{ route('workfile.edit', $file->id) }}" class="file-link">{{ $file->file_no }}</a>
                                                <div class="sub-line">{{ date('d-m-Y', strtotime($file->received_date)) }}</div>
                                            </td>

                                            <td data-label="Vehicle">
                                                <span class="lead-line">{{ $file->registration_no }}</span>
                                            </td>

                                            <td data-label="Work">
                                                <div class="lead-line">{{ $file->workType?->name }}</div>
                                                @if ($file->description)
                                                    <div class="sub-line">{{ $file->description }}</div>
                                                @endif
                                            </td>

                                            <td data-label="Customer &rarr; Vendor">
                                                <div class="route">
                                                    {{ $file->customer?->name }}
                                                    <span class="arrow">&rarr;</span>
                                                    @if ($file->vendor)
                                                        {{ $file->vendor->name }}
                                                    @else
                                                        <span class="text-muted">In-house</span>
                                                    @endif
                                                </div>
                                            </td>

                                            <td data-label="Charged" class="text-end">
                                                <span class="charged">{{ number_format((float) $file->customer_amount, 2, '.', ',') }}</span>
                                            </td>

                                            <td data-label="Status">
                                                <select class="form-select js-status" name="statuses[{{ $file->id }}]" data-was="{{ $file->status }}" data-amount="{{ $file->customer_amount }}">
                                                    @foreach ($statuses as $key => $text)
                                                        {{-- Returning to a customer has its own screen, where the refund
                                                             and the reason are asked for properly. It stays in this list
                                                             only for a file already returned, so it can still be undone. --}}
                                                        @continue($key === $work::RETURNED && $file->status !== $key)
                                                        <option value="{{ $key }}" {{ $file->status === $key ? 'selected' : '' }}>{{ $text }}</option>
                                                    @endforeach
                                                </select>

                                                {{-- Shown only when the chosen status is the one that needs it. --}}
                                                <div class="status-extra js-shot-box" style="display: none;">
                                                    <label class="shot-label">Approval screenshot <span class="required-mark">*</span></label>
                                                    <input type="file" class="form-control form-control-sm js-shot"
                                                        name="screenshots[{{ $file->id }}]" accept="image/*,application/pdf">
                                                    @if ($file->approval_screenshot)
                                                        <div class="shot-have">
                                                            <i class="bi bi-paperclip"></i>
                                                            <a href="{{ $file->screenshotUrl() }}" target="_blank" rel="noopener">screenshot on file</a>
                                                            &mdash; choose a file only to replace it
                                                        </div>
                                                    @endif
                                                </div>

                                                <div class="status-extra js-return-box" style="display: none;">
                                                    <label class="shot-label">Refund to customer</label>
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-text">INR</span>
                                                        <input type="number" min="0.01" step="0.01"
                                                            max="{{ $file->customer_amount }}"
                                                            class="form-control text-end js-refund"
                                                            name="refunds[{{ $file->id }}]"
                                                            value="{{ old('refunds.' . $file->id, $file->returned_amount) }}"
                                                            placeholder="{{ number_format((float) $file->customer_amount, 2, '.', '') }}">
                                                    </div>
                                                    <div class="cr fw-bold mt-1 js-return-note">
                                                        Full {{ number_format((float) $file->customer_amount, 2, '.', ',') }} goes back
                                                        &mdash; reduce it if you are keeping part
                                                    </div>
                                                </div>

                                                <div class="status-extra js-cancel-note" style="display: none;">
                                                    <span class="cr fw-bold">Removes this file from both ledgers</span>
                                                </div>
                                            </td>

                                            <td data-label="Remark">
                                                <input type="text" class="form-control js-remark"
                                                    name="remarks[{{ $file->id }}]" value="{{ old('remarks.' . $file->id) }}"
                                                    maxlength="255" placeholder="Why, or what is pending">
                                                @if (! empty($lastRemarks[$file->id]))
                                                    <div class="last-remark" title="Most recent remark on this file">
                                                        <i class="bi bi-clock-history"></i> {{ $lastRemarks[$file->id] }}
                                                    </div>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="save-bar" id="save_bar">
                            <span class="save-note" id="change_count"></span>
                            <div class="d-flex gap-2">
                                {{-- Reloads this same view, so Reset never quietly
                                     changes which files are on screen. --}}
                                <a href="{{ route('workfile.status', array_filter(['status' => $filter, 'work_type' => $workTypeId])) }}" class="btn btn-outline-secondary">Reset</a>
                                <button type="submit" class="btn btn-primary" id="save_button" disabled>
                                    <i class="bi bi-check2-circle me-1"></i> Save Status
                                </button>
                            </div>
                        </div>
                    </form>
                @endif

            </div>
        </div>
    </section>
@endsection

@section('script')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const form = document.getElementById('status_form');

            if (!form) {
                return;
            }

            const saveBar = document.getElementById('save_bar');
            const saveButton = document.getElementById('save_button');
            const changeCount = document.getElementById('change_count');

            const refresh = function () {
                let changed = 0;
                let returning = 0;
                let cancelling = 0;
                let noted = 0;
                let needsReason = 0;

                form.querySelectorAll('.js-status').forEach(function (select) {
                    const cell = select.parentElement;
                    const row = select.closest('tr');
                    const shotBox = cell.querySelector('.js-shot-box');
                    const shot = cell.querySelector('.js-shot');
                    const remark = row.querySelector('.js-remark');
                    const isChanged = select.value !== select.dataset.was;
                    const hasRemark = remark.value.trim() !== '';

                    // The edge colour follows the choice, not the stored value, so
                    // the row shows where it is heading.
                    row.dataset.state = select.value;
                    row.classList.toggle('changed', isChanged);
                    row.classList.toggle('noted', !isChanged && hasRemark);
                    changed += isChanged ? 1 : 0;
                    noted += (!isChanged && hasRemark) ? 1 : 0;

                    // The upload box appears whenever Approval Done is selected —
                    // including when it is already the stored status, so a missing
                    // or wrong screenshot can still be attached afterwards.
                    const needsShot = select.value === 'approval_done';
                    shotBox.style.display = needsShot ? '' : 'none';
                    // A file input left enabled would still post its file after the
                    // user changed their mind about the status.
                    shot.disabled = !needsShot;
                    if (!needsShot) {
                        shot.value = '';
                    }

                    // The refund box appears whenever Paper Returned is selected,
                    // including when already stored, so a part refund stays editable.
                    const isReturning = select.value === 'paper_returned';
                    const returnBox = cell.querySelector('.js-return-box');
                    const refund = cell.querySelector('.js-refund');
                    returnBox.style.display = isReturning ? '' : 'none';
                    refund.disabled = !isReturning;

                    // Only explain what a blank means while it is actually blank.
                    cell.querySelector('.js-return-note').style.display =
                        (isReturning && refund.value.trim() === '') ? '' : 'none';

                    const cancelNote = isChanged && select.value === 'cancelled';
                    cell.querySelector('.js-cancel-note').style.display = cancelNote ? '' : 'none';
                    returning += (isChanged && isReturning) ? 1 : 0;
                    cancelling += cancelNote ? 1 : 0;

                    // Cancelling and returning move the customer's balance, so the
                    // server insists on a reason. Say so here rather than letting
                    // the save bounce back.
                    const consequential = isChanged && (select.value === 'cancelled' || isReturning);
                    remark.classList.toggle('is-invalid', consequential && !hasRemark);
                    needsReason += (consequential && !hasRemark) ? 1 : 0;
                });

                let uploads = 0;
                form.querySelectorAll('.js-shot').forEach(function (shot) {
                    uploads += (!shot.disabled && shot.files.length) ? 1 : 0;
                });

                const nothing = changed === 0 && uploads === 0 && noted === 0;
                saveButton.disabled = nothing || needsReason > 0;
                saveBar.classList.toggle('dirty', !nothing);

                if (needsReason) {
                    changeCount.className = 'save-note warn';
                    changeCount.textContent = needsReason === 1
                        ? 'Add a remark explaining the cancellation or return.'
                        : 'Add a remark for the ' + needsReason + ' files being cancelled or returned.';

                    return;
                }

                changeCount.className = 'save-note';

                if (nothing) {
                    changeCount.textContent = 'No changes yet.';

                    return;
                }

                const parts = [];
                if (changed) {
                    parts.push(changed + (changed === 1 ? ' file' : ' files') + ' will be updated');
                }
                if (noted) {
                    parts.push(noted + ' remark' + (noted === 1 ? '' : 's') + ' added');
                }
                if (uploads) {
                    parts.push(uploads + ' screenshot' + (uploads === 1 ? '' : 's') + ' attached');
                }
                if (returning) {
                    parts.push(returning + ' credited back to customer');
                }
                if (cancelling) {
                    parts.push(cancelling + ' cancelled');
                }
                changeCount.textContent = parts.join(', ') + '.';
            };

            // Picking Paper Returned fills in the full charge so the figure going
            // back is visible. Only on the dropdown changing — refresh() runs on
            // every keystroke, so filling in there would make it unclearable.
            form.addEventListener('change', function (event) {
                if (event.target.classList.contains('js-status') && event.target.value === 'paper_returned') {
                    const refund = event.target.parentElement.querySelector('.js-refund');

                    if (refund.value.trim() === '') {
                        refund.value = Number(event.target.dataset.amount || 0).toFixed(2);
                    }
                }

                refresh();
            });

            form.addEventListener('input', refresh);

            refresh();
        });
    </script>
@endsection
