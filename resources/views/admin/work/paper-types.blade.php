@extends('admin.layouts.app')

@section('title', 'Paper Types | Ac Info')

@section('style')
    <style>
        /* One line per work type: its name, and how it needs this paper. */
        .paper-needs {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
        }

        .paper-needs__row {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: 0.25rem 0.75rem;
            justify-content: space-between;
        }

        .paper-needs__row label {
            font-weight: 600;
            margin: 0;
        }

        .paper-needs__row .form-select {
            flex: 0 1 11rem;
            min-width: 0;
        }
    </style>
@endsection

@section('content')
    <div class="pagetitle">
        <h1>Paper Types</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item active">Paper Types</li>
            </ol>
        </nav>
    </div>

    <section class="section">
        @include('admin.party._alerts')

        <div class="row">
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-body pt-4">
                        <h5 class="card-title p-0 mb-3">{{ $isEdit ? 'Edit Paper' : 'Add Paper' }}</h5>

                        @if ($errors->any())
                            <div class="alert alert-danger">
                                <ul class="mb-0 ps-3">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        {{-- Server-rendered, like Expense Types: a name, an order and
                             one choice per work type. --}}
                        <form method="POST" action="{{ $formAction }}">
                            @csrf

                            <div class="mb-3">
                                <label for="name" class="form-label">Paper <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="name" name="name"
                                       value="{{ $nameValue }}" maxlength="120" required autofocus
                                       placeholder="Form 35">
                            </div>

                            <div class="mb-3">
                                <label for="sort" class="form-label">Order on the checklist</label>
                                <input type="number" min="0" step="1" class="form-control" id="sort" name="sort"
                                       value="{{ $sortValue }}" placeholder="Leave blank to add at the end">
                                <div class="form-text">Lower numbers are listed first.</div>
                            </div>

                            <div class="mb-3">
                                <span class="form-label d-block">Needed for</span>
                                @if (count($workTypes))
                                    <div class="paper-needs">
                                        @foreach ($workTypes as $type)
                                            <div class="paper-needs__row">
                                                <label for="needs_{{ $type['id'] }}">{{ $type['name'] }}</label>
                                                <select class="form-select form-select-sm" id="needs_{{ $type['id'] }}" name="needs[{{ $type['id'] }}]">
                                                    @foreach ($needLabels as $value => $label)
                                                        <option value="{{ $value }}" {{ $type['need'] === (string) $value ? 'selected' : '' }}>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        @endforeach
                                    </div>
                                    <div class="form-text">
                                        <strong>Required</strong> papers must be received, or marked not needed with a reason.
                                        <strong>If applicable</strong> papers start as not needed.
                                    </div>
                                @else
                                    <div class="form-text">Add a work type first.</div>
                                @endif
                            </div>

                            @if ($isEdit)
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" value="1"
                                           id="is_active" name="is_active" {{ $activeChecked ? 'checked' : '' }}>
                                    <label class="form-check-label" for="is_active">Asked for on new audits</label>
                                    <div class="form-text">Switch off to retire it. Files already checked keep it.</div>
                                </div>
                            @endif

                            <button type="submit" class="btn btn-primary">{{ $isEdit ? 'Save' : 'Add' }}</button>

                            @if ($isEdit)
                                <a href="{{ $cancelUrl }}" class="btn btn-outline-secondary">Cancel</a>
                            @endif
                        </form>

                        @if ($isEdit)
                            <form method="POST" action="{{ $deleteUrl }}" class="mt-3"
                                  onsubmit="return confirm('Delete this paper? Only one no file has been checked against can go.');">
                                @csrf
                                <button type="submit" class="btn btn-outline-danger btn-sm">
                                    <i class="bi bi-trash"></i> Delete
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card">
                    <div class="card-body pt-4">
                        <h5 class="card-title p-0 mb-1">The papers each work needs</h5>
                        <p class="text-muted small mb-3">
                            A starting list from Parivahan's published requirements. Check it against what your RTO
                            asks for — several papers are needed in some states only. Changes apply to files audited
                            from now on.
                        </p>
                        <div data-vue="vue-paper-types" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
