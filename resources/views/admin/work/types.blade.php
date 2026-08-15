@extends('admin.layouts.app')

@section('title', 'Work Types | Ac Info')

@section('content')
    @php
        $isEdit = (bool) $editing;
        // After a failed submission the switch is drawn from what was sent, not
        // from what is stored, or a change the user made is silently undone.
        $activeChecked = $isEdit ? ($errors->any() ? old('is_active') : $editing->is_active) : true;
    @endphp

    <div class="pagetitle">
        <h1>Work Types</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item active">Work Types</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard">
        @include('admin.party._alerts')

        {{--
            Rendered by Vue. The form posts name, default_rate and is_active —
            the field names WorkTypeController::index() already validates — so
            the submission and its checks are unchanged and only the rendering
            moved. The list's sorting and search, which were DataTables', are in
            the component.
        --}}
        <div data-vue="vue-work-types" data-props="{{ json_encode([
            'action' => $isEdit ? route('worktype.edit', $editing->id) : route('worktype.index'),
            'csrf' => csrf_token(),
            'cancelUrl' => route('worktype.index'),
            'filesUrl' => route('workfile.index'),
            'editingId' => $isEdit ? (int) $editing->id : null,
            'initial' => [
                'name' => old('name', $isEdit ? $editing->name : ''),
                'default_rate' => old('default_rate', $isEdit ? $editing->default_rate : ''),
                'is_active' => (bool) $activeChecked,
            ],
            'types' => $types->map(fn ($type) => [
                'id' => (int) $type->id,
                'name' => $type->name,
                'default_rate' => $type->default_rate === null ? null : (float) $type->default_rate,
                'is_active' => (bool) $type->is_active,
                'file_count' => (int) $type->file_count,
                'billed_total' => (float) $type->billed_total,
                'edit_url' => route('worktype.edit', $type->id),
            ])->values(),
        ]) }}"></div>
    </section>
@endsection
