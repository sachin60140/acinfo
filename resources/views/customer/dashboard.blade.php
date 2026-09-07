@extends('customer.layouts.app')

@section('title', 'My Account | Ac Info')

@section('style')
    @include('admin.layouts._statement-style')
@endsection

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
            <div class="col-lg-6">
                <div class="card info-card">
                    <div class="card-body pt-4">
                        <h5 class="card-title p-0 mb-3">{{ $customerName }}</h5>

                        {{--
                            The figure without a minus sign, and words for which
                            way it falls. Dr and Cr are the office's shorthand;
                            the person reading this has no reason to know it.
                        --}}
                        <div class="statement-summary">
                            <div class="stat closing">
                                <span class="label">{{ $balanceLabel }}</span>
                                <span class="value {{ $balanceTone === 'settled' ? '' : $balanceTone }}">{{ $balanceText }}</span>
                            </div>
                        </div>

                        <p class="text-muted small mt-3 mb-3">
                            As on {{ now()->format('d-m-Y') }}
                        </p>

                        <a href="{{ route('customer.statement') }}" class="btn btn-primary">
                            <i class="bi bi-journal-text"></i> View Statement
                        </a>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card">
                    <div class="card-body pt-4">
                        <h5 class="card-title p-0 mb-2">Your Files</h5>
                        {{--
                            Said plainly rather than shown as an empty list: a
                            screen of zeroes reads as "you have no files", which
                            would not be true.
                        --}}
                        <p class="text-muted mb-0">
                            The status of each of your files will appear here shortly.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

@endsection
