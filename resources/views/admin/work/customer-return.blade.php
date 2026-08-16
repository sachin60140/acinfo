@extends('admin.layouts.app')

@section('title', 'Return Files to Customer | Ac Info')

@section('style')
    @include('admin.party._style')
@endsection

@section('content')
    <div class="pagetitle">
        <h1>Return Files to Customer</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item"><a href="{{ route('workfile.index') }}">Work Files</a></li>
                <li class="breadcrumb-item active">Return to Customer</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        @if (! $fileCount)
            <div class="alert alert-info">
                @if ($anyFiles)
                    No files are available to return. Everything received has already been returned or cancelled.
                    <a href="{{ route('workfile.receive') }}" class="alert-link">Receive files</a>.
                @else
                    No files have been received yet, so there is nothing to give back.
                    <a href="{{ route('workfile.receive') }}" class="alert-link">Receive the first one</a>.
                @endif
            </div>
        @else
            {{--
                Rendered by Vue. The field names are the ones
                WorkFileController::customerReturn() already validates, so the
                form still posts normally and the server still checks every
                value — which is what makes converting a screen on a live ledger
                safe: only the rendering moves.

                Old input is cast so that a bounced batch always arrives as a
                JSON array and a JSON object, whatever keys it happens to carry.
            --}}
            <div data-vue="vue-customer-return" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>
        @endif
    </section>
@endsection

@section('script')
@endsection
