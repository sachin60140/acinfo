@extends('admin.layouts.app')

@section('title', 'Adjust Entry #' . $entryId . ' | Ac Info')

@section('style')
    @include('admin.party._style')
@endsection

@section('content')
    <div class="pagetitle">
        <h1>Adjust Entry #{{ $entryId }}</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item"><a href="{{ route('party.index', $type) }}">{{ $label }}s</a></li>
                <li class="breadcrumb-item"><a href="{{ $statementUrl }}">{{ $partyName }}</a></li>
                <li class="breadcrumb-item active">Adjust</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        {{--
            Rendered by Vue. Posts alloc[file][work_file_id] and
            alloc[file][amount], the names PartyController::entry() reads for a
            new payment, and adjust() checks them the same way.
        --}}
        <div data-vue="vue-party-adjust" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>
    </section>
@endsection
