@extends('admin.layouts.app')

@section('title', 'Payment | Ac Info')

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
        <div data-vue="vue-payment-form" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>
    </section>
@endsection

{{-- The summary that was worked out here now lives in the component. The
     transaction-date box still parses and validates itself and keeps its own
     hidden Y-m-d value in step — see assets/js/datepicker.js. --}}
@section('script')
@endsection
