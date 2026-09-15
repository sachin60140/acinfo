@extends('admin.layouts.app')

@section('title', 'Expense Report | Ac Info')

@section('style')
    @include('admin.layouts._statement-style')
@endsection

@section('content')
    <div class="pagetitle">
        <h1>Expense Report</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item active">Reports</li>
            </ol>
        </nav>
    </div>

    <section class="section">
        <div class="card">
            <div class="card-body pt-4">
                <form method="GET" action="{{ $base }}" class="row g-2 align-items-end statement-filter">
                    <div class="col-sm-auto">
                        <label for="expense_type_id" class="form-label">Kind</label>
                        <select class="form-select" id="expense_type_id" name="expense_type_id">
                            <option value="">All kinds</option>
                            @foreach ($types as $type)
                                <option value="{{ $type->id }}" @selected($typeId === (int) $type->id)>
                                    {{ $type->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-sm-auto">
                        <label for="party_id" class="form-label">Customer</label>
                        <select class="form-select" id="party_id" name="party_id">
                            <option value="">All customers</option>
                            @foreach ($customers as $party)
                                <option value="{{ $party->id }}" @selected($partyId === (int) $party->id)>
                                    {{ $party->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-sm-auto">
                        <label for="from_display" class="form-label">From</label>
                        @include('partials._datefield', ['name' => 'from', 'value' => $from, 'max' => $maxDate])
                    </div>
                    <div class="col-sm-auto">
                        <label for="to_display" class="form-label">To</label>
                        @include('partials._datefield', ['name' => 'to', 'value' => $to, 'max' => $maxDate])
                    </div>
                    <div class="col-sm-auto">
                        <button type="submit" class="btn btn-primary">Apply</button>
                        <a href="{{ $base }}" class="btn btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body pt-4">
                <h5 class="card-title p-0 m-0">Expenses</h5>
                <div class="statement-period">
                    {{ $periodText }} &middot; {{ $count }} {{ Str::plural('expense', $count) }}
                    on {{ $fileCount }} {{ Str::plural('file', $fileCount) }}
                </div>

                {{--
                    What each kind came to. The question behind this report is
                    usually "which of these is taking the margin", and a list of
                    lines answers it more slowly than a few figures do.
                --}}
                <div class="statement-summary my-3">
                    <div class="stat closing">
                        <span class="label">Paid Out</span>
                        <span class="value cr">{{ number_format((float) $total, 2, '.', ',') }}</span>
                    </div>
                    @foreach ($byType->take(4) as $name => $one)
                        <div class="stat">
                            <span class="label">{{ $name }}</span>
                            <span class="value">{{ number_format($one['total'], 2, '.', ',') }}</span>
                            <span class="stat-note">{{ $one['count'] }} {{ Str::plural('time', $one['count']) }}</span>
                        </div>
                    @endforeach
                </div>

                <div data-vue="vue-expense-report" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>
            </div>
        </div>
    </section>
@endsection
