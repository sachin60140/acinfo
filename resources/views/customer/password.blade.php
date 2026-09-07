@extends('customer.layouts.app')

@section('title', 'Change Password | Ac Info')

@section('content')

    <div class="pagetitle">
        <h1>Change Password</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('customer.dashboard') }}">Home</a></li>
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
                        {{--
                            The same component the office uses to set a password,
                            asking for the current one first. Copying it to change
                            two lines would leave two forms to keep in step, and
                            this one already knows about Caps Lock, revealing both
                            boxes together, and the difference between a
                            confirmation that is wrong and one still being typed.
                        --}}
                        <div data-vue="vue-customer-password" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
