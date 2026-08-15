@extends('admin.layouts.app')

@section('title', 'Give Work to Vendor | Ac Info')

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

        .assign-table .form-control {
            min-height: 38px;
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

        .balance-chip {
            background: #fff1f2;
            border: 1px solid #fecdd3;
            border-radius: 7px;
            color: #9f1239;
            display: inline-flex;
            font-size: 0.85rem;
            font-weight: 700;
            margin-top: 0.55rem;
            padding: 0.45rem 0.7rem;
        }
    </style>
@endsection

@section('content')
    <div class="pagetitle">
        <h1>Give Work to Vendor</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item"><a href="{{ route('workfile.index') }}">Work Files</a></li>
                <li class="breadcrumb-item active">Give to Vendor</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        @if ($vendors->isEmpty())
            <div class="alert alert-warning">
                Add a <a href="{{ route('party.create', 'vendor') }}" class="alert-link">vendor</a> before giving work out.
            </div>
        @elseif ($files->isEmpty())
            <div class="alert alert-info">
                No files are waiting to be given out. Everything received is either already with a vendor or cancelled.
                <a href="{{ route('workfile.receive') }}" class="alert-link">Receive more files</a>.
            </div>
        @else
            <form id="assign_form" action="{{ route('workfile.assign') }}" method="POST">
                @csrf

                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Vendor and Date</h5>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="vendor_id" class="form-label">Vendor <span class="required-mark">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-truck"></i></span>
                                    <select class="form-select" id="vendor_id" name="vendor_id" required autofocus>
                                        <option value="">Select vendor</option>
                                        @foreach ($vendors as $v)
                                            <option value="{{ $v->id }}" data-balance="{{ $v->current_balance }}" {{ old('vendor_id') == $v->id ? 'selected' : '' }}>{{ $v->name }} ({{ $v->mobile }})</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="balance-chip" id="balance_hint">Current balance: 0.00</div>
                            </div>

                            <div class="col-md-3">
                                <label for="vendor_date_display" class="form-label">Given On <span class="required-mark">*</span></label>
                                @include('partials._datefield', [
                                    'name' => 'vendor_date',
                                    'value' => old('vendor_date', date('Y-m-d')),
                                    'required' => true,
                                ])
                            </div>

                            <div class="col-md-3">
                                <label for="remark" class="form-label">Remark</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-chat-left-text"></i></span>
                                    <input type="text" class="form-control" id="remark" name="remark" value="{{ old('remark') }}" maxlength="200" placeholder="Added to each file's history">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                            <div>
                                <h5 class="card-title mb-0">Files Waiting to Go Out</h5>
                                <div class="side-hint">
                                    Tick the files you are handing over. Leave an amount blank if the rate is not agreed yet —
                                    nothing is posted to the vendor until it is filled in.
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
                                        <th class="text-end">Charged</th>
                                        <th style="min-width: 9rem;" class="text-end">Vendor Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($files as $file)
                                        <tr>
                                            <td>
                                                <input class="form-check-input js-pick" type="checkbox" name="files[]" value="{{ $file->id }}"
                                                    {{ in_array($file->id, (array) old('files', [])) ? 'checked' : '' }}>
                                            </td>
                                            <td data-label="File No." class="fw-bold">{{ $file->file_no }}</td>
                                            <td data-label="Received">{{ date('d-m-Y', strtotime($file->received_date)) }}</td>
                                            <td data-label="Work Type">{{ $file->workType?->name }}</td>
                                            <td data-label="Details">{{ $file->description }}</td>
                                            <td data-label="Customer">{{ $file->customer?->name }}</td>
                                            <td data-label="Charged" class="text-end dr">{{ number_format((float) $file->customer_amount, 2, '.', ',') }}</td>
                                            <td data-label="Vendor Amount">
                                                <input type="number" min="0" step="0.01" class="form-control text-end js-amount"
                                                    name="amounts[{{ $file->id }}]"
                                                    value="{{ old('amounts.' . $file->id) }}" placeholder="0.00">
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="row g-3 mt-1">
                            <div class="col-md-6 ms-auto">
                                <div class="batch-total">
                                    <span class="label">Total Credit to Vendor</span>
                                    <span class="value cr" id="batch_total">0.00</span>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2 justify-content-end mt-3">
                            <a href="{{ route('workfile.index') }}" class="btn btn-outline-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check2-circle me-1"></i> Give to Vendor
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
            const form = document.getElementById('assign_form');

            if (!form) {
                return;
            }

            const vendor = document.getElementById('vendor_id');
            const checkAll = document.getElementById('check_all');
            const totalOut = document.getElementById('batch_total');
            const balanceHint = document.getElementById('balance_hint');

            const money = function (value) {
                return (Number(value) || 0).toLocaleString('en-IN', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            };

            const balance = function (value) {
                const parsed = Number(value) || 0;
                const shown = money(Math.abs(parsed));

                if (Math.abs(parsed) < 0.005) {
                    return shown;
                }

                return shown + (parsed < 0 ? ' Cr' : ' Dr');
            };

            const currentBalance = function () {
                const selected = vendor.options[vendor.selectedIndex];
                return selected && selected.dataset.balance ? Number(selected.dataset.balance) : 0;
            };

            const rowOf = function (element) {
                return element.closest('tr');
            };

            const retotal = function () {
                let total = 0;

                form.querySelectorAll('.js-pick').forEach(function (pick) {
                    const row = rowOf(pick);
                    const amount = row.querySelector('.js-amount');

                    row.classList.toggle('picked', pick.checked);
                    // An amount on an unticked row is not going anywhere; greying it
                    // out is how the page says so before the user presses save.
                    amount.disabled = !pick.checked;

                    if (pick.checked) {
                        total += Number(amount.value) || 0;
                    }
                });

                totalOut.textContent = money(total);
                balanceHint.textContent = 'Balance after: '
                    + (vendor.value ? balance(currentBalance() - total) : money(total));
            };

            checkAll.addEventListener('change', function () {
                form.querySelectorAll('.js-pick').forEach(function (pick) {
                    pick.checked = checkAll.checked;
                });
                retotal();
            });

            form.addEventListener('change', retotal);
            form.addEventListener('input', retotal);

            retotal();
        });
    </script>
@endsection
