@extends('admin.layouts.app')

@section('title', 'Return Files to Customer | Ac Info')

@section('style')
    @include('admin.party._style')

    <style>
        .assign-table {
            font-size: 13px;
        }

        .assign-table th {
            color: #64748b;
            font-size: 0.74rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .assign-table td {
            vertical-align: middle;
        }

        .assign-table tr.picked {
            background: #f6f9ff;
        }

        .batch-total {
            background: #fdecee;
            border: 1px solid #dc3545;
            border-radius: 7px;
            padding: 0.7rem 0.95rem;
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 1rem;
        }

        .batch-total .label {
            color: #64748b;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .batch-total .value {
            color: #0f172a;
            font-size: 1.3rem;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
        }
    </style>
@endsection

@section('content')
    <div class="pagetitle">
        <h1>Return Files to Customer</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item"><a href="{{ route('workfile.index') }}">Work Files</a></li>
                <li class="breadcrumb-item active">Return to Customer</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        @if ($files->isEmpty())
            <div class="alert alert-info">
                No files are available to return. Everything received has already been returned or cancelled.
                <a href="{{ route('workfile.receive') }}" class="alert-link">Receive files</a>.
            </div>
        @else
            <form id="return_form" action="{{ route('workfile.customerreturn') }}" method="POST">
                @csrf

                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">When and Why</h5>

                        <div class="row g-3">
                            <div class="col-md-3">
                                <label for="returned_on_display" class="form-label">Returned On <span class="required-mark">*</span></label>
                                @include('partials._datefield', [
                                    'name' => 'returned_on',
                                    'value' => old('returned_on', date('Y-m-d')),
                                    'required' => true,
                                ])
                            </div>

                            <div class="col-md-6">
                                <label for="remark" class="form-label">Reason <span class="required-mark">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-chat-left-text"></i></span>
                                    <input type="text" class="form-control" id="remark" name="remark" value="{{ old('remark') }}" maxlength="200" placeholder="Why the papers are going back" required>
                                </div>
                                <div class="side-hint">This changes the customer's balance, so the reason is kept on each file's history.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                            <div>
                                <h5 class="card-title mb-0">Files You Can Return</h5>
                                <div class="side-hint">
                                    The refund is filled in with what the customer was charged. Reduce it if you are keeping
                                    part &mdash; for fees already paid or a trip already made. The original charge stays on
                                    their statement with the refund beside it.
                                </div>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="check_all">
                                <label class="form-check-label" for="check_all">Select all</label>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table assign-table align-middle rt-form">
                                <thead>
                                    <tr>
                                        <th style="width: 2.5rem;"></th>
                                        <th>File No.</th>
                                        <th>Received</th>
                                        <th>Work Type</th>
                                        <th>Details</th>
                                        <th>Customer</th>
                                        <th>Status</th>
                                        <th class="text-end">Charged</th>
                                        <th style="min-width: 9rem;" class="text-end">Refund</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($files as $file)
                                        <tr>
                                            <td>
                                                <input class="form-check-input js-pick" type="checkbox" name="files[]" value="{{ $file->id }}"
                                                    data-amount="{{ $file->customer_amount }}"
                                                    {{ in_array($file->id, (array) old('files', [])) ? 'checked' : '' }}>
                                            </td>
                                            <td data-label="File No." class="fw-bold">{{ $file->file_no }}</td>
                                            <td data-label="Received">{{ date('d-m-Y', strtotime($file->received_date)) }}</td>
                                            <td data-label="Work Type">{{ $file->workType?->name }}</td>
                                            <td data-label="Details">{{ $file->description }}</td>
                                            <td data-label="Customer">{{ $file->customer?->name }}</td>
                                            <td data-label="Status">
                                                <span class="badge {{ $file->statusBadge() }}">{{ $file->statusLabel() }}</span>
                                            </td>
                                            <td data-label="Charged" class="text-end dr">{{ number_format((float) $file->customer_amount, 2, '.', ',') }}</td>
                                            <td data-label="Refund">
                                                <input type="number" min="0.01" step="0.01"
                                                    max="{{ $file->customer_amount }}"
                                                    class="form-control form-control-sm text-end js-amount"
                                                    name="amounts[{{ $file->id }}]"
                                                    value="{{ old('amounts.' . $file->id) }}"
                                                    placeholder="{{ number_format((float) $file->customer_amount, 2, '.', '') }}">
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="row g-3 mt-1">
                            <div class="col-md-6 ms-auto">
                                <div class="batch-total">
                                    <span class="label">Total Credited to Customers</span>
                                    <span class="value cr" id="batch_total">0.00</span>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2 justify-content-end mt-3">
                            <a href="{{ route('workfile.index') }}" class="btn btn-outline-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary" id="save_button" disabled>
                                <i class="bi bi-arrow-return-left me-1"></i> Return to Customer
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        @endif
    </section>
@endsection

@section('script')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const form = document.getElementById('return_form');

            if (!form) {
                return;
            }

            const checkAll = document.getElementById('check_all');
            const totalOut = document.getElementById('batch_total');
            const saveButton = document.getElementById('save_button');

            /*
             * Ticking a file fills in what was charged, so the figure going back
             * is visible rather than implied by a grey placeholder. Done on the
             * tick alone, never inside refresh() — refresh runs on every
             * keystroke, so filling in there would make the box impossible to
             * clear or edit.
             */
            const prefill = function (pick) {
                const amount = pick.closest('tr').querySelector('.js-amount');

                if (pick.checked && amount.value.trim() === '') {
                    amount.value = Number(pick.dataset.amount || 0).toFixed(2);
                }
            };

            const refresh = function () {
                let total = 0;
                let picked = 0;

                form.querySelectorAll('.js-pick').forEach(function (pick) {
                    const row = pick.closest('tr');
                    const amount = row.querySelector('.js-amount');

                    row.classList.toggle('picked', pick.checked);
                    // A disabled input posts nothing, so an amount left on an
                    // unticked row cannot reach the server.
                    amount.disabled = !pick.checked;

                    if (pick.checked) {
                        picked++;
                        const typed = amount.value.trim();
                        total += typed === ''
                            ? (Number(pick.dataset.amount) || 0)
                            : (Number(typed) || 0);
                    }
                });

                totalOut.textContent = total.toLocaleString('en-IN', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });

                saveButton.disabled = picked === 0;
            };

            checkAll.addEventListener('change', function () {
                form.querySelectorAll('.js-pick').forEach(function (pick) {
                    pick.checked = checkAll.checked;
                    prefill(pick);
                });
                refresh();
            });

            form.addEventListener('change', function (event) {
                if (event.target.classList.contains('js-pick')) {
                    prefill(event.target);
                }
                refresh();
            });

            form.addEventListener('input', refresh);

            form.querySelectorAll('.js-pick').forEach(prefill);
            refresh();
        });
    </script>
@endsection
