@extends('admin.layouts.app')

@section('title', 'Hand Over Papers | Ac Info')

@section('style')
    @include('admin.party._style')
@endsection

@section('content')
    <div class="pagetitle">
        <h1>Hand Over Papers</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item"><a href="{{ route('workfile.approved') }}">Approved Files</a></li>
                <li class="breadcrumb-item active">Hand Over Papers</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        @if (! $fileCount)
            <div class="alert alert-info">
                @if ($anyApproved)
                    Every approved file's papers have already gone back to the customer.
                    <a href="{{ route('workfile.approved') }}" class="alert-link">See approved files</a>.
                @else
                    No file is fully approved yet, so there are no papers to hand over.
                    <a href="{{ route('workfile.status') }}" class="alert-link">Update a status</a>.
                @endif
            </div>
        @else
            {{--
                Rendered by Vue. The field names are the ones
                WorkFileController::handOver() validates, so the form still posts
                normally and the server re-reads every file before recording
                anything against it.
            --}}
            <div data-vue="vue-hand-over" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>
        @endif
    </section>
@endsection

@section('script')
@endsection
