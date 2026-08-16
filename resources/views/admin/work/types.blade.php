@extends('admin.layouts.app')

@section('title', 'Work Types | Ac Info')

@section('content')

    <div class="pagetitle">
        <h1>Work Types</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item active">Work Types</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
        @include('admin.party._alerts')

        {{--
            Rendered by Vue. The form posts name, default_rate and is_active —
            the field names WorkTypeController::index() already validates — so
            the submission and its checks are unchanged and only the rendering
            moved. The list's sorting and search, which were DataTables', are in
            the component.
        --}}
        <div data-vue="vue-work-types" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>
    </section>
@endsection
