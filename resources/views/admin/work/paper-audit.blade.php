@extends('admin.layouts.app')

@section('title', 'Paper Audit | Ac Info')

@section('style')
    @include('admin.party._style')
@endsection

@section('content')
    <div class="pagetitle">
        <h1>Paper Audit</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item"><a href="{{ route('workfile.index') }}">Work Files</a></li>
                <li class="breadcrumb-item active">Paper Audit</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        {{--
            Rendered by Vue. Two lists: files whose papers are still to be
            checked, and every paper still pending, which the counter marks
            received when the customer brings it. The server re-reads every
            line before changing it.
        --}}
        <div data-vue="vue-paper-audit" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>
    </section>
@endsection

@section('script')
@endsection
