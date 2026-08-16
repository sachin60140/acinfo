@extends('admin.layouts.app')

@section('title', 'Client Ledger | Ac Info')

@section('content')

    <div class="pagetitle">
        <h1>Dashboard</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item">Clinets</li>

            </ol>
        </nav>
    </div><!-- End Page Title -->
    <section class="section dashboard">
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

        <div class="row">
            <div class="col-lg-12">

                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Client Ledger</h5>

                        <div class="ui" data-vue="vue-client-list" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>

                    </div>
                </div>

            </div>
        </div>

    </section>
@endsection
