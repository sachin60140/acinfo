@extends('admin.layouts.app')

@section('title', 'Change Password | Ac Info')

@section('content')

    <div class="pagetitle">
        <h1>Change Password</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item active">Change Password</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section">
        <div class="row">
            <div class="col-md-6 mx-auto">
                @if ($errors->any())
                    <div class="alert alert-danger">
                        <ul class="mb-0 ps-3">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="card">
                    <div class="card-body pt-4">
                        {{-- The same component as the client and customer password
                             screens, asking for the current one first. --}}
                        <div data-vue="vue-admin-password" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
