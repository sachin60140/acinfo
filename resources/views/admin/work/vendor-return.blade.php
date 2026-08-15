@extends('admin.layouts.app')

@section('title', 'Papers Returned by Vendor | Ac Info')

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
            background: #eaf7f0;
            border: 1px solid #198754;
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
        <h1>Papers Returned by Vendor</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item"><a href="{{ route('workfile.index') }}">Work Files</a></li>
                <li class="breadcrumb-item active">Returned by Vendor</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        @if ($files->isEmpty())
            <div class="alert alert-info">
                No files are out with a vendor at the moment.
                <a href="{{ route('workfile.assign') }}" class="alert-link">Give work to a vendor</a> first.
            </div>
        @else
            <form id="return_form" action="{{ route('workfile.vendorreturn') }}" method="POST">
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
                                    <input type="text" class="form-control" id="remark" name="remark" value="{{ old('remark') }}" maxlength="200" placeholder="Why the papers came back" required>
                                </div>
                                <div class="side-hint">This changes the vendor's balance, so the reason is kept on each file's history.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                            <div>
                                <h5 class="card-title mb-0">Files Out With Vendors</h5>
                                <div class="side-hint">
                                    Taking a file back reverses what was booked to that vendor. Their statement keeps both
                                    lines &mdash; what was booked and what came back &mdash; and nets to nothing owed.
                                    The file returns to <strong>In Office</strong>.
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
                                        <th>Vendor</th>
                                        <th>Given On</th>
                                        <th>Work Type</th>
                                        <th>Details</th>
                                        <th>Customer</th>
                                        <th class="text-end">Booked to Vendor</th>
                                        <th style="min-width: 9rem;" class="text-end">Reverse</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($files as $file)
                                        <tr>
                                            <td>
                                                <input class="form-check-input js-pick" type="checkbox" name="files[]" value="{{ $file->id }}"
                                                    data-amount="{{ $file->vendor_amount }}"
                                                    {{ in_array($file->id, (array) old('files', [])) ? 'checked' : '' }}>
                                            </td>
                                            <td data-label="File No." class="fw-bold">{{ $file->file_no }}</td>
                                            <td data-label="Vendor">{{ $file->vendor?->name }}</td>
                                            <td data-label="Given On">{{ $file->vendor_date ? date('d-m-Y', strtotime($file->vendor_date)) : '—' }}</td>
                                            <td data-label="Work Type">{{ $file->workType?->name }}</td>
                                            <td data-label="Details">{{ $file->description }}</td>
                                            <td data-label="Customer">{{ $file->customer?->name }}</td>
                                            <td data-label="Booked to Vendor" class="text-end cr">
                                                {{ $file->vendor_amount === null ? '—' : number_format((float) $file->vendor_amount, 2, '.', ',') }}
                                            </td>
                                            <td data-label="Reverse">
                                                <input type="number" min="0.01" step="0.01"
                                                    max="{{ $file->vendor_amount }}"
                                                    class="form-control form-control-sm text-end js-amount"
                                                    name="amounts[{{ $file->id }}]"
                                                    value="{{ old('amounts.' . $file->id) }}"
                                                    placeholder="{{ $file->vendor_amount === null ? '0.00' : number_format((float) $file->vendor_amount, 2, '.', '') }}">
                                                <div class="side-hint">Blank reverses it all</div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="row g-3 mt-1">
                            <div class="col-md-6 ms-auto">
                                <div class="batch-total">
                                    <span class="label">Total Reversed to Vendors</span>
                                    <span class="value dr" id="batch_total">0.00</span>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2 justify-content-end mt-3">
                            <a href="{{ route('workfile.index') }}" class="btn btn-outline-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary" id="save_button" disabled>
                                <i class="bi bi-arrow-return-left me-1"></i> Take Files Back
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
             * Ticking a file fills in what was booked to the vendor, so the
             * figure being reversed is visible rather than implied by a grey
             * placeholder. Done on the tick alone, never inside refresh() —
             * refresh runs on every keystroke, so filling in there would make the
             * box impossible to clear or edit.
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
                    // An amount on an unticked row is going nowhere, and a disabled
                    // input posts nothing.
                    amount.disabled = !pick.checked;

                    if (pick.checked) {
                        picked++;
                        // Blank means reverse the whole booking.
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
