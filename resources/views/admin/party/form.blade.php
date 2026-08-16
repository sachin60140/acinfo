@extends('admin.layouts.app')

@section('title', ($isEdit ? 'Edit' : 'Add') . ' ' . $label . ' | Ac Info')

@section('style')
    @include('admin.party._style')
@endsection

@section('content')

    <div class="pagetitle">
        <h1>{{ $isEdit ? 'Edit' : 'Add' }} {{ $label }}</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item"><a href="{{ route('party.index', $type) }}">{{ $label }}s</a></li>
                <li class="breadcrumb-item active">{{ $isEdit ? 'Edit' : 'Add' }}</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        <div class="row">
            <div class="col-lg-9">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
                            <h5 class="card-title mb-0">{{ $label }} Details</h5>
                            <a href="{{ route('party.index', $type) }}" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-list-ul me-1"></i> All {{ $label }}s
                            </a>
                        </div>

                        {{--
                            Rendered by Vue. The field names are the ones
                            PartyController::create() and ::edit() already validate, so
                            the form still posts normally and the server still checks
                            every value — which is what makes converting a screen on a
                            live ledger safe: only the rendering moves.
                        --}}
                        <div data-vue="vue-party-form" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

{{-- The digit filtering and the "same as mobile" mirror moved into the
     component. The opening-date box still parses and validates itself and keeps
     its own hidden Y-m-d value in step — see assets/js/datepicker.js. --}}
@section('script')
@endsection
