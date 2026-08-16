@extends('admin.layouts.app')

@section('title', 'Give Work to Vendor | Ac Info')

@section('style')
    @include('admin.party._style')
@endsection

@section('content')

    <div class="pagetitle">
        <h1>Give Work to Vendor</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item"><a href="{{ route('workfile.index') }}">Work Files</a></li>
                <li class="breadcrumb-item active">Give to Vendor</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        @if (! $vendorCount)
            <div class="alert alert-warning">
                Add a <a href="{{ route('party.create', 'vendor') }}" class="alert-link">vendor</a> before giving work out.
            </div>
        @elseif (! $fileCount)
            <div class="alert alert-info">
                @if ($anyFiles)
                    No files are waiting to be given out. Everything received is either already with a vendor or cancelled.
                    <a href="{{ route('workfile.receive') }}" class="alert-link">Receive more files</a>.
                @else
                    No files have been received yet.
                    <a href="{{ route('workfile.receive') }}" class="alert-link">Receive the first one</a> and it can be given to a vendor from here.
                @endif
            </div>
        @else
            {{--
                Rendered by Vue. The field names are the ones
                WorkFileController::assign() already validates, so the form still
                posts normally and the server still checks every value — which is
                what makes converting a screen on a live ledger safe: only the
                rendering moves.
            --}}
            <div data-vue="vue-give-to-vendor" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>
        @endif
    </section>
@endsection

@section('script')
@endsection
