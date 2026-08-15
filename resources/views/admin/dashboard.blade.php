@extends('admin.layouts.app')

@section('title','Dashboard | Ac Info')


@section('style')
@endsection

@section('content')
    <div class="pagetitle">
        <h1>Dashboard</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{url('admin/dashboard')}}">Home</a></li>
                <li class="breadcrumb-item active">Dashboard</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
        <div class="row">

            <!-- Left side columns -->
            <div class="col-lg-12">
                <div class="row">

                    <!-- Net outstanding across every client -->
                    <div class="col-xxl-4 col-md-4">
                        <div class="card info-card sales-card">

                            <div class="card-body">
                                <h5 class="card-title">Net Outstanding <span>| All Clients</span></h5>

                                <div class="d-flex align-items-center">
                                    <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                                        <i class="bi bi-currency-rupee"></i>
                                    </div>
                                    <div class="ps-3">
                                        <h6>{{ number_format((float) $totaldues, 2, '.', ',') }}</h6>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div><!-- End Net Outstanding Card -->

                    <!-- Net movement in the current month -->
                    <div class="col-xxl-4 col-md-4">
                        <div class="card info-card revenue-card">

                            <div class="card-body">
                                <h5 class="card-title">Net Movement <span>| {{ now()->format('F Y') }}</span></h5>

                                <div class="d-flex align-items-center">
                                    <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                                        <i class="bi bi-currency-rupee"></i>
                                    </div>
                                    <div class="ps-3">
                                        <h6>{{ number_format((float) $monthnet, 2, '.', ',') }}</h6>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div><!-- End Net Movement Card -->

                    <!-- Client count -->
                    <div class="col-xxl-4 col-md-4">

                        <div class="card info-card customers-card">

                            <div class="card-body">
                                <h5 class="card-title">Clients <span>| Total</span></h5>

                                <div class="d-flex align-items-center">
                                    <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                                        <i class="bi bi-people"></i>
                                    </div>
                                    <div class="ps-3">
                                        <h6>{{ $clientcount }}</h6>
                                    </div>
                                </div>

                            </div>
                        </div>

                    </div><!-- End Clients Card -->

                </div>

                <h5 class="mt-2 mb-3 text-muted" style="font-size: 0.95rem; font-weight: 700;">Vendor &amp; Customer Ledgers</h5>

                <div class="row">

                    <!-- What customers owe you -->
                    <div class="col-xxl-3 col-md-6">
                        <div class="card info-card sales-card">
                            <div class="card-body">
                                <h5 class="card-title">Receivable <span>| {{ $outstanding['customers'] }} {{ Str::plural('customer', $outstanding['customers']) }}</span></h5>

                                <div class="d-flex align-items-center">
                                    <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                                        <i class="bi bi-arrow-down-left-circle"></i>
                                    </div>
                                    <div class="ps-3">
                                        <h6><a href="{{ route('party.index', 'customer') }}" class="text-decoration-none text-reset">{{ number_format($outstanding['receivable'], 2, '.', ',') }}</a></h6>
                                        <span class="text-muted small pt-2">Dr &mdash; to collect</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div><!-- End Receivable Card -->

                    <!-- What you owe vendors -->
                    <div class="col-xxl-3 col-md-6">
                        <div class="card info-card revenue-card">
                            <div class="card-body">
                                <h5 class="card-title">Payable <span>| {{ $outstanding['vendors'] }} {{ Str::plural('vendor', $outstanding['vendors']) }}</span></h5>

                                <div class="d-flex align-items-center">
                                    <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                                        <i class="bi bi-arrow-up-right-circle"></i>
                                    </div>
                                    <div class="ps-3">
                                        <h6><a href="{{ route('party.index', 'vendor') }}" class="text-decoration-none text-reset">{{ number_format($outstanding['payable'], 2, '.', ',') }}</a></h6>
                                        <span class="text-muted small pt-2">Cr &mdash; to pay</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div><!-- End Payable Card -->

                    <!-- Files still being worked on -->
                    <div class="col-xxl-3 col-md-6">
                        <div class="card info-card customers-card">
                            <div class="card-body">
                                <h5 class="card-title">Open Files <span>| Pending &amp; in progress</span></h5>

                                <div class="d-flex align-items-center">
                                    <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                                        <i class="bi bi-folder2-open"></i>
                                    </div>
                                    <div class="ps-3">
                                        <h6><a href="{{ route('workfile.index') }}" class="text-decoration-none text-reset">{{ $work['open'] }}</a></h6>
                                        <span class="text-muted small pt-2">still to finish</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div><!-- End Open Files Card -->

                    <!-- Margin earned on files received this month -->
                    <div class="col-xxl-3 col-md-6">
                        <div class="card info-card sales-card">
                            <div class="card-body">
                                <h5 class="card-title">File Margin <span>| {{ now()->format('F Y') }}</span></h5>

                                <div class="d-flex align-items-center">
                                    <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                                        <i class="bi bi-graph-up-arrow"></i>
                                    </div>
                                    <div class="ps-3">
                                        <h6>{{ number_format($work['month_margin'], 2, '.', ',') }}</h6>
                                        <span class="text-muted small pt-2">on {{ number_format($work['month_billed'], 2, '.', ',') }} billed</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div><!-- End File Margin Card -->

                </div>
            </div>

        </div>
    </section>
@endsection

@section('script')

@endsection