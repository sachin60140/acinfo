@extends('admin.layouts.app')

@section('title','Dashboard | Ac Info')


@section('style')
@endsection

@section('content')
    <div class="pagetitle">
        <h1>Dashboard</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{url('admin/dashboard')}}">Home</a></li>
                <li class="breadcrumb-item active">Dashboard</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->


    <section class="section ui">
        <div data-vue="vue-dashboard" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>
    </section>
@endsection

@section('script')

@endsection