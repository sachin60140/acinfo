@extends('customer.layouts.app')

@section('title', 'My Account | Ac Info')

@section('content')

    <div class="pagetitle">
        <h1>My Account</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('customer.dashboard') }}">Home</a></li>
                <li class="breadcrumb-item active">My Account</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
        <div class="row">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-body pt-4">
                        <h5 class="card-title">{{ $customerName }}</h5>

                        <p class="mb-1 text-muted">Signed in with {{ $customerMobile }}.</p>

                        {{--
                            Phase 1 ships the way in and nothing behind it. Said
                            plainly rather than shown as an empty dashboard,
                            because a screen of zeroes reads as "you have no
                            files", which would not be true.
                        --}}
                        <p class="mb-0">
                            Your files and your account statement will appear here shortly.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
