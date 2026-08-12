@extends('admin.layouts.app')

@section('title', 'Payment | Ac Info')

@section('style')
    <style>
        input::-webkit-outer-spin-button,
        input::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        .payment-page .card {
            border: 0;
            border-radius: 8px;
            box-shadow: 0 4px 24px rgba(1, 41, 112, 0.08);
        }

        .payment-page .form-label {
            color: #334155;
            font-size: 0.86rem;
            font-weight: 700;
            margin-bottom: 0.4rem;
        }

        .payment-page .form-control,
        .payment-page .form-select,
        .payment-page .input-group-text {
            border-color: #d9e2ef;
            border-radius: 7px;
            min-height: 44px;
        }

        .payment-page .input-group > .form-control,
        .payment-page .input-group > .form-select {
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
        }

        .payment-page .input-group-text {
            background: #f8fafc;
            color: #64748b;
            min-width: 44px;
            justify-content: center;
        }

        .payment-page .form-control:focus,
        .payment-page .form-select:focus {
            border-color: #dc3545;
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.14);
        }

        .payment-page .required-mark {
            color: #dc3545;
        }

        .payment-page .payment-summary {
            background: linear-gradient(180deg, #fff8f8 0%, #ffffff 100%);
            border-top: 4px solid #dc3545;
        }

        .payment-page .summary-row {
            border-bottom: 1px solid #e9eef5;
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.85rem 0;
        }

        .payment-page .summary-row:last-child {
            border-bottom: 0;
        }

        .payment-page .summary-label {
            color: #64748b;
            font-size: 0.82rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .payment-page .summary-value {
            color: #0f172a;
            font-weight: 700;
            text-align: right;
        }

        .payment-page .amount-preview {
            color: #dc3545;
            font-size: 1.35rem;
        }

        .payment-page .balance-chip {
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
        <h1>Payment Entry</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item active">Payment</li>
            </ol>
        </nav>
    </div>

    <section class="section dashboard payment-page">
        @if ($errors->any())
            <div class="alert alert-danger bg-danger text-light border-0 alert-dismissible fade show">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"
                    aria-label="Close"></button>
            </div>
        @endif

        @if (Session::has('success'))
            <div class="alert alert-success bg-success text-light border-0 alert-dismissible fade show" role="alert">
                {{ Session::get('success') }}
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"
                    aria-label="Close"></button>
            </div>
        @endif

        @if (Session::has('error'))
            <div class="alert alert-danger bg-danger text-light border-0 alert-dismissible fade show" role="alert">
                {{ Session::get('error') }}
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"
                    aria-label="Close"></button>
            </div>
        @endif

        <div class="row">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
                            <div>
                                <h5 class="card-title mb-0">Payment Details</h5>
                            </div>
                            <a href="{{ route('viewclient') }}" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-people me-1"></i> Clients
                            </a>
                        </div>

                        <form class="row g-3" id="payment_form" action="{{ route('payment') }}" method="POST">
                            @csrf
                            <div class="col-md-6">
                                <label for="client_name" class="form-label">Client <span class="required-mark">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                                    <select class="form-select" name="client_name" id="client_name" required autofocus>
                                        <option value="" selected>Select client ledger</option>
                                        @foreach ($clientlist as $clients)
                                            <option {{ old('client_name') == $clients->id ? 'selected' : '' }} value="{{ $clients->id }}" data-balance="{{ $clients->current_balance }}">{{ $clients->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="balance-chip" id="current_balance_hint">Current balance: INR 0.00</div>
                            </div>
                            <div class="col-md-6">
                                <label for="paymentMode" class="form-label">Payment Mode <span class="required-mark">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-credit-card"></i></span>
                                    <select class="form-select" name="paymentMode" id="paymentMode" required>
                                        <option value="" selected>Select payment mode</option>
                                        @foreach ($pay_mode as $items)
                                            <option {{ old('paymentMode') == $items->id ? 'selected' : '' }} value="{{ $items->id }}">{{ $items->payment_mode }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="txn_date_display" class="form-label">Transaction Date <span class="required-mark">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-calendar3"></i></span>
                                    <input type="text" class="form-control" id="txn_date_display" value="{{ date('d/m/Y', strtotime(old('txn_date', date('Y-m-d')))) }}" placeholder="dd/mm/yyyy" inputmode="numeric" pattern="\d{2}/\d{2}/\d{4}" required>
                                    <input type="hidden" id="txn_date" name="txn_date" value="{{ old('txn_date', date('Y-m-d')) }}">
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="amount" class="form-label">Amount <span class="required-mark">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">INR</span>
                                    <input type="number" min="0.01" max="500000.00" step="0.01" class="form-control" id="amount" name="amount" value="{{ old('amount') }}" placeholder="0.00" required>
                                </div>
                            </div>

                            <div class="col-md-12">
                                <label for="remarks" class="form-label">Remarks <span class="required-mark">*</span></label>
                                <textarea class="form-control" id="remarks" name="remarks" rows="4" required>{{ old('remarks') }}</textarea>
                            </div>

                            <div class="col-12 d-flex flex-wrap gap-2 justify-content-end mt-2">
                                <button type="reset" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i> Reset
                                </button>
                                <button type="submit" class="btn btn-danger">
                                    <i class="bi bi-check2-circle me-1"></i> Save Payment
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-4 mt-4 mt-lg-0">
                <div class="card payment-summary">
                    <div class="card-body">
                        <h5 class="card-title mb-2">Payment Summary</h5>
                        <div class="summary-row">
                            <span class="summary-label">Client</span>
                            <span class="summary-value" id="summary_client">Not selected</span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Mode</span>
                            <span class="summary-value" id="summary_mode">Not selected</span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Current Balance</span>
                            <span class="summary-value" id="summary_balance">INR 0.00</span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Date</span>
                            <span class="summary-value" id="summary_date">Not selected</span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Payment Amount</span>
                            <span class="summary-value amount-preview" id="summary_amount">INR 0.00</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

@section('script')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const client = document.getElementById('client_name');
            const mode = document.getElementById('paymentMode');
            const date = document.getElementById('txn_date');
            const dateDisplay = document.getElementById('txn_date_display');
            const amount = document.getElementById('amount');
            const form = document.getElementById('payment_form');
            const balanceHint = document.getElementById('current_balance_hint');

            const setText = function (id, text) {
                document.getElementById(id).textContent = text || 'Not selected';
            };

            const selectedText = function (field) {
                return field.options[field.selectedIndex] ? field.options[field.selectedIndex].text : '';
            };

            const formatAmount = function (value) {
                const parsed = Number(value);
                if (!parsed) {
                    return 'INR 0.00';
                }
                return new Intl.NumberFormat('en-IN', {
                    style: 'currency',
                    currency: 'INR'
                }).format(parsed);
            };

            const getSelectedBalance = function () {
                const selected = client.options[client.selectedIndex];
                return selected ? selected.dataset.balance : 0;
            };

            const parseDisplayDate = function (value) {
                const match = value.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
                if (!match) {
                    return '';
                }

                const day = Number(match[1]);
                const month = Number(match[2]);
                const year = Number(match[3]);
                const parsed = new Date(year, month - 1, day);

                if (parsed.getFullYear() !== year || parsed.getMonth() !== month - 1 || parsed.getDate() !== day) {
                    return '';
                }

                return year + '-' + String(month).padStart(2, '0') + '-' + String(day).padStart(2, '0');
            };

            const syncDateValue = function () {
                const parsed = parseDisplayDate(dateDisplay.value);
                date.value = parsed;
                dateDisplay.setCustomValidity(parsed ? '' : 'Enter date as dd/mm/yyyy');
            };

            const updateSummary = function () {
                syncDateValue();
                setText('summary_client', client.value ? selectedText(client) : 'Not selected');
                setText('summary_mode', mode.value ? selectedText(mode) : 'Not selected');
                setText('summary_balance', client.value ? formatAmount(getSelectedBalance()) : 'INR 0.00');
                setText('summary_date', dateDisplay.value || 'Not selected');
                setText('summary_amount', formatAmount(amount.value));
                balanceHint.textContent = 'Current balance: ' + (client.value ? formatAmount(getSelectedBalance()) : 'INR 0.00');
            };

            [client, mode, amount].forEach(function (field) {
                field.addEventListener('input', updateSummary);
                field.addEventListener('change', updateSummary);
            });

            dateDisplay.addEventListener('input', function () {
                let value = dateDisplay.value.replace(/\D/g, '').slice(0, 8);
                if (value.length >= 5) {
                    value = value.slice(0, 2) + '/' + value.slice(2, 4) + '/' + value.slice(4);
                } else if (value.length >= 3) {
                    value = value.slice(0, 2) + '/' + value.slice(2);
                }
                dateDisplay.value = value;
                updateSummary();
            });
            dateDisplay.addEventListener('change', updateSummary);

            form.addEventListener('submit', function () {
                syncDateValue();
            });

            form.addEventListener('reset', function () {
                window.setTimeout(updateSummary, 0);
            });

            updateSummary();
        });
    </script>
@endsection
