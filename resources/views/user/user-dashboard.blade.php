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
        {{--
            Rendered by Vue. The figure is the one UserController::userdashboard()
            already computed, so only the rendering moved — and it now goes
            through money.js, which groups it, gives it two decimals and never
            writes a balance with a minus sign. It was printed raw before, so an
            overdrawn client was shown something like "-2400.5".
        --}}
        <div data-vue="vue-user-dashboard" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>
    </section>
@endsection

@section('script')

@endsection
