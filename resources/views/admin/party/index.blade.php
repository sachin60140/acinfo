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
    @php
        $totalDr = 0.0;
        $totalCr = 0.0;
        foreach ($parties as $p) {
            if ($p->current_balance >= 0) {
                $totalDr += (float) $p->current_balance;
            } else {
                $totalCr += (float) abs($p->current_balance);
            }
        }

        /*
         * Column order, sortability and the export set are the ones the old
         * DataTables config carried; several of them were fixes.
         *
         * Balance is typed 'balance', so it sorts on the raw signed figure and
         * prints "1,200.00 Cr" — which is what the data-order attribute on the
         * old cell existed to achieve. Action is unsortable, as it was, and is
         * kept out of exports: a column of the word "Edit" is not data.
         *
         * No totals row. Netting a customer in debit against one in credit
         * would report a business with nothing outstanding, when it has money
         * to collect and money to refund; both sides are shown above instead.
         */
        $columns = [
            ['key' => 'id', 'label' => '#'],
            ['key' => 'name', 'label' => 'Name', 'type' => 'link', 'linkTo' => 'statement_url', 'sub' => 'inactive_note'],
            ['key' => 'mobile', 'label' => 'Mobile', 'type' => 'link', 'linkTo' => 'mobile_url'],
            ['key' => 'whatsapp', 'label' => 'WhatsApp', 'type' => 'link', 'linkTo' => 'whatsapp_url', 'class' => 'wa-cell'],
            ['key' => 'address', 'label' => 'Address'],
            ['key' => 'entry_count', 'label' => 'Entries', 'type' => 'count'],
            ['key' => 'current_balance', 'label' => 'Balance', 'type' => 'balance'],
            ['key' => 'action', 'label' => 'Action', 'type' => 'link', 'linkTo' => 'edit_url', 'sortable' => false, 'exportable' => false],

            // Carried for searching only: "inactive" finds the deactivated
            // parties, whose marker is otherwise a quiet line under the name.
            ['key' => 'inactive_note', 'label' => 'Status', 'hidden' => true],
        ];

        $rows = $parties->map(function ($party) {
            // Blank means "no separate WhatsApp number", so the link falls back
            // to the mobile — which is the same number in most cases.
            $wa = $party->whatsapp ?: $party->mobile;

            return [
                'id' => (int) $party->id,
                'name' => $party->name,
                'statement_url' => route('party.statement', $party->id),
                'inactive_note' => $party->is_active ? null : 'Inactive',
                'mobile' => $party->mobile,
                'mobile_url' => 'tel:'.$party->mobile,
                'whatsapp' => $wa,
                'whatsapp_url' => 'https://wa.me/91'.$wa,
                'address' => $party->address,
                'entry_count' => (int) $party->entry_count,
                'current_balance' => (float) $party->current_balance,
                'action' => 'Edit',
                'edit_url' => route('party.edit', $party->id),
            ];
        })->values();

        /*
         * The grid opens unsorted, which lands on name ascending: withBalance()
         * already orders by name, and that is the order the old table was
         * configured to sort itself into on load.
         */
        $grid = [
            'columns' => $columns,
            'rows' => $rows,
            'title' => $label.' Ledgers',
            'perPage' => 50,
            'emptyText' => 'No '.Str::lower($label).'s yet — use the Add button above.',
        ];
    @endphp

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
                                    {{ $parties->count() }} {{ Str::plural($label, $parties->count()) }}
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
                        <div class="ui party-list" data-vue="vue-party-list" data-props="{{ json_encode($grid) }}"></div>

                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

@section('script')
@endsection
