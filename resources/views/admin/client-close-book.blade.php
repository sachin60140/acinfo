@extends('admin.layouts.app')

@section('title', 'Close Old Book | Ac Info')

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
                        <table class="table align-middle mb-0">
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
                                        <td><a href="{{ route('clientstatement', $row['id']) }}">{{ $row['name'] }}</a></td>
                                        <td>{{ $row['mobile'] }}</td>
                                        <td class="text-end text-nowrap">{{ $row['balance'] }}</td>
                                        <td>
                                            <form method="POST" action="{{ route('client.carry', $row['id']) }}" class="d-flex flex-wrap gap-2">
                                                @csrf
                                                <select name="customer" class="form-select form-select-sm w-auto" required aria-label="Customer for {{ $row['name'] }}">
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
