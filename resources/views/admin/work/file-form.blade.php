@extends('admin.layouts.app')

@section('title', ($file ? 'Edit File' : 'Receive File') . ' | Ac Info')

@section('style')
    @include('admin.party._style')

    <style>
        .party-page .entry-summary {
            background: linear-gradient(180deg, #f7faff 0%, #ffffff 100%);
            border-top: 4px solid #4154f1;
        }

        .party-page .summary-row {
            border-bottom: 1px solid #e9eef5;
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.8rem 0;
        }

        .party-page .summary-row:last-child {
            border-bottom: 0;
        }

        .party-page .summary-label {
            color: #64748b;
            font-size: 0.82rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .party-page .summary-value {
            color: #0f172a;
            font-weight: 700;
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        .party-page .summary-value.margin {
            font-size: 1.2rem;
        }

        .party-page .section-head {
            border-top: 1px solid #e9eef5;
            margin-top: 0.5rem;
            padding-top: 1rem;
        }

        .party-page .section-head h6 {
            margin-bottom: 0.15rem;
        }

        .timeline {
            list-style: none;
            margin: 0;
            padding: 0 0 0 1.1rem;
            border-left: 2px solid #e9eef5;
        }

        .timeline li {
            padding: 0 0 0.9rem 0.9rem;
            position: relative;
        }

        .timeline li:last-child {
            padding-bottom: 0;
        }

        .timeline li::before {
            content: "";
            position: absolute;
            left: -1.42rem;
            top: 0.35rem;
            width: 0.6rem;
            height: 0.6rem;
            border-radius: 50%;
            background: #fff;
            border: 2px solid #4154f1;
        }

        /* Newest first, so the top marker is the live one. */
        .timeline li:first-child::before {
            background: #4154f1;
        }

        .tl-head {
            color: #2f3d4a;
            font-size: 0.85rem;
        }

        .tl-remark {
            color: #0f172a;
            font-size: 0.85rem;
            margin-top: 0.2rem;
            padding: 0.35rem 0.55rem;
            background: #f8fafc;
            border-left: 3px solid #cbd5e1;
            border-radius: 3px;
        }

        .tl-meta {
            color: #94a3b8;
            font-size: 0.72rem;
            margin-top: 0.25rem;
        }
    </style>
@endsection

@section('content')
    @php
        $isEdit = (bool) $file;
        // Only ever reached for an existing file — receiving is its own screen now.
        $action = $isEdit ? route('workfile.edit', $file->id) : route('workfile.receive');
        $blocked = $workTypes->isEmpty() || $customers->isEmpty();
    @endphp

    <div class="pagetitle">
        <h1>{{ $isEdit ? 'Edit File ' . $file->file_no : 'Receive File' }}</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item"><a href="{{ route('workfile.index') }}">Work Files</a></li>
                <li class="breadcrumb-item active">{{ $isEdit ? 'Edit' : 'Receive' }}</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        @if ($blocked)
            <div class="alert alert-warning">
                Before a file can be received you need
                @if ($workTypes->isEmpty())
                    at least one <a href="{{ route('worktype.index') }}" class="alert-link">work type</a>
                @endif
                @if ($workTypes->isEmpty() && $customers->isEmpty())
                    and
                @endif
                @if ($customers->isEmpty())
                    at least one <a href="{{ route('party.create', 'customer') }}" class="alert-link">customer</a>
                @endif
                .
            </div>
        @else
            <div class="row">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
                                <h5 class="card-title mb-0">File Details</h5>
                                <a href="{{ route('workfile.index') }}" class="btn btn-outline-secondary btn-sm">
                                    <i class="bi bi-list-ul me-1"></i> All Files
                                </a>
                            </div>

                            <form class="row g-3" id="file_form" action="{{ $action }}" method="POST" enctype="multipart/form-data">
                                @csrf

                                <div class="col-md-4">
                                    <label for="received_date_display" class="form-label">Received Date <span class="required-mark">*</span></label>
                                    @include('partials._datefield', [
                                        'name' => 'received_date',
                                        'value' => old('received_date', $isEdit ? $file->received_date : date('Y-m-d')),
                                        'required' => true,
                                    ])
                                </div>

                                <div class="col-md-4">
                                    <label for="file_no" class="form-label">File No.</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-hash"></i></span>
                                        <input type="text" class="form-control" id="file_no" name="file_no" value="{{ old('file_no', $isEdit ? $file->file_no : '') }}" maxlength="30" placeholder="Auto">
                                    </div>
                                    <div class="side-hint">Leave blank to number it automatically.</div>
                                </div>

                                <div class="col-md-4">
                                    <label for="status" class="form-label">Status <span class="required-mark">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-flag"></i></span>
                                        <select class="form-select" id="status" name="status" required>
                                            @foreach ($statuses as $key => $text)
                                                <option value="{{ $key }}" {{ old('status', $isEdit ? $file->status : 'pending') === $key ? 'selected' : '' }}>{{ $text }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="side-hint cr fw-bold" id="status_hint" style="display: none;"></div>
                                </div>

                                <div class="col-md-4" id="refund_box" style="display: none;">
                                    <label for="returned_amount" class="form-label">Refund to Customer</label>
                                    <div class="input-group">
                                        <span class="input-group-text">INR</span>
                                        <input type="number" min="0.01" step="0.01" class="form-control" id="returned_amount" name="returned_amount"
                                            value="{{ old('returned_amount', $isEdit ? $file->returned_amount : '') }}"
                                            placeholder="{{ $isEdit ? number_format((float) $file->customer_amount, 2, '.', '') : '0.00' }}">
                                    </div>
                                    <div class="side-hint">Leave blank to refund the whole charge.</div>
                                </div>

                                <div class="col-12" id="screenshot_box" style="display: none;">
                                    <label for="approval_screenshot" class="form-label">Approval Screenshot <span class="required-mark">*</span></label>
                                    <input type="file" class="form-control" id="approval_screenshot" name="approval_screenshot" accept="image/*,application/pdf">
                                    <div class="side-hint">
                                        @if ($isEdit && $file->approval_screenshot)
                                            <i class="bi bi-paperclip"></i>
                                            <a href="{{ $file->screenshotUrl() }}" target="_blank" rel="noopener">Screenshot on file</a>
                                            — choose a file only if you want to replace it.
                                        @else
                                            Required to mark this file approved. JPG, PNG, WEBP or PDF, up to 4&nbsp;MB.
                                        @endif
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <label for="work_type_id" class="form-label">Type of Work <span class="required-mark">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-briefcase"></i></span>
                                        <select class="form-select" id="work_type_id" name="work_type_id" required autofocus>
                                            <option value="">Select type of work</option>
                                            @foreach ($workTypes as $wt)
                                                @php
                                                    // Built up in one place: option text is plain text, so stitching
                                                    // it together from directives inline is both unreadable and fragile.
                                                    $wtLabel = $wt->name;
                                                    if ($wt->default_rate !== null) {
                                                        $wtLabel .= ' — '.number_format((float) $wt->default_rate, 2, '.', ',');
                                                    }
                                                    if (! $wt->is_active) {
                                                        $wtLabel .= ' (retired)';
                                                    }
                                                @endphp
                                                <option value="{{ $wt->id }}" data-rate="{{ $wt->default_rate }}" {{ old('work_type_id', $isEdit ? $file->work_type_id : '') == $wt->id ? 'selected' : '' }}>{{ $wtLabel }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <label for="registration_no" class="form-label">Registration No.</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-car-front"></i></span>
                                        <input type="text" class="form-control text-uppercase" id="registration_no" name="registration_no"
                                            value="{{ old('registration_no', $isEdit ? $file->registration_no : '') }}"
                                            maxlength="20" autocomplete="off" placeholder="BR01AB1234">
                                    </div>
                                    <div class="side-hint">Stored without spaces or dashes, so the same vehicle is always found.</div>
                                </div>

                                <div class="col-md-6">
                                    <label for="description" class="form-label">File Details</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-card-text"></i></span>
                                        <input type="text" class="form-control" id="description" name="description" value="{{ old('description', $isEdit ? $file->description : '') }}" maxlength="255" placeholder="Vehicle no., party name, reference">
                                    </div>
                                    <div class="side-hint">Shown next to the work type on both statements.</div>
                                </div>

                                <div class="col-12 section-head">
                                    <h6>Customer <span class="dr">— will be debited</span></h6>
                                    <div class="side-hint">The customer who gave you this file is charged for the work.</div>
                                </div>

                                <div class="col-md-6">
                                    <label for="customer_id" class="form-label">Customer <span class="required-mark">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-people"></i></span>
                                        <select class="form-select" id="customer_id" name="customer_id" required>
                                            <option value="">Select customer</option>
                                            @foreach ($customers as $c)
                                                <option value="{{ $c->id }}" data-balance="{{ $c->current_balance }}" {{ old('customer_id', $isEdit ? $file->customer_id : '') == $c->id ? 'selected' : '' }}>{{ $c->name }} ({{ $c->mobile }}){{ $c->is_active ? '' : ' — inactive' }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <label for="customer_amount" class="form-label">Amount Charged <span class="required-mark">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">INR</span>
                                        <input type="number" min="0" step="0.01" class="form-control" id="customer_amount" name="customer_amount" value="{{ old('customer_amount', $isEdit ? $file->customer_amount : '') }}" placeholder="0.00" required>
                                    </div>
                                </div>

                                <div class="col-12 section-head">
                                    <h6>Vendor <span class="cr">— will be credited</span> <span class="text-muted fw-normal" style="font-size: 0.85rem;">(optional)</span></h6>
                                    <div class="side-hint">Fill this in when the file is handed to a vendor. Leave blank for work done in-house.</div>
                                </div>

                                <div class="col-md-4">
                                    <label for="vendor_id" class="form-label">Vendor</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-truck"></i></span>
                                        <select class="form-select" id="vendor_id" name="vendor_id">
                                            <option value="">In-house / not assigned</option>
                                            @foreach ($vendors as $v)
                                                <option value="{{ $v->id }}" data-balance="{{ $v->current_balance }}" {{ old('vendor_id', $isEdit ? $file->vendor_id : '') == $v->id ? 'selected' : '' }}>{{ $v->name }} ({{ $v->mobile }}){{ $v->is_active ? '' : ' — inactive' }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <label for="vendor_amount" class="form-label">Vendor Amount</label>
                                    <div class="input-group">
                                        <span class="input-group-text">INR</span>
                                        <input type="number" min="0" step="0.01" class="form-control" id="vendor_amount" name="vendor_amount" value="{{ old('vendor_amount', $isEdit ? $file->vendor_amount : '') }}" placeholder="0.00">
                                    </div>
                                    <div class="side-hint">Leave blank until the rate is agreed.</div>
                                </div>

                                <div class="col-md-4">
                                    <label for="vendor_date_display" class="form-label">Given On</label>
                                    @include('partials._datefield', [
                                        'name' => 'vendor_date',
                                        'value' => old('vendor_date', $isEdit ? $file->vendor_date : ''),
                                    ])
                                    <div class="side-hint">Defaults to the received date.</div>
                                </div>

                                <div class="col-12 section-head">
                                    <label for="remarks" class="form-label">Remarks</label>
                                    <textarea class="form-control" id="remarks" name="remarks" rows="2" maxlength="255">{{ old('remarks', $isEdit ? $file->remarks : '') }}</textarea>
                                </div>

                                <div class="col-12 d-flex flex-wrap gap-2 justify-content-end mt-2">
                                    <a href="{{ route('workfile.index') }}" class="btn btn-outline-secondary">Cancel</a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-check2-circle me-1"></i> {{ $isEdit ? 'Update File' : 'Receive File' }}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4 mt-4 mt-lg-0">
                    <div class="card entry-summary">
                        <div class="card-body">
                            <h5 class="card-title mb-2">Ledger Effect</h5>
                            <div class="summary-row">
                                <span class="summary-label">Customer</span>
                                <span class="summary-value" id="summary_customer">Not selected</span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">Debit</span>
                                <span class="summary-value dr" id="summary_debit">0.00</span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">Balance After</span>
                                <span class="summary-value" id="summary_customer_after">0.00</span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">Vendor</span>
                                <span class="summary-value" id="summary_vendor">In-house</span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">Credit</span>
                                <span class="summary-value cr" id="summary_credit">0.00</span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">Balance After</span>
                                <span class="summary-value" id="summary_vendor_after">0.00</span>
                            </div>
                            <div class="summary-row">
                                <span class="summary-label">Margin</span>
                                <span class="summary-value margin" id="summary_margin">0.00</span>
                            </div>
                        </div>
                    </div>

                    @if ($isEdit)
                        <div class="alert alert-warning mt-3">
                            <i class="bi bi-info-circle me-1"></i>
                            Changing an amount, party or date here rewrites this file's existing ledger entries rather than
                            adding a correction, so both statements will show only the corrected figure.
                        </div>

                        <div class="card mb-0">
                            <div class="card-body">
                                <h5 class="card-title mb-2">History</h5>

                                <ol class="timeline">
                                    @forelse ($timeline as $entry)
                                        <li>
                                            <div class="tl-head">
                                                @if ($entry->isOpening())
                                                    Received &mdash; <strong>{{ $entry->toLabel() }}</strong>
                                                @elseif ($entry->isNoteOnly())
                                                    Note &mdash; <strong>{{ $entry->toLabel() }}</strong>
                                                @else
                                                    {{ $entry->fromLabel() }} &rarr; <strong>{{ $entry->toLabel() }}</strong>
                                                @endif
                                            </div>
                                            @if ($entry->remark)
                                                <div class="tl-remark">{{ $entry->remark }}</div>
                                            @endif
                                            <div class="tl-meta">
                                                {{ date('d-m-Y', strtotime($entry->created_at)) }}
                                                at {{ date('h:i A', strtotime($entry->created_at)) }}
                                                @if ($entry->user)
                                                    &middot; {{ $entry->user->name }}
                                                @endif
                                            </div>
                                        </li>
                                    @empty
                                        <li><div class="tl-meta">Nothing recorded yet.</div></li>
                                    @endforelse
                                </ol>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </section>
@endsection

@section('script')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const form = document.getElementById('file_form');

            if (!form) {
                return;
            }

            const workType = document.getElementById('work_type_id');
            const status = document.getElementById('status');
            const statusHint = document.getElementById('status_hint');
            const customer = document.getElementById('customer_id');
            const customerAmount = document.getElementById('customer_amount');
            const vendor = document.getElementById('vendor_id');
            const vendorAmount = document.getElementById('vendor_amount');

            const setText = function (id, text) {
                document.getElementById(id).textContent = text;
            };

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

            const dataOf = function (field, key) {
                const selected = field.options[field.selectedIndex];
                return selected && selected.dataset[key] ? Number(selected.dataset[key]) : 0;
            };

            const textOf = function (field) {
                return field.value && field.options[field.selectedIndex]
                    ? field.options[field.selectedIndex].text
                    : '';
            };

            // Both date fields parse and validate themselves, keeping their hidden
            // Y-m-d values in step — see assets/js/datepicker.js.
            const update = function () {
                // A cancelled file posts nothing and a returned one nets to nothing
                // for the customer, so the panel must say so — otherwise it would
                // promise a debit the save is about to withdraw or hand straight back.
                const cancelled = status.value === 'cancelled';
                const returned = status.value === 'paper_returned';

                statusHint.style.display = (cancelled || returned) ? '' : 'none';
                statusHint.textContent = cancelled
                    ? "Cancelling removes this file's entries from both ledgers. The file and its number are kept."
                    : 'The papers go back and the full amount is credited to the customer. The original charge stays on the statement beside it.';

                document.getElementById('screenshot_box').style.display =
                    status.value === 'approval_done' ? '' : 'none';

                const refundBox = document.getElementById('refund_box');
                const refundField = document.getElementById('returned_amount');
                refundBox.style.display = returned ? '' : 'none';
                // A disabled field posts nothing, so a stale refund figure cannot
                // survive a change of mind about the status.
                refundField.disabled = !returned;

                const typed = Number(customerAmount.value) || 0;
                // A blank refund gives the whole charge back.
                const refunded = refundField.value.trim() === ''
                    ? typed
                    : Math.min(Number(refundField.value) || 0, typed);
                // Returned still debits, then credits the refund back.
                const debit = cancelled ? 0 : (returned ? typed - refunded : typed);
                const credit = cancelled ? 0 : Number(vendorAmount.value) || 0;

                setText('summary_customer', customer.value ? textOf(customer) : 'Not selected');
                setText('summary_debit', money(debit));
                setText('summary_customer_after', customer.value ? balance(dataOf(customer, 'balance') + debit) : '0.00');

                setText('summary_vendor', vendor.value ? textOf(vendor) : 'In-house');
                setText('summary_credit', money(credit));
                setText('summary_vendor_after', vendor.value ? balance(dataOf(vendor, 'balance') - credit) : '0.00');

                setText('summary_margin', money(debit - credit));
            };

            // Picking a type fills in its standard rate, but never overwrites an
            // amount already typed — the rate is a starting point, not a rule.
            workType.addEventListener('change', function () {
                const rate = dataOf(workType, 'rate');
                if (rate && !customerAmount.value) {
                    customerAmount.value = rate.toFixed(2);
                }
                update();
            });

            [customer, customerAmount, vendor, vendorAmount, status].forEach(function (field) {
                field.addEventListener('input', update);
                field.addEventListener('change', update);
            });

            update();
        });
    </script>
@endsection
