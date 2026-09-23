@extends('admin.layouts.app')

@section('title', $what.' | Ac Info')

@section('content')
    <div class="pagetitle">
        <h1>{{ $what }}</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item">Client Ledger</li>
                <li class="breadcrumb-item active">{{ $what }}</li>
            </ol>
        </nav>
    </div>

    <section class="section">
        @include('admin.party._alerts')

        {{-- The old Client Ledger was closed by the owner on 2026-09-23:
             money is recorded on the Customer/Vendor Ledger only. What stood
             here says where to go instead. --}}
        <div class="card">
            <div class="card-body pt-4">
                <h5 class="card-title p-0 mb-2">The old Client Ledger is closed</h5>
                <p>Nothing new is added to it. {{ $instead }}</p>

                <div class="d-flex flex-wrap gap-2">
                    @foreach ($links as $label => $href)
                        <a href="{{ $href }}" class="btn {{ $loop->first ? 'btn-primary' : 'btn-outline-primary' }}">{{ $label }}</a>
                    @endforeach

                    @if ($openCount)
                        <a href="{{ route('client.closebook') }}" class="btn btn-outline-secondary">
                            Close the old book: {{ $openCount }} {{ Str::plural('balance', $openCount) }} left to carry over
                        </a>
                    @endif

                    <a href="{{ route('viewclient') }}" class="btn btn-outline-secondary">Old clients and their statements</a>
                </div>
            </div>
        </div>
    </section>
@endsection
