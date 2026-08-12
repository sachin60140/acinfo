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
    </section>
@endsection

@section('script')

@endsection