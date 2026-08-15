@extends('admin.layouts.app')

@section('title', 'Client Ledger | Ac Info')

@section('content')
    @php
        /*
         * Props are computed here rather than inline in the directive: Blade's
         * attribute parser splits on commas and cannot read a multi-line array
         * that contains function calls.
         */

        /*
         * Largest amount first, which is the order DataTables applied on load and
         * the reason anyone opens this screen. DataGrid has no initial-sort prop,
         * so the order has to arrive already applied. These are the rows the
         * controller already sent — nothing is queried here.
         */
        $rows = $data
            ->sortByDesc(fn ($client) => (float) $client->current_balance)
            ->map(fn ($client) => [
                'id' => $client->id,
                'name' => $client->name,
                'mobile' => $client->mobile,
                'amount' => (float) $client->current_balance,
                'statement' => 'Statement',
                'statement_url' => url('admin/client/statement/' . $client->id),
                'password' => 'Set Password',
                'password_url' => route('clientpassword', $client->id),
            ])
            ->values();

        $columns = [
            ['key' => 'id', 'label' => '#'],
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'mobile', 'label' => 'Mobile'],
            /*
             * The sum of the client's ledger, kept as a plain signed figure. This
             * ledger stores a receipt positive — the opposite of the party tables
             * — so printing it as Dr/Cr would put every balance on the wrong side.
             */
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'money'],
            /*
             * One fixed label on every row, so there is nothing in these two to
             * sort by and nothing worth carrying into an export.
             */
            [
                'key' => 'statement',
                'label' => 'Statement',
                'type' => 'link',
                'linkTo' => 'statement_url',
                'sortable' => false,
                'exportable' => false,
            ],
            [
                'key' => 'password',
                'label' => 'Password',
                'type' => 'link',
                'linkTo' => 'password_url',
                'sortable' => false,
                'exportable' => false,
            ],
        ];

        $props = [
            'columns' => $columns,
            'rows' => $rows,
            'title' => 'Client Ledger',
            'perPage' => 50,
            'emptyText' => 'No clients yet.',
        ];
    @endphp

    <div class="pagetitle">
        <h1>Dashboard</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item">Clinets</li>

            </ol>
        </nav>
    </div><!-- End Page Title -->
    <section class="section dashboard">
        @if (Session::has('success'))
            <div class="alert alert-primary bg-primary text-light border-0 alert-dismissible fade show" role="alert">
                {{ Session::get('success') }}
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"
                    aria-label="Close"></button>
            </div>
        @endif

        @if (Session::has('error'))
            <div class="alert alert-danger bg-danger text-light border-0 alert-dismissible fade show" role="alert">
                {{ Session::get('error') }}
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"
                    aria-label="Close"></button>
            </div>
        @endif

        <div class="row">
            <div class="col-lg-12">

                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Client Ledger</h5>

                        <div class="ui" data-vue="vue-client-list" data-props="{{ json_encode($props) }}"></div>

                    </div>
                </div>

            </div>
        </div>

    </section>
@endsection
