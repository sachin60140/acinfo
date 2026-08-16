@extends('admin.layouts.app')

@section('title', $label . ' Entry | Ac Info')

@section('style')
    @include('admin.party._style')
@endsection

@section('content')
    <div class="pagetitle">
        <h1>{{ $label }} Entry</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item"><a href="{{ route('party.index', $type) }}">{{ $label }}s</a></li>
                <li class="breadcrumb-item active">Entry</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        @if (! $partyCount)
            <div class="card">
                <div class="card-body pt-4">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                        <h5 class="card-title mb-0">New Ledger Entry</h5>
                        <a href="{{ route('party.index', $type) }}" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-list-ul me-1"></i> All {{ $label }}s
                        </a>
                    </div>

                    <div class="alert alert-warning mb-0">
                        No active {{ strtolower($label) }}s yet.
                        <a href="{{ route('party.create', $type) }}" class="alert-link">Add one first</a>.
                    </div>
                </div>
            </div>
        @else
            {{--
                Rendered by Vue. The field names are the ones
                PartyController::entry() already validates, so the form still
                posts normally and the server still checks every value — which is
                what makes converting a screen on a live ledger safe: only the
                rendering moves.
            --}}
            <div data-vue="vue-party-entry" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>
        @endif
    </section>
@endsection

@section('script')
@endsection
