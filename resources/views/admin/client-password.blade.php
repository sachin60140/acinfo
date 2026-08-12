@extends('admin.layouts.app')

@section('title', 'Set Client Password | Ac Info')

@section('content')
    <div class="pagetitle">
        <h1>Dashboard</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item"><a href="{{ route('viewclient') }}">Clients</a></li>
                <li class="breadcrumb-item active">Set Password</li>
            </ol>
        </nav>
    </div>
    <section class="section dashboard">
        <div class="row">
            <div class="col-md-6 mx-auto">
                @if ($errors->any())
                    <div class="alert alert-danger bg-danger text-light border-0 alert-dismissible fade show">
                        <ul>
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Set Password</h5>

                        <div class="mb-3">
                            <strong>{{ $client->name }}</strong><br>
                            <span>{{ $client->mobile }}</span>
                        </div>

                        <form class="row g-3" action="{{ route('clientpassword', $client->id) }}" method="POST">
                            @csrf
                            <div class="col-12">
                                <label for="password" class="form-label">Password <span style="color: red;">*</span></label>
                                <input type="password" class="form-control" id="password" name="password" minlength="8" required>
                            </div>
                            <div class="col-12">
                                <label for="password_confirmation" class="form-label">Confirm Password <span style="color: red;">*</span></label>
                                <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" minlength="8" required>
                            </div>
                            <div class="text-center">
                                <button type="submit" class="btn btn-primary">Update Password</button>
                                <a href="{{ route('viewclient') }}" class="btn btn-secondary">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
