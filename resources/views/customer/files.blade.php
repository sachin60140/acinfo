@extends('customer.layouts.app')

@section('title', 'My Files | Ac Info')

@section('style')
    @include('admin.layouts._statement-style')
@endsection

@section('content')

    <div class="pagetitle">
        <h1>My Files</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('customer.dashboard') }}">Home</a></li>
                <li class="breadcrumb-item active">My Files</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section">
        <div class="row">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-body pt-4">

                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                            <div>
                                <h5 class="card-title p-0 m-0">{{ $customerName }}</h5>
                                <div class="statement-period">
                                    {{ $fileCount }} {{ Str::plural('file', $fileCount) }} in total
                                </div>
                            </div>
                        </div>

                        {{-- Where the files stand, before the list of them. The
                             counts are of folders, not works: a folder is the one
                             thing a customer handed over and gets back. --}}
                        <div class="statement-summary mb-3">
                            <div class="stat">
                                <span class="label">In Progress</span>
                                <span class="value">{{ $open }}</span>
                            </div>
                            <div class="stat">
                                <span class="label">Approved</span>
                                <span class="value dr">{{ $approved }}</span>
                            </div>
                            <div class="stat">
                                <span class="label">Returned to You</span>
                                <span class="value">{{ $returned }}</span>
                            </div>
                            <div class="stat">
                                <span class="label">Cancelled</span>
                                <span class="value">{{ $cancelled }}</span>
                            </div>
                        </div>

                        {{--
                            Rendered by Vue: the same grid the office lists use,
                            with the same search, sorting, paging and exports.
                            Nothing about a vendor is in these props — see
                            CustomerPortalController for what is left out and why.
                        --}}
                        <div data-vue="vue-customer-files" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>

                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
