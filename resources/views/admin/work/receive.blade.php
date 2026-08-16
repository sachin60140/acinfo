@extends('admin.layouts.app')

@section('title', 'Receive Files | Ac Info')

@section('style')
    @include('admin.party._style')

    <style>
        .row-table {
            font-size: 13px;
        }

        .row-table th {
            color: #64748b;
            font-size: 0.74rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .row-table td {
            vertical-align: top;
        }

        .row-table .form-control,
        .row-table .form-select {
            min-height: 40px;
        }

        .row-table td.row-no {
            color: #94a3b8;
            font-weight: 700;
            padding-top: 0.65rem;
            width: 2rem;
        }

        .batch-total {
            background: #f6f9ff;
            border: 1px solid #4154f1;
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
            background: #eef2ff;
            border: 1px solid #c7d2fe;
            border-radius: 7px;
            color: #3730a3;
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
        <h1>Receive Files</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item"><a href="{{ route('workfile.index') }}">Work Files</a></li>
                <li class="breadcrumb-item active">Receive</li>
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
            <form id="receive_form" action="{{ route('workfile.receive') }}" method="POST">
                @csrf

                <div class="card">
                    <div class="card-body">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
                            <h5 class="card-title mb-0">Who and When</h5>
                            <a href="{{ route('workfile.index') }}" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-list-ul me-1"></i> All Files
                            </a>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="customer_id" class="form-label">Customer <span class="required-mark">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-people"></i></span>
                                    <select class="form-select" id="customer_id" name="customer_id" required autofocus>
                                        <option value="">Select customer</option>
                                        @foreach ($customers as $c)
                                            <option value="{{ $c->id }}" data-balance="{{ $c->current_balance }}" {{ old('customer_id') == $c->id ? 'selected' : '' }}>{{ $c->name }} ({{ $c->mobile }})</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="balance-chip" id="balance_hint">Current balance: 0.00</div>
                            </div>

                            <div class="col-md-3">
                                <label for="received_date_display" class="form-label">Received Date <span class="required-mark">*</span></label>
                                @include('partials._datefield', [
                                    'name' => 'received_date',
                                    'value' => old('received_date', date('Y-m-d')),
                                    'required' => true,
                                ])
                            </div>

                            <div class="col-md-3">
                                <label for="remarks" class="form-label">Remarks</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-chat-left-text"></i></span>
                                    <input type="text" class="form-control" id="remarks" name="remarks" value="{{ old('remarks') }}" maxlength="255" placeholder="Applies to every file below">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body">
                        {{--
                            Rendered by Vue. The inputs keep the very names the
                            controller already validates, so the form still posts
                            normally and the server still checks every field —
                            which is what makes converting one screen at a time
                            safe on a live application.
                        --}}
                        <div data-vue="vue-receive-rows" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>

                        <div class="d-flex flex-wrap gap-2 justify-content-end mt-3">
                            <a href="{{ route('workfile.index') }}" class="btn btn-outline-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check2-circle me-1"></i> Receive Files
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
            const customer = document.getElementById('customer_id');
            const balanceHint = document.getElementById('balance_hint');

            if (!customer || !balanceHint) {
                return;
            }

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

            const current = function () {
                const selected = customer.options[customer.selectedIndex];
                return selected && selected.dataset.balance ? Number(selected.dataset.balance) : 0;
            };

            let total = 0;

            const paint = function () {
                balanceHint.textContent = 'Balance after: '
                    + (customer.value ? balance(current() + total) : money(total));
            };

            // The rows are a Vue component now; it announces its running total
            // rather than this script reaching into its markup.
            document.addEventListener('receive-total', function (event) {
                total = event.detail;
                paint();
            });

            customer.addEventListener('change', paint);

            paint();
        });
    </script>
@endsection
