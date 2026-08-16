@extends('admin.layouts.app')

@section('title', ($isEdit ? 'Edit File' : 'Receive File') . ' | Ac Info')

@section('style')
    @include('admin.party._style')
@endsection

@section('content')

    <div class="pagetitle">
        <h1>{{ $isEdit ? 'Edit File ' . $fileNo : 'Receive File' }}</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item"><a href="{{ route('workfile.index') }}">Work Files</a></li>
                <li class="breadcrumb-item active">{{ $isEdit ? 'Edit' : 'Receive' }}</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        @if ($blocked)
            <div class="alert alert-warning">
                Before a file can be received you need
                @if ($noWorkTypes)
                    at least one <a href="{{ route('worktype.index') }}" class="alert-link">work type</a>
                @endif
                @if ($noWorkTypes && $noCustomers)
                    and
                @endif
                @if ($noCustomers)
                    at least one <a href="{{ route('party.create', 'customer') }}" class="alert-link">customer</a>
                @endif
                .
            </div>
        @else
            {{--
                Rendered by Vue — the form, the ledger-effect panel beside it and
                the history below that. The field names are the ones
                WorkFileController::receive() and ::edit() already validate, so the
                form still posts normally and the server still checks every value —
                which is what makes converting a screen on a live ledger safe: only
                the rendering moves.
            --}}
            <div data-vue="vue-file-form" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>
        @endif
    </section>
@endsection

{{-- The ledger-effect panel, the status-driven boxes and the default-rate fill
     all moved into the component. Both date boxes still parse and validate
     themselves and keep their hidden Y-m-d values in step — see
     assets/js/datepicker.js. --}}
@section('script')
@endsection
