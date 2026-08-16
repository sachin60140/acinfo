@extends('admin.layouts.app')

@section('title', 'Receipt | Ac Info')

@section('content')

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
        <div data-vue="vue-payment-receipt" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>
    </section>
@endsection

{{-- The running summary moved into the component. The transaction date still
     parses and validates itself and keeps its own hidden Y-m-d value in step —
     see assets/js/datepicker.js. --}}
@section('script')
@endsection
