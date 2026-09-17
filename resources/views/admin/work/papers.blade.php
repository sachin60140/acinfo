@extends('admin.layouts.app')

@section('title', 'Papers · '.$fileNo.' | Ac Info')

@section('style')
    @include('admin.party._style')
@endsection

@section('content')
    <div class="pagetitle">
        <h1>Papers for {{ $fileNo }}</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item"><a href="{{ route('workfile.paperaudit') }}">Paper Audit</a></li>
                <li class="breadcrumb-item active">{{ $fileNo }}</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        {{--
            Rendered by Vue. One line per paper the file's unfinished work
            needs; the server checks every line is answered before saving.
        --}}
        <div data-vue="vue-paper-checklist" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>
    </section>
@endsection

@section('script')
@endsection
