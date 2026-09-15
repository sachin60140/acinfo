@extends('admin.layouts.app')

@section('title', 'Expense Types | Ac Info')

@section('content')
    <div class="pagetitle">
        <h1>Expense Types</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item active">Expense Types</li>
            </ol>
        </nav>
    </div>

    <section class="section">
        <div class="row">
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-body pt-4">
                        <h5 class="card-title p-0 mb-3">{{ $isEdit ? 'Edit Expense Type' : 'Add Expense Type' }}</h5>

                        @if ($errors->any())
                            <div class="alert alert-danger">
                                <ul class="mb-0 ps-3">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        {{--
                            Server-rendered rather than a component of its own.
                            The work types form is eight hundred lines; this one
                            needs a name, a usual amount and a switch, and
                            copying that would leave two forms to keep in step
                            for no gain.
                        --}}
                        <form method="POST" action="{{ $formAction }}">
                            @csrf

                            <div class="mb-3">
                                <label for="name" class="form-label">Kind <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="name" name="name"
                                       value="{{ $nameValue }}" maxlength="255" required autofocus
                                       placeholder="Transfer Challan">
                            </div>

                            <div class="mb-3">
                                <label for="default_amount" class="form-label">Usual Amount</label>
                                <input type="number" step="0.01" min="0" class="form-control"
                                       id="default_amount" name="default_amount" value="{{ $amountValue }}"
                                       placeholder="Leave blank if it varies">
                                <div class="form-text">
                                    Filled in when this kind is chosen on a file, and always overridable.
                                </div>
                            </div>

                            @if ($isEdit)
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" value="1"
                                           id="is_active" name="is_active" {{ $activeChecked ? 'checked' : '' }}>
                                    <label class="form-check-label" for="is_active">
                                        Offered on new expenses
                                    </label>
                                    <div class="form-text">
                                        Switch off to retire it. Expenses already recorded keep the name.
                                    </div>
                                </div>
                            @endif

                            <button type="submit" class="btn btn-primary">
                                {{ $isEdit ? 'Save' : 'Add' }}
                            </button>

                            @if ($isEdit)
                                <a href="{{ $cancelUrl }}" class="btn btn-outline-secondary">Cancel</a>
                            @endif
                        </form>

                        @if ($isEdit)
                            <form method="POST" action="{{ $deleteUrl }}" class="mt-3"
                                  onsubmit="return confirm('Delete this expense type? Only one nothing has been spent under can go.');">
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
                        <h5 class="card-title p-0 mb-3">What has been paid out, by kind</h5>
                        <div data-vue="vue-expense-types" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
