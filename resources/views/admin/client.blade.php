@extends('admin.layouts.app')

@section('title', 'View Clients | Awani Enterprises')


@section('style')

    <style>
        /* Chrome, Safari, Edge, Opera */
        input::-webkit-outer-spin-button,
        input::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
    </style>

@endsection

@section('content')
    <div class="pagetitle">
        <h1>Dashboard</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item active">Admin</li>
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
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Add Client Ledger</h5>

                <!-- Multi Columns Form -->
                <form class="row g-3" action="{{route('addclients')}}" method="POST">
                    @csrf
                    <div class="col-md-6">
                        <label for="fee" class="form-label">Name <span style="color: red;">*</span></label>
                        <input type="text" class="form-control" id="cab_fee" value="{{ old('name') }}"
                            name="name">
                    </div>

                    <div class="col-md-6">
                        <label for="mobile_number" class="form-label">Mobile Number <span
                                style="color: red;">*</span></label>
                        <input type="number" class="form-control" id="inputName5" value="{{ old('mobile_number') }}"
                            name="mobile_number" maxlength="10" required>
                    </div>
                    <div class="col-12">
                        <label for="inputAddress2" class="form-label">Address</label>
                        <input type="text" class="form-control" id="address" name="address"
                            placeholder="Apartment, studio, or floor" value="{{ old('address') }}" required>
                    </div>
                    <div class="text-center">
                        <button type="submit" class="btn btn-primary">Submit</button>
                        <button type="reset" class="btn btn-secondary">Reset</button>
                    </div>
                </form>

            </div>
        </div>



        </div>
    @endsection

    @section('script')
    @endsection
