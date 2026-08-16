@extends('admin.layouts.app')

@section('title','Dashboard | Ac Info')


@section('style')
@endsection

@section('content')
    <div class="pagetitle">
        <h1>Dashboard</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{url('admin/dashboard')}}">Home</a></li>
                <li class="breadcrumb-item active">Dashboard</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    @php
        /*
         * Every figure here is one the controller already computed. The tiles are
         * described rather than drawn so the component decides how a figure reads
         * — grouped, two decimals, Dr/Cr where it is a balance — in one place
         * instead of six.
         *
         * Receivable and payable stay separate and adjacent. Netting them would
         * report a business owed 10,000 and owing 7,000 as one owed 3,000, which
         * is a different and much calmer statement than the truth.
         */
        $dashboardTiles = [
            /*
             * Both are sums over client_ledger, which stores a receipt positive —
             * so they are negated and written as balances, exactly as the client
             * list and each client's own statement write the same figures. Left
             * raw they printed "-446,722.91" here while the list one click away
             * printed the same money as "446,722.91 Dr".
             */
            [
                'group' => 'Client ledger',
                'label' => 'Net Outstanding',
                'value' => round(-(float) $totaldues, 2),
                'type' => 'balance',
                'note' => 'Across every client',
            ],
            [
                'group' => 'Client ledger',
                'label' => 'Net Movement',
                'value' => round(-(float) $monthnet, 2),
                'type' => 'balance',
                'note' => now()->format('F Y'),
            ],
            [
                'group' => 'Client ledger',
                'label' => 'Clients',
                'value' => (int) $clientcount,
                'type' => 'count',
                'note' => 'On the books',
            ],
            [
                'group' => 'Parties',
                'label' => 'Receivable',
                'value' => (float) $outstanding['receivable'],
                'type' => 'money',
                'tone' => 'dr',
                'note' => $outstanding['customers'].' '.Str::plural('customer', $outstanding['customers']),
                // Lands on the customers this figure was summed from.
                'href' => route('party.index', 'customer'),
            ],
            [
                'group' => 'Parties',
                'label' => 'Payable',
                'value' => (float) $outstanding['payable'],
                'type' => 'money',
                'tone' => 'cr',
                'note' => $outstanding['vendors'].' '.Str::plural('vendor', $outstanding['vendors']),
                'href' => route('party.index', 'vendor'),
            ],
            [
                'group' => 'Work',
                'label' => 'Open Files',
                'value' => (int) $work['open'],
                'type' => 'count',
                'note' => 'Work in hand',
                // The filtered list, not every file ever received — the count and
                // the screen it opens have to be the same set.
                'href' => route('workfile.index', ['status' => 'open']),
            ],
            [
                'group' => 'Work',
                'label' => 'File Margin',
                'value' => (float) $work['month_margin'],
                'type' => 'money',
                'note' => 'on '.number_format($work['month_billed'], 2, '.', ',').' billed · '.now()->format('F Y'),
            ],
        ];
    @endphp

    <section class="section ui">
        <div data-vue="vue-dashboard" data-props="{{ \App\Support\VueProps::encode(['tiles' => $dashboardTiles]) }}"></div>
    </section>
@endsection

@section('script')

@endsection