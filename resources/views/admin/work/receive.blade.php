@extends('admin.layouts.app')

@section('title', 'Receive Files | Ac Info')

@section('style')
    @include('admin.party._style')

    <style>
        /*
         * The batch's own header. Everything below it is drawn by the component,
         * which uses the application's own primitives — these are the few things
         * this page adds on top.
         */
        .rcv-head {
            display: grid;
            gap: var(--s-4);
            grid-template-columns: 2fr 1fr 1fr;
        }

        /* Where the customer's balance lands once this batch is booked. Under
           the select, because it means nothing until one is chosen. */
        .rcv-balance {
            align-items: baseline;
            background: var(--n-050);
            border: 1px solid var(--n-200);
            border-radius: var(--r-sm);
            display: inline-flex;
            font-size: var(--t-sm);
            font-weight: 700;
            gap: var(--s-2);
            margin-top: var(--s-1);
            padding: var(--s-2) var(--s-3);
        }

        .rcv-balance__label {
            color: var(--n-500);
            font-size: var(--t-xs);
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        @media (max-width: 991.98px) {
            .rcv-head {
                grid-template-columns: 1fr;
            }
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

                <div class="ui">
                    <div class="ui-card">
                        <div class="ui-card__head">
                            <div>
                                <h2 class="ui-card__title">Who and When</h2>
                                <div class="ui-hint">These apply to every file in this batch.</div>
                            </div>
                            <a href="{{ route('workfile.index') }}" class="ui-btn ui-btn--sm">
                                <i class="bi bi-list-ul"></i> All Files
                            </a>
                        </div>

                        <div class="ui-card__body">
                            <div class="rcv-head">
                                <div class="ui-field">
                                    <label class="ui-label" for="customer_id">
                                        Customer <span class="ui-label__req">*</span>
                                    </label>
                                    <select class="ui-select" id="customer_id" name="customer_id" required autofocus>
                                        <option value="">Select customer</option>
                                        @foreach ($customers as $c)
                                            <option value="{{ $c->id }}" data-balance="{{ $c->current_balance }}" {{ old('customer_id') == $c->id ? 'selected' : '' }}>{{ $c->name }} ({{ $c->mobile }})</option>
                                        @endforeach
                                    </select>

                                    <div class="rcv-balance">
                                        <span class="rcv-balance__label">Balance after</span>
                                        <span class="ui-money" id="balance_hint">0.00</span>
                                    </div>
                                </div>

                                <div class="ui-field">
                                    <label class="ui-label" for="received_date_display">
                                        Received Date <span class="ui-label__req">*</span>
                                    </label>
                                    {{--
                                        The markup assets/js/datepicker.js binds by class,
                                        written out here rather than through the shared
                                        partial so the box carries this screen's own input
                                        styling. The contract it binds to — js-datefield,
                                        data-target, and the hidden Y-m-d beside it — is
                                        unchanged.
                                    --}}
                                    @php
                                        $rcvDate = old('received_date', date('Y-m-d'));
                                        $rcvShown = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $rcvDate)
                                            ? date('d-m-Y', strtotime($rcvDate))
                                            : '';
                                    @endphp
                                    <input type="text"
                                           class="ui-input js-datefield"
                                           id="received_date_display"
                                           data-target="received_date"
                                           value="{{ $rcvShown }}"
                                           placeholder="dd-mm-yyyy"
                                           inputmode="numeric"
                                           maxlength="10"
                                           autocomplete="off"
                                           required>
                                    <input type="hidden" id="received_date" name="received_date" value="{{ $rcvDate }}">
                                </div>

                                <div class="ui-field">
                                    <label class="ui-label" for="remarks">Remarks</label>
                                    <input type="text"
                                           class="ui-input"
                                           id="remarks"
                                           name="remarks"
                                           value="{{ old('remarks') }}"
                                           maxlength="255"
                                           placeholder="Applies to every file below">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{--
                    Rendered by Vue, card and footer included. The inputs keep the
                    very names the controller already validates, so the form still
                    posts normally and the server still checks every field — which
                    is what makes converting one screen at a time safe on a live
                    application.
                --}}
                <div data-vue="vue-receive-rows" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>
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
                // The label beside it is markup now, so only the figure is
                // written here.
                balanceHint.textContent = customer.value ? balance(current() + total) : money(total);
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
