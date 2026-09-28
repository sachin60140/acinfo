@extends('admin.layouts.app')

@section('title', 'Close Old Book | Ac Info')

@section('style')
    <style>
        .cb-pick {
            min-width: 14rem;
            width: auto;
        }

        /*
         * On a phone, a card for each client, not a table 777px wide to scroll
         * sideways (found by the mobile audit): the name and balance, then
         * the customer to carry to across the whole card.
         */
        @media (max-width: 767.98px) {
            .cb-table thead {
                display: none;
            }

            .cb-table,
            .cb-table tbody,
            .cb-table tr,
            .cb-table td {
                display: block;
                width: 100%;
            }

            .cb-table tr {
                border: 1px solid #dee2e6;
                border-radius: 8px;
                margin-bottom: 0.75rem;
                padding: 0.5rem 0.75rem;
            }

            .cb-table td {
                border: 0;
                padding: 0.25rem 0;
                text-align: left !important;
            }

            .cb-table td[data-label]::before {
                color: #6c757d;
                content: attr(data-label) ' ';
                font-size: 0.75rem;
                font-weight: 700;
                text-transform: uppercase;
            }

            .cb-pick,
            .cb-table form .btn {
                min-width: 0;
                width: 100%;
            }
        }
    </style>
@endsection

@section('content')
    <div class="pagetitle">
        <h1>Close the Old Client Ledger</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item">Client Ledger</li>
                <li class="breadcrumb-item active">Close Old Book</li>
            </ol>
        </nav>
    </div>

    <section class="section">
        @include('admin.party._alerts')

        <div class="card">
            <div class="card-body pt-4">
                @if (! $rows)
                    <h5 class="card-title p-0 mb-2">Nothing is left in the old book</h5>
                    <p class="mb-0">
                        Every client's balance has been carried to Customers. Their old statements stay under
                        <a href="{{ route('viewclient') }}">View Client</a>.
                    </p>
                @else
                    <h5 class="card-title p-0 mb-2">{{ count($rows) }} {{ Str::plural('client', count($rows)) }} still have a balance in the old book</h5>
                    <p>
                        Pick the customer each one is, or make one from the client, and carry the balance over.
                        The old book gets a line bringing it to nothing, "{{ \App\Http\Controllers\CloseClientLedgerController::CARRIED }}",
                        and the customer a line for the same amount, "{{ \App\Http\Controllers\CloseClientLedgerController::BROUGHT }}".
                        Both are dated today. Nothing is deleted.
                    </p>

                    <div class="table-responsive">
                        <table class="table align-middle mb-0 cb-table">
                            <thead>
                                <tr>
                                    <th>Client</th>
                                    <th>Mobile</th>
                                    <th class="text-end">Balance</th>
                                    <th>Carry to customer</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows as $row)
                                    <tr>
                                        <td data-label="Client"><a href="{{ route('clientstatement', $row['id']) }}">{{ $row['name'] }}</a></td>
                                        <td data-label="Mobile">{{ $row['mobile'] }}</td>
                                        <td class="text-end text-nowrap" data-label="Balance">{{ $row['balance'] }}</td>
                                        <td>
                                            <form method="POST" action="{{ route('client.carry', $row['id']) }}" class="d-flex flex-wrap gap-2">
                                                @csrf
                                                <select name="customer" class="form-select form-select-sm cb-pick" required aria-label="Customer for {{ $row['name'] }}">
                                                    <option value="">Pick a customer…</option>
                                                    @if ($row['canCreate'])
                                                        <option value="new">New customer: {{ $row['name'] }} ({{ $row['mobile'] }})</option>
                                                    @endif
                                                    @foreach ($customers as $customer)
                                                        <option value="{{ $customer->id }}" @selected($row['suggested'] === (int) $customer->id)>
                                                            {{ $customer->name }} ({{ $customer->mobile }})
                                                        </option>
                                                    @endforeach
                                                </select>
                                                <button type="submit" class="btn btn-sm btn-primary text-nowrap">Carry over</button>
                                            </form>
                                            @if ($row['suggested'])
                                                <div class="small text-muted">Picked for you: the customer with the same mobile. Change it if that is wrong.</div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </section>
@endsection
