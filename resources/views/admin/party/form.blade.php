@extends('admin.layouts.app')

@section('title', ($party ? 'Edit' : 'Add') . ' ' . $label . ' | Ac Info')

@section('style')
    @include('admin.party._style')
@endsection

@section('content')
    @php
        $isEdit = (bool) $party;
        $action = $isEdit ? route('party.edit', $party->id) : route('party.create', $type);
        // An unchecked checkbox posts nothing, so old('is_active') is absent both
        // when the form is fresh and when the user deliberately cleared it. Only
        // the presence of validation errors tells the two apart.
        $activeChecked = $isEdit ? ($errors->any() ? old('is_active') : $party->is_active) : true;
    @endphp

    <div class="pagetitle">
        <h1>{{ $isEdit ? 'Edit' : 'Add' }} {{ $label }}</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item"><a href="{{ route('party.index', $type) }}">{{ $label }}s</a></li>
                <li class="breadcrumb-item active">{{ $isEdit ? 'Edit' : 'Add' }}</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        <div class="row">
            <div class="col-lg-9">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
                            <h5 class="card-title mb-0">{{ $label }} Details</h5>
                            <a href="{{ route('party.index', $type) }}" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-list-ul me-1"></i> All {{ $label }}s
                            </a>
                        </div>

                        {{--
                            Rendered by Vue. The field names are the ones
                            PartyController::create() and ::edit() already validate, so
                            the form still posts normally and the server still checks
                            every value — which is what makes converting a screen on a
                            live ledger safe: only the rendering moves.
                        --}}
                        <div data-vue="vue-party-form" data-props="{{ json_encode([
                            'action' => $action,
                            'csrf' => csrf_token(),
                            'label' => $label,
                            'indexUrl' => route('party.index', $type),
                            'isEdit' => $isEdit,
                            'isActive' => (bool) $activeChecked,
                            'defaultOpeningType' => $defaultOpeningType ?? 'debit',
                            'values' => [
                                'name' => old('name', $isEdit ? $party->name : ''),
                                'mobile' => old('mobile', $isEdit ? $party->mobile : ''),
                                'whatsapp' => old('whatsapp', $isEdit ? $party->whatsapp : ''),
                                'address' => old('address', $isEdit ? $party->address : ''),
                                'opening_balance' => old('opening_balance', ''),
                                'opening_type' => old('opening_type', $defaultOpeningType ?? 'debit'),
                            ],
                            // Rendered here rather than rebuilt in the component: the
                            // date box keeps one markup contract, the one
                            // assets/js/datepicker.js binds by class.
                            'dateField' => $isEdit ? '' : view('partials._datefield', [
                                'name' => 'opening_date',
                                'value' => old('opening_date', date('Y-m-d')),
                            ])->render(),
                            // The list above stays as it is; this puts the same message
                            // against the field it came from. Cast so an empty bag
                            // still arrives as an object rather than as an array.
                            'errors' => (object) array_map(fn ($messages) => $messages[0], $errors->messages()),
                        ]) }}"></div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

{{-- The digit filtering and the "same as mobile" mirror moved into the
     component. The opening-date box still parses and validates itself and keeps
     its own hidden Y-m-d value in step — see assets/js/datepicker.js. --}}
@section('script')
@endsection
