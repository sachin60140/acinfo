@extends('admin.layouts.app')

@section('title', 'Receipt | Ac Info')

@section('content')
    @php
        /*
         * Props are built here rather than inline in the directive: Blade's
         * attribute parser splits on commas and cannot read a multi-line array
         * that contains function calls.
         */
        $props = [
            'action' => route('receipt'),
            'csrf' => csrf_token(),
            'clientsUrl' => route('viewclient'),
            /*
             * The balance is the one the controller already summed. It is sent as
             * a plain number so the component can work out where the receipt
             * lands, rather than being read back out of a data- attribute the way
             * the old inline script did.
             */
            'clients' => $clientlist->map(fn ($client) => [
                'id' => $client->id,
                'name' => $client->name,
                'current_balance' => (float) $client->current_balance,
            ])->values(),
            'paymentModes' => $pay_mode->map(fn ($mode) => [
                'id' => $mode->id,
                'name' => $mode->payment_mode,
            ])->values(),
            /*
             * The date box stays the shared partial rather than being rebuilt in
             * Vue: assets/js/datepicker.js owns that markup, and dd-mm-yyyy for
             * everyone is the whole reason it exists. It is the same field the
             * page hand-rolled before, down to the ids.
             */
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
        ];
    @endphp

    <div class="pagetitle">
        <h1>Receipt Entry</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item active">Receipt</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

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
            AuthController::paymentreceipt() already validates, so the form still
            posts normally and the server still checks every value — which is
            what makes converting a screen on a live ledger safe: only the
            rendering moves.
        --}}
        <div data-vue="vue-payment-receipt" data-props="{{ \App\Support\VueProps::encode($props) }}"></div>
    </section>
@endsection

{{-- The running summary moved into the component. The transaction date still
     parses and validates itself and keeps its own hidden Y-m-d value in step —
     see assets/js/datepicker.js. --}}
@section('script')
@endsection
