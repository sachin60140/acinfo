@extends('admin.layouts.app')

@section('title', $label . ' Entry | Ac Info')

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
            padding: 0.85rem 0;
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

        .party-page .summary-value.new-balance {
            font-size: 1.2rem;
        }
    </style>
@endsection

@section('content')
    <div class="pagetitle">
        <h1>{{ $label }} Entry</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item"><a href="{{ route('party.index', $type) }}">{{ $label }}s</a></li>
                <li class="breadcrumb-item active">Entry</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        <div class="row">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
                            <h5 class="card-title mb-0">New Ledger Entry</h5>
                            <a href="{{ route('party.index', $type) }}" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-list-ul me-1"></i> All {{ $label }}s
                            </a>
                        </div>

                        @if ($partylist->isEmpty())
                            <div class="alert alert-warning mb-0">
                                No active {{ strtolower($label) }}s yet.
                                <a href="{{ route('party.create', $type) }}" class="alert-link">Add one first</a>.
                            </div>
                        @else
                            <form class="row g-3" id="entry_form" action="{{ route('party.entry', $type) }}" method="POST">
                                @csrf

                                <div class="col-md-6">
                                    <label for="party_id" class="form-label">{{ $label }} <span class="required-mark">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                                        <select class="form-select" name="party_id" id="party_id" required autofocus>
                                            <option value="">Select {{ strtolower($label) }}</option>
                                            @foreach ($partylist as $p)
                                                <option value="{{ $p->id }}" data-balance="{{ $p->current_balance }}" {{ old('party_id') == $p->id ? 'selected' : '' }}>
                                                    {{ $p->name }} ({{ $p->mobile }})
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Entry Type <span class="required-mark">*</span></label>
                                    <div class="side-toggle">
                                        <input type="radio" name="entry_type" id="entry_debit" value="debit" {{ old('entry_type', $defaultEntryType) === 'debit' ? 'checked' : '' }} required>
                                        <label for="entry_debit" class="dr-option">Debit (Dr)</label>

                                        <input type="radio" name="entry_type" id="entry_credit" value="credit" {{ old('entry_type', $defaultEntryType) === 'credit' ? 'checked' : '' }} required>
                                        <label for="entry_credit" class="cr-option">Credit (Cr)</label>
                                    </div>
                                    <div class="side-hint">
                                        @if ($type === 'customer')
                                            Debit = sale / amount charged &nbsp;·&nbsp; Credit = payment received
                                        @else
                                            Debit = payment made to vendor &nbsp;·&nbsp; Credit = purchase / bill received
                                        @endif
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <label for="txn_date_display" class="form-label">Transaction Date <span class="required-mark">*</span></label>
                                    @include('partials._datefield', [
                                        'name' => 'txn_date',
                                        'value' => old('txn_date', date('Y-m-d')),
                                        'required' => true,
                                    ])
                                </div>

                                <div class="col-md-6">
                                    <label for="amount" class="form-label">Amount <span class="required-mark">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">INR</span>
                                        <input type="number" min="0.01" step="0.01" class="form-control" id="amount" name="amount" value="{{ old('amount') }}" placeholder="0.00" required>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <label for="payment_mode" class="form-label">Payment Mode</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-credit-card"></i></span>
                                        <select class="form-select" name="payment_mode" id="payment_mode">
                                            <option value="">Not specified</option>
                                            @foreach ($paymentModes as $mode)
                                                <option value="{{ $mode }}" {{ old('payment_mode') === $mode ? 'selected' : '' }}>{{ $mode }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <label for="ref_no" class="form-label">Bill / Reference No.</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-hash"></i></span>
                                        <input type="text" class="form-control" id="ref_no" name="ref_no" value="{{ old('ref_no') }}" maxlength="50" placeholder="Optional">
                                    </div>
                                </div>

                                <div class="col-12">
                                    <label for="particular" class="form-label">Particulars <span class="required-mark">*</span></label>
                                    <textarea class="form-control" id="particular" name="particular" rows="3" maxlength="255" required>{{ old('particular') }}</textarea>
                                </div>

                                <div class="col-12 d-flex flex-wrap gap-2 justify-content-end mt-2">
                                    <button type="reset" class="btn btn-outline-secondary">
                                        <i class="bi bi-arrow-counterclockwise me-1"></i> Reset
                                    </button>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-check2-circle me-1"></i> Save Entry
                                    </button>
                                </div>
                            </form>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-lg-4 mt-4 mt-lg-0">
                <div class="card entry-summary">
                    <div class="card-body">
                        <h5 class="card-title mb-2">Entry Summary</h5>
                        <div class="summary-row">
                            <span class="summary-label">{{ $label }}</span>
                            <span class="summary-value" id="summary_party">Not selected</span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Current Balance</span>
                            <span class="summary-value" id="summary_balance">0.00</span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">This Entry</span>
                            <span class="summary-value" id="summary_amount">0.00</span>
                        </div>
                        <div class="summary-row">
                            <span class="summary-label">Balance After</span>
                            <span class="summary-value new-balance" id="summary_new_balance">0.00</span>
                        </div>
                    </div>
                </div>

                <div class="card mt-3" id="statement_card" style="display: none;">
                    <div class="card-body">
                        <h5 class="card-title mb-2">Statement</h5>
                        <a href="#" id="statement_link" class="btn btn-outline-primary btn-sm w-100" target="_blank">
                            <i class="bi bi-file-earmark-text me-1"></i> Open full statement
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

@section('script')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const form = document.getElementById('entry_form');

            if (!form) {
                return;
            }

            const party = document.getElementById('party_id');
            const amount = document.getElementById('amount');
            const statementCard = document.getElementById('statement_card');
            const statementLink = document.getElementById('statement_link');

            // {id} is replaced client-side rather than built by string concatenation,
            // so the URL always matches whatever the route actually generates.
            const statementTemplate = @json(route('party.statement', ['id' => '__ID__']));

            const setText = function (id, text) {
                document.getElementById(id).textContent = text;
            };

            const formatBalance = function (value) {
                const parsed = Number(value) || 0;
                const shown = Math.abs(parsed).toLocaleString('en-IN', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });

                if (Math.abs(parsed) < 0.005) {
                    return shown;
                }

                return shown + (parsed < 0 ? ' Cr' : ' Dr');
            };

            const currentBalance = function () {
                const selected = party.options[party.selectedIndex];
                return selected && selected.dataset.balance ? Number(selected.dataset.balance) : 0;
            };

            const entrySign = function () {
                const checked = form.querySelector('input[name="entry_type"]:checked');
                return checked && checked.value === 'credit' ? -1 : 1;
            };

            // The date field parses and validates itself — see assets/js/datepicker.js.
            const updateSummary = function () {
                const balance = currentBalance();
                const value = (Number(amount.value) || 0) * entrySign();

                setText('summary_party', party.value ? party.options[party.selectedIndex].text : 'Not selected');
                setText('summary_balance', formatBalance(balance));
                setText('summary_amount', formatBalance(value));
                setText('summary_new_balance', formatBalance(balance + value));

                if (party.value) {
                    statementLink.href = statementTemplate.replace('__ID__', party.value);
                    statementCard.style.display = '';
                } else {
                    statementCard.style.display = 'none';
                }
            };

            [party, amount].forEach(function (field) {
                field.addEventListener('input', updateSummary);
                field.addEventListener('change', updateSummary);
            });

            form.querySelectorAll('input[name="entry_type"]').forEach(function (radio) {
                radio.addEventListener('change', updateSummary);
            });

            form.addEventListener('reset', function () {
                window.setTimeout(updateSummary, 0);
            });

            updateSummary();
        });
    </script>
@endsection
