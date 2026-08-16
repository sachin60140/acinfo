@extends('admin.layouts.app')

@section('title', 'Set Client Password | Ac Info')

@section('content')
    <div class="pagetitle">
        <h1>Set Client Password</h1>
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

                        {{--
                            Rendered by Vue. Which client this is used to be printed
                            above the form and is now the component's first block —
                            the screen is reached from a list, and there is nothing
                            else on it to say which row was clicked.

                            The field names are the ones
                            AuthController::clientpassword() already validates, so
                            the form still posts normally and the server still
                            checks both values.
                        --}}
                        <div data-vue="vue-client-password" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
