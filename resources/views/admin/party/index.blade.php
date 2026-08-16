@extends('admin.layouts.app')

@section('title', $label . 's | Ac Info')

@section('style')
    @include('admin.party._style')

    <style>
        /* The green is the affordance — it says "this opens WhatsApp" faster than
           the number does, and a grid cell is a plain link with no room for the
           icon the old markup carried. Same value as .wa-link in _style. */
        .party-list .wa-cell .ui-link {
            color: #25d366;
        }

        .party-list .wa-cell .ui-link:hover {
            color: #128c7e;
        }
    </style>
@endsection

@section('content')

    <div class="pagetitle">
        <h1>{{ $label }}s</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item active">{{ $label }}s</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        <div class="row">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-body pt-4">

                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                            <div>
                                <h5 class="card-title p-0 m-0">{{ $label }} Ledgers</h5>
                                <div class="text-muted" style="font-size: 0.85rem;">
                                    {{ $partyCount }} {{ Str::plural($label, $partyCount) }}
                                    &nbsp;&middot;&nbsp; Receivable <span class="dr fw-bold">{{ number_format($totalDr, 2, '.', ',') }} Dr</span>
                                    &nbsp;&middot;&nbsp; Payable <span class="cr fw-bold">{{ number_format($totalCr, 2, '.', ',') }} Cr</span>
                                </div>
                            </div>
                            <div class="d-flex flex-wrap gap-2 card-actions">
                                <a href="{{ route('party.entry', $type) }}" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-journal-plus me-1"></i> New Entry
                                </a>
                                <a href="{{ route('party.create', $type) }}" class="btn btn-primary btn-sm">
                                    <i class="bi bi-person-plus me-1"></i> Add {{ $label }}
                                </a>
                            </div>
                        </div>

                        {{--
                            Rendered by DataGrid, which carries the search, sort,
                            paging and the Copy/CSV/Excel/PDF/Print exports that
                            were DataTables' — and none of the megabyte of CDN
                            script that came with them. The list is read-only, so
                            the server still owns every figure on it.
                        --}}
                        <div class="ui party-list" data-vue="vue-party-list" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>

                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

@section('script')
@endsection
