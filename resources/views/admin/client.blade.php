@extends('admin.layouts.app')

@section('title', 'Add Client | Ac Info')

@section('content')
    <div class="pagetitle">
        <h1>Add Client</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item"><a href="{{ route('viewclient') }}">Clients</a></li>
                <li class="breadcrumb-item active">Add Client</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
        <div>
            @if ($errors->any())
                <div class="alert alert-danger bg-danger text-light border-0 alert-dismissible fade show">
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (Session::has('success'))
                <div class="alert alert-primary bg-primary text-light border-0 alert-dismissible fade show" role="alert">
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
        </div>

        <div class="row">
            <div class="col-lg-9">
                {{--
                    Rendered by Vue, card and all. The field names are the ones
                    AuthController::client() already validates, so the form still
                    posts normally and the server still checks every value — which
                    is what makes converting a screen on a live ledger safe: only
                    the rendering moves.

                    Neither password is sent back out. A rejected submission
                    returns the name, mobile and address the user typed; the two
                    password boxes start empty every time, which is also what the
                    component's Reset puts back.
                --}}
                <div data-vue="vue-client-form" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>
            </div>
        </div>
    </section>
@endsection

{{-- The digit filtering and the spinner-less number box moved into the
     component. --}}
@section('script')
@endsection
