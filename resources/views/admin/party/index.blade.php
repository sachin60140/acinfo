@extends('admin.layouts.app')

@section('title', $label . 's | Ac Info')

@section('style')
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">
    @include('admin.party._style')
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

                        <div class="table-responsive">
                            <table class="table display party-table rt" id="example">
                                <thead>
                                    <tr>
                                        <th scope="col">#</th>
                                        <th scope="col">Name</th>
                                        <th scope="col">Mobile</th>
                                        <th scope="col">WhatsApp</th>
                                        <th scope="col">Address</th>
                                        <th scope="col" class="text-end">Entries</th>
                                        <th scope="col" class="text-end">Balance</th>
                                        <th scope="col" class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($parties as $items)
                                        @php
                                            // Blank means "no separate WhatsApp number", so the link falls
                                            // back to the mobile — which is the same number in most cases.
                                            $wa = $items->whatsapp ?: $items->mobile;
                                        @endphp
                                        <tr>
                                            <td data-label="#">{{ $items->id }}</td>
                                            <td>
                                                {{ $items->name }}
                                                @if (! $items->is_active)
                                                    <span class="badge bg-secondary ms-1">Inactive</span>
                                                @endif
                                            </td>
                                            <td data-label="Mobile"><a href="tel:{{ $items->mobile }}" class="link-primary">{{ $items->mobile }}</a></td>
                                            <td>
                                                <a href="https://wa.me/91{{ $wa }}" target="_blank" rel="noopener" class="wa-link">
                                                    <i class="bi bi-whatsapp me-1"></i>{{ $wa }}
                                                </a>
                                            </td>
                                            <td data-label="Address">{{ $items->address }}</td>
                                            <td data-label="Entries" class="text-end">{{ $items->entry_count }}</td>
                                            <td data-label="Balance" class="text-end fw-bold {{ $items->current_balance < 0 ? 'cr' : 'dr' }}" data-order="{{ $items->current_balance }}">
                                                {{ \App\Models\PartyLedgerModel::formatBalance($items->current_balance) }}
                                            </td>
                                            <td class="text-end">
                                                <a href="{{ route('party.statement', $items->id) }}" class="link-primary" target="_blank">Statement</a>
                                                <span class="text-muted mx-1">|</span>
                                                <a href="{{ route('party.edit', $items->id) }}" class="link-primary">Edit</a>
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
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>

    <script>
        $(document).ready(function() {
            $('#example').DataTable({
                language: { emptyTable: 'No {{ strtolower($label) }}s yet — use the Add button above.' },
                dom: 'Bfrtip',
                buttons: ['copyHtml5', 'excelHtml5', 'csvHtml5', 'pdfHtml5', 'print'],
                "pageLength": 50,
                "aaSorting": [
                    [1, 'asc']
                ],
                columnDefs: [{
                    orderable: false,
                    targets: [7]
                }]
            });
        });
    </script>
@endsection
