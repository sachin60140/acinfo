@extends('admin.layouts.app')

@section('title', 'Work Types | Ac Info')

@section('style')
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    @include('admin.party._style')
@endsection

@section('content')
    @php
        $isEdit = (bool) $editing;
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

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        <div class="row">
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">{{ $isEdit ? 'Edit' : 'Add' }} Work Type</h5>

                        <form class="row g-3" method="POST" action="{{ $isEdit ? route('worktype.edit', $editing->id) : route('worktype.index') }}">
                            @csrf

                            <div class="col-12">
                                <label for="name" class="form-label">Work Type <span class="required-mark">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-briefcase"></i></span>
                                    <input type="text" class="form-control" id="name" name="name" value="{{ old('name', $isEdit ? $editing->name : '') }}" placeholder="e.g. RC Transfer" required autofocus>
                                </div>
                            </div>

                            <div class="col-12">
                                <label for="default_rate" class="form-label">Default Rate</label>
                                <div class="input-group">
                                    <span class="input-group-text">INR</span>
                                    <input type="number" min="0" step="0.01" class="form-control" id="default_rate" name="default_rate" value="{{ old('default_rate', $isEdit ? $editing->default_rate : '') }}" placeholder="0.00">
                                </div>
                                <div class="side-hint">Pre-fills the amount on the file form. Leave blank for work quoted per job.</div>
                            </div>

                            @if ($isEdit)
                                <div class="col-12">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" {{ $activeChecked ? 'checked' : '' }}>
                                        <label class="form-check-label" for="is_active">Active</label>
                                    </div>
                                    <div class="side-hint">Inactive types stay on existing files but cannot be chosen for new ones.</div>
                                </div>
                            @endif

                            <div class="col-12 d-flex gap-2 justify-content-end">
                                @if ($isEdit)
                                    <a href="{{ route('worktype.index') }}" class="btn btn-outline-secondary">Cancel</a>
                                @endif
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check2-circle me-1"></i> {{ $isEdit ? 'Update' : 'Add' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-8 mt-4 mt-lg-0">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                            <h5 class="card-title p-0 m-0">All Work Types</h5>
                            <a href="{{ route('workfile.index') }}" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-folder2-open me-1"></i> Work Files
                            </a>
                        </div>

                        <div class="table-responsive">
                            <table class="table display party-table rt" id="example">
                                <thead>
                                    <tr>
                                        <th scope="col">#</th>
                                        <th scope="col">Work Type</th>
                                        <th scope="col" class="text-end">Default Rate</th>
                                        <th scope="col" class="text-end">Files</th>
                                        <th scope="col" class="text-end">Billed</th>
                                        <th scope="col" class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($types as $items)
                                        <tr>
                                            <td data-label="#">{{ $items->id }}</td>
                                            <td>
                                                {{ $items->name }}
                                                @if (! $items->is_active)
                                                    <span class="badge bg-secondary ms-1">Inactive</span>
                                                @endif
                                            </td>
                                            <td data-label="Default Rate" class="text-end">{{ $items->default_rate === null ? '—' : number_format((float) $items->default_rate, 2, '.', ',') }}</td>
                                            <td data-label="Files" class="text-end">{{ $items->file_count }}</td>
                                            <td data-label="Billed" class="text-end">{{ number_format((float) $items->billed_total, 2, '.', ',') }}</td>
                                            <td class="text-end">
                                                <a href="{{ route('worktype.edit', $items->id) }}" class="link-primary">Edit</a>
                                            </td>
                                        </tr>
                                    @empty
                                        {{-- No placeholder row here on purpose: a colspan cell has
                                             fewer cells than the header, which stops DataTables
                                             initialising with "Incorrect column count". DataTables
                                             draws its own message from language.emptyTable below. --}}
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

@section('script')
    <script src="https://code.jquery.com/jquery-3.7.0.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>

    <script>
        $(document).ready(function() {
            $('#example').DataTable({
                language: { emptyTable: 'No work types yet — add the first one on the left.' },
                "pageLength": 25,
                "aaSorting": [
                    [1, 'asc']
                ],
                columnDefs: [{
                    orderable: false,
                    targets: [5]
                }]
            });
        });
    </script>
@endsection
