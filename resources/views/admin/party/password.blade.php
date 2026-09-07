@extends('admin.layouts.app')

@section('title', 'Customer Login | Ac Info')

@section('content')
    <div class="pagetitle">
        <h1>Customer Login</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item"><a href="{{ route('party.index', 'customer') }}">Customers</a></li>
                <li class="breadcrumb-item active">Set Password</li>
            </ol>
        </nav>
    </div>

    <section class="section dashboard">
        <div class="row">
            <div class="col-md-6 mx-auto">
                @if ($errors->any())
                    <div class="alert alert-danger bg-danger text-light border-0 alert-dismissible fade show">
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Set Password</h5>

                        <p class="text-muted small">
                            This lets {{ $partyName }} sign in at
                            <strong>{{ url('/customer') }}</strong> with their mobile number
                            to see their files and statement. Tell them the password yourself —
                            it is not sent anywhere.
                        </p>

                        {{--
                            The same component the client login screen uses. Its
                            props are named for clients because that is what it
                            was written for; the screen is the same screen, and
                            copying it to rename two props would leave two of
                            them to keep in step.
                        --}}
                        <div data-vue="vue-client-password" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
