@extends('user.layouts.app')

@section('title', 'Dashboard | Ac Info')


@section('style')
@endsection

@section('content')
    <div class="pagetitle">
        <h1>Dashboard</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('userdashboard') }}">Home</a></li>
                <li class="breadcrumb-item active">Dashboard</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
        <div class="row">

            <!-- Left side columns -->
            <div class="col-lg-12">
                <div class="row">

                    <!-- Sales Card -->
                    <div class="col-xxl-4 col-md-4">
                        <div class="card info-card sales-card">

                            <div class="card-body">
                                <h5 class="card-title">Total Available Balance <span>| Today</span></h5>

                                <div class="d-flex align-items-center">
                                    <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                                        <i class="bi bi-currency-rupee"></i>
                                    </div>
                                    <div class="ps-3">
                                        <h6>{{ $totalamount }}</h6>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div><!-- End Sales Card -->
                </div>
            </div>
        </div>
    </section>
@endsection

@section('script')

@endsection
