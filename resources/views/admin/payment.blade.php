@extends('admin.layouts.app')

@section('title', 'Payment | Ac Info')

@section('content')
    @php
        /*
         * Built above the markup rather than inline in the directive: Blade's
         * parser splits a directive's arguments on commas and cannot read a
         * multi-line array with function calls in it.
         */
        $props = [
            'action' => route('payment'),
            'csrf' => csrf_token(),
            'clientsUrl' => route('viewclient'),
            'clients' => $clientlist
                ->map(fn ($client) => [
                    'id' => $client->id,
                    'name' => $client->name,
                    // The sum of the client's ledger, raw. client_ledger stores a
                    // receipt positive — the opposite of the party tables — and
                    // the component negates it before printing a side, the same
                    // way the client statement does.
                    'current_balance' => (float) $client->current_balance,
                ])
                ->values(),
            'paymentModes' => $pay_mode
                ->map(fn ($mode) => [
                    'id' => $mode->id,
                    'payment_mode' => $mode->payment_mode,
                ])
                ->values(),
            // The date field stays the shared partial rather than being rebuilt
            // in Vue: assets/js/datepicker.js owns that markup, and dd-mm-yyyy
            // everywhere is the whole reason it exists.
            'dateField' => view('partials._datefield', [
                'name' => 'txn_date',
                'value' => old('txn_date', date('Y-m-d')),
                'required' => true,
            ])->render(),
            // What Reset puts back, which is what the page loaded with —
            // including a rejected submission's own values.
            'initial' => [
                'client_name' => (string) old('client_name'),
                'paymentMode' => (string) old('paymentMode'),
                'amount' => (string) old('amount'),
                'remarks' => (string) old('remarks'),
            ],
            // The list above stays as it is; this puts the same message against
            // the field it came from. Cast so an empty bag still arrives as an
            // object rather than as an array.
            'errors' => (object) array_map(fn ($messages) => $messages[0], $errors->messages()),
        ];
    @endphp

    <div class="pagetitle">
        <h1>Payment Entry</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item active">Payment</li>
            </ol>
        </nav>
    </div>

    <section class="section dashboard">
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

        {{--
            Rendered by Vue. The field names are the ones
            AuthController::ledgerEntry() already validates and the sign on the
            amount is still the server's to apply, so the form posts normally and
            the server checks every value — which is what makes converting a
            screen on a live ledger safe: only the rendering moves.
        --}}
        <div data-vue="vue-payment-form" data-props="{{ \App\Support\VueProps::encode($props) }}"></div>
    </section>
@endsection

{{-- The summary that was worked out here now lives in the component. The
     transaction-date box still parses and validates itself and keeps its own
     hidden Y-m-d value in step — see assets/js/datepicker.js. --}}
@section('script')
@endsection
