@extends('admin.layouts.app')

@section('title', 'Limits | Ac Info')

@section('content')
    <div class="pagetitle">
        <h1>Limits</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item active">Limits</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section">
        @include('admin.party._alerts')

        <div class="row">
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-body pt-4">
                        <h5 class="card-title p-0 mb-2">Write-off limit</h5>
                        <p class="text-muted">
                            The most that may be given up on a bill at one time, and the most that may be given up on
                            any one bill in total. A customer who owes 5,000 and pays 4,950 leaves 50 behind; this is
                            how large a leftover the office may close off. Set it to nought to stop write-offs
                            altogether.
                        </p>

                        <form method="POST" action="{{ route('setting.index') }}" class="row g-3 align-items-end">
                            @csrf
                            <div class="col-sm-6">
                                <label for="writeoff_cap" class="form-label">Most that may be written off</label>
                                <div class="input-group">
                                    <span class="input-group-text">INR</span>
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        id="writeoff_cap"
                                        name="writeoff_cap"
                                        class="form-control @error('writeoff_cap') is-invalid @enderror"
                                        value="{{ old('writeoff_cap', number_format($cap, 2, '.', '')) }}"
                                        required>
                                </div>
                                @error('writeoff_cap')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-sm-auto">
                                <button type="submit" class="btn btn-primary">Save limit</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card">
                    <div class="card-body pt-4">
                        <h5 class="card-title p-0 mb-2">What it has been</h5>
                        {{-- Every figure it has ever held: a limit on money given
                             away is the kind of thing asked about months later. --}}
                        @if ($history->isEmpty())
                            <p class="text-muted mb-0">Nothing set yet.</p>
                        @else
                            <ul class="list-unstyled mb-0">
                                @foreach ($history as $was)
                                    <li class="mb-2">
                                        <strong>{{ number_format((float) $was->value, 2, '.', ',') }}</strong>
                                        <span class="text-muted">
                                            &middot; {{ date('d-m-Y', strtotime($was->created_at)) }}
                                            @if ($was->by)
                                                &middot; {{ $was->by }}
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
