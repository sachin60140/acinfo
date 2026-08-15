@extends('admin.layouts.app')

@section('title', 'Work Files | Ac Info')

@section('style')
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">
    @include('admin.layouts._statement-style')
    @include('admin.party._style')
@endsection

@section('content')
    @php
        $today = now();
        $base = route('workfile.index');

        // Totals follow what each file actually earned and cost once its status is
        // taken into account — cancelled charged nobody, returned charged and gave
        // it straight back. Counting the face figures would make this disagree
        // with the statements it is supposed to summarise.
        $work = \App\Models\WorkFileModel::class;

        $billed = 0.0;
        $cost = 0.0;
        $closedCount = 0;
        foreach ($files as $f) {
            if (in_array($f->status, [$work::CANCELLED, $work::RETURNED], true)) {
                $closedCount++;
            }

            // Part refunds and vendor returns both change what a file really
            // earned and cost. Leaving those out made this page disagree with
            // the statements and the dashboard it is meant to summarise.
            $billed += $work::netCustomer($f->status, $f->customer_amount, $f->returned_amount);
            $cost += $work::netVendor($f->status, $f->vendor_amount, $f->vendor_returned_on !== null, $f->vendor_returned_amount);
        }
    @endphp

    <div class="pagetitle">
        <h1>Work Files</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item active">Work Files</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        <div class="row">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-body pt-4">

                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                            <div>
                                <h5 class="card-title p-0 m-0">Files Received</h5>
                                <div class="statement-period">
                                    {{ $files->count() }} {{ Str::plural('file', $files->count()) }}
                                    @if ($closedCount)
                                        &nbsp;&middot;&nbsp; {{ $closedCount }} cancelled or returned, not counted as billed
                                    @endif
                                    @if ($status)
                                        &nbsp;&middot;&nbsp; Status: {{ \App\Models\WorkFileModel::STATUSES[$status] }}
                                    @endif
                                </div>
                            </div>
                            <div class="d-flex flex-wrap gap-2 card-actions">
                                <a href="{{ route('worktype.index') }}" class="btn btn-outline-secondary btn-sm">
                                    <i class="bi bi-briefcase me-1"></i> Work Types
                                </a>
                                <a href="{{ route('workfile.status') }}" class="btn btn-outline-secondary btn-sm">
                                    <i class="bi bi-flag me-1"></i> Update Status
                                </a>
                                <a href="{{ route('workfile.assign') }}" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-truck me-1"></i> Give to Vendor
                                </a>
                                <a href="{{ route('workfile.receive') }}" class="btn btn-primary btn-sm">
                                    <i class="bi bi-folder-plus me-1"></i> Receive Files
                                </a>
                            </div>
                        </div>

                        <form method="GET" action="{{ $base }}" class="row g-2 align-items-end statement-filter mb-3">
                            <div class="col-sm-auto">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="">All</option>
                                    @foreach (\App\Models\WorkFileModel::STATUSES as $key => $text)
                                        <option value="{{ $key }}" {{ $status === $key ? 'selected' : '' }}>{{ $text }}</option>
                                    @endforeach

                                </select>
                            </div>
                            <div class="col-sm-auto">
                                <label for="from_display" class="form-label">From</label>
                                @include('partials._datefield', ['name' => 'from', 'value' => $from, 'max' => $today->toDateString()])
                            </div>
                            <div class="col-sm-auto">
                                <label for="to_display" class="form-label">To</label>
                                @include('partials._datefield', ['name' => 'to', 'value' => $to, 'max' => $today->toDateString()])
                            </div>
                            <div class="col-sm-auto">
                                <button type="submit" class="btn btn-primary">Apply</button>
                                <a href="{{ $base }}" class="btn btn-outline-secondary">Reset</a>
                            </div>
                        </form>

                        <div class="statement-summary mb-3">
                            <div class="stat">
                                <span class="label">Billed to Customers</span>
                                <span class="value dr">{{ number_format($billed, 2, '.', ',') }}</span>
                            </div>
                            <div class="stat">
                                <span class="label">Vendor Cost</span>
                                <span class="value cr">{{ number_format($cost, 2, '.', ',') }}</span>
                            </div>
                            <div class="stat closing">
                                <span class="label">Margin</span>
                                <span class="value {{ $billed - $cost < 0 ? 'cr' : 'dr' }}">{{ number_format($billed - $cost, 2, '.', ',') }}</span>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table display statement-table rt" id="example">
                                <thead>
                                    <tr>
                                        <th scope="col">File No.</th>
                                        <th scope="col">Vehicle</th>
                                        <th scope="col">Received</th>
                                        <th scope="col">Work Type</th>
                                        <th scope="col">Details</th>
                                        <th scope="col">Customer</th>
                                        <th scope="col" class="text-end">Charged</th>
                                        <th scope="col">Vendor</th>
                                        <th scope="col" class="text-end">Cost</th>
                                        <th scope="col" class="text-end">Margin</th>
                                        <th scope="col">Status</th>
                                        <th scope="col" class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($files as $items)
                                        @php
                                            $netCustomer = $work::netCustomer($items->status, $items->customer_amount, $items->returned_amount);
                                            $netVendor = $work::netVendor($items->status, $items->vendor_amount, $items->vendor_returned_on !== null, $items->vendor_returned_amount);
                                            $fileMargin = $netCustomer - $netVendor;
                                            // Struck through where the figure no longer stands at all; where only
                                            // part of it came back, both are shown so the row explains itself.
                                            $voidCustomer = $netCustomer <= 0 && (float) $items->customer_amount > 0;
                                            $voidVendor = $netVendor <= 0 && (float) $items->vendor_amount > 0;
                                            $partCustomer = ! $voidCustomer && abs($netCustomer - (float) $items->customer_amount) > 0.005;
                                            $partVendor = ! $voidVendor && abs($netVendor - (float) $items->vendor_amount) > 0.005;
                                            $isClosed = in_array($items->status, [$work::CANCELLED, $work::RETURNED], true);
                                        @endphp
                                        <tr class="{{ $isClosed ? 'text-muted' : '' }}">
                                            <td data-label="File No.">{{ $items->file_no }}</td>
                                            <td data-label="Vehicle">{{ $items->registration_no }}</td>
                                            {{-- data-order keeps the raw Y-m-d so any sort stays chronological
                                                 while the reader sees dd-mm-yyyy. --}}
                                            <td data-label="Received" data-order="{{ $items->received_date }}">{{ date('d-m-Y', strtotime($items->received_date)) }}</td>
                                            <td data-label="Work Type">{{ $items->work_type }}</td>
                                            <td data-label="Details">{{ $items->description }}</td>
                                            <td data-label="Customer">
                                                <a href="{{ route('party.statement', $items->customer_id) }}" class="link-primary" target="_blank">{{ $items->customer_name }}</a>
                                            </td>
                                            {{-- Struck through rather than blanked: the figures the file was
                                                 entered with are still worth seeing, they just no longer count. --}}
                                            <td data-label="Charged" class="text-end {{ $voidCustomer ? 'text-decoration-line-through' : 'dr' }}">
                                                @if ($partCustomer)
                                                    <span class="text-muted text-decoration-line-through">{{ number_format((float) $items->customer_amount, 2, '.', ',') }}</span>
                                                    <span class="dr">{{ number_format($netCustomer, 2, '.', ',') }}</span>
                                                @else
                                                    {{ number_format((float) $items->customer_amount, 2, '.', ',') }}
                                                @endif
                                            </td>
                                            <td data-label="Vendor">
                                                @if ($items->vendor_id)
                                                    <a href="{{ route('party.statement', $items->vendor_id) }}" class="link-primary" target="_blank">{{ $items->vendor_name }}</a>
                                                @else
                                                    <span class="text-muted">In-house</span>
                                                @endif
                                            </td>
                                            <td data-label="Cost" class="text-end {{ $voidVendor ? 'text-decoration-line-through' : 'cr' }}">
                                                @if ($items->vendor_amount === null)
                                                @elseif ($partVendor)
                                                    <span class="text-muted text-decoration-line-through">{{ number_format((float) $items->vendor_amount, 2, '.', ',') }}</span>
                                                    <span class="cr">{{ number_format($netVendor, 2, '.', ',') }}</span>
                                                @else
                                                    {{ number_format((float) $items->vendor_amount, 2, '.', ',') }}
                                                @endif
                                            </td>
                                            <td data-label="Margin" class="text-end fw-bold {{ abs($fileMargin) < 0.005 ? '' : ($fileMargin < 0 ? 'cr' : 'dr') }}">{{ number_format($fileMargin, 2, '.', ',') }}</td>
                                            <td data-label="Status">
                                                <span class="badge {{ $work::STATUS_BADGES[$items->status] ?? 'bg-secondary' }}">
                                                    {{ $work::STATUSES[$items->status] ?? $items->status }}
                                                </span>
                                                @if ($items->approval_screenshot)
                                                    <a href="{{ url($items->approval_screenshot) }}" target="_blank" rel="noopener" class="link-primary ms-1" title="Approval screenshot">
                                                        <i class="bi bi-paperclip"></i>
                                                    </a>
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                <a href="{{ route('workfile.edit', $items->id) }}" class="link-primary">Edit</a>
                                            </td>
                                        </tr>
                                    @empty
                                        {{-- No placeholder row here on purpose: a colspan cell has
                                             fewer cells than the header, which stops DataTables
                                             initialising with "Incorrect column count". DataTables
                                             draws its own message from language.emptyTable below. --}}
                                    @endforelse
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <th colspan="6" class="text-end">Totals</th>
                                        <th data-label="Billed" class="text-end dr">{{ number_format($billed, 2, '.', ',') }}</th>
                                        <th></th>
                                        <th data-label="Vendor Cost" class="text-end cr">{{ number_format($cost, 2, '.', ',') }}</th>
                                        <th data-label="Margin" class="text-end {{ $billed - $cost < 0 ? 'cr' : 'dr' }}">{{ number_format($billed - $cost, 2, '.', ',') }}</th>
                                        <th colspan="2"></th>
                                    </tr>
                                </tfoot>
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
                language: { emptyTable: 'No files in this view — use Receive Files above.' },
                dom: 'Bfrtip',
                buttons: ['copyHtml5', 'excelHtml5', 'csvHtml5', 'pdfHtml5', 'print'],
                "pageLength": 50,
                // Server sends newest first; keep it.
                order: [],
                columnDefs: [{
                    orderable: false,
                    targets: [11]
                }]
            });
        });
    </script>
@endsection
