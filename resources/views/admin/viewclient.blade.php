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
         * the reason anyone opens this screen. The order is applied here and then
         * declared to the grid through sortedBy below, so the heading shows which
         * way the list already runs and the first click on it reverses that
         * rather than starting again from smallest. These are the rows the
         * controller already sent — nothing is queried here.
         */
        $rows = $data
            ->sortByDesc(fn ($client) => (float) $client->current_balance)
            ->map(fn ($client) => [
                'id' => $client->id,
                'name' => $client->name,
                'mobile' => $client->mobile,
                /*
                 * Negated for the same reason client-statement.blade.php negates
                 * it: a receipt credits the client and is stored positive, so a
                 * positive balance is money held for them — a credit — while
                 * balance() prints a negative as Cr. Left raw, this list printed
                 * "-1,234.00" for the client whose own statement, one click away,
                 * printed "1,234.00 Dr" for the very same money.
                 */
                'amount' => round(-(float) $client->current_balance, 2),
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
            // Written the way the client's own statement writes it: magnitude
            // plus the side it falls on, never a minus sign.
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'balance'],
            /*
             * One fixed label on every row, so there is nothing in these two to
             * sort by and nothing worth carrying into an export. They are kept out
             * of the search text for the same reason: the words are on every row,
             * so searching either of them would match the whole list.
             */
            [
                'key' => 'statement',
                'label' => 'Statement',
                'type' => 'link',
                'linkTo' => 'statement_url',
                // A statement is read beside the list it was opened from, which is
                // what the old link did and what anyone reconciling accounts needs.
                'newTab' => true,
                'sortable' => false,
                'searchable' => false,
                'exportable' => false,
            ],
            [
                'key' => 'password',
                'label' => 'Password',
                'type' => 'link',
                'linkTo' => 'password_url',
                // Stays in this tab: setting a password redirects back to this
                // list, so a new tab would leave the reader with two of them.
                'sortable' => false,
                'searchable' => false,
                'exportable' => false,
            ],
        ];

        $props = [
            'columns' => $columns,
            'rows' => $rows,
            'title' => 'Client Ledger',
            'perPage' => 50,
            'emptyText' => 'No clients yet.',
            /*
             * Ascending, because the figure was negated above: the rows are in
             * exactly the order they have always been in, largest raw balance
             * first, and describing that order accurately is what lets the first
             * click reverse it instead of re-sorting from scratch.
             */
            'sortedBy' => 'amount',
            'sortedDesc' => false,
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

                        <div class="ui" data-vue="vue-client-list" data-props="{{ \App\Support\VueProps::encode($props) }}"></div>

                    </div>
                </div>

            </div>
        </div>

    </section>
@endsection
