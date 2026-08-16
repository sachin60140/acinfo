@extends('admin.layouts.app')

@section('title', 'Papers Returned by Vendor | Ac Info')

@section('style')
    @include('admin.party._style')
@endsection

@section('content')
    <div class="pagetitle">
        <h1>Papers Returned by Vendor</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item"><a href="{{ route('workfile.index') }}">Work Files</a></li>
                <li class="breadcrumb-item active">Returned by Vendor</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        @if (! $fileCount)
            <div class="alert alert-info">
                @if ($anyFiles)
                    No files are out with a vendor at the moment.
                    <a href="{{ route('workfile.assign') }}" class="alert-link">Give work to a vendor</a> first.
                @else
                    No files have been received yet.
                    <a href="{{ route('workfile.receive') }}" class="alert-link">Receive the first one</a>, then give it to a vendor.
                @endif
            </div>
        @else
        
            {{--
                Rendered by Vue. The field names are the ones
                WorkFileController::vendorReturn() already validates, so the form
                still posts normally and the server still checks every value —
                which is what makes converting a screen on a live ledger safe:
                only the rendering moves.

                The date box keeps the markup contract in
                public/assets/js/datepicker.js, so the calendar still binds to it.
            --}}
            <div data-vue="vue-vendor-return" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>
        @endif
    </section>
@endsection

@section('script')
@endsection
