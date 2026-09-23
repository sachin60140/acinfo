@extends('admin.layouts.app')

@section('title', 'Hand-over Sheet | Ac Info')

@section('style')
    <style>
        .sheet {
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: .5rem;
            padding: 1.5rem;
        }

        .sheet__head {
            border-bottom: 2px solid #212529;
            margin-bottom: 1rem;
            padding-bottom: .75rem;
        }

        .sheet table {
            width: 100%;
        }

        .sheet th,
        .sheet td {
            border-bottom: 1px solid #dee2e6;
            padding: .5rem .4rem;
            text-align: left;
            vertical-align: top;
        }

        .sheet th {
            border-bottom: 1px solid #212529;
            font-size: .8rem;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        /* The vendor writes here, so it has to be wide enough to write in. */
        .sheet .remark-col {
            min-width: 8rem;
        }

        .sheet__sign {
            display: flex;
            gap: 3rem;
            margin-top: 3rem;
        }

        .sheet__sign div {
            border-top: 1px solid #212529;
            flex: 1;
            padding-top: .4rem;
        }

        /*
         * What goes on the paper: the sheet, and nothing else. The office's own
         * navigation, the pickers and the Print button are on screen to reach
         * it with, and a vendor signs the sheet rather than the screen.
         */
        @media print {
            .sidebar,
            .header,
            .footer,
            .pagetitle,
            .sheet-tools,
            .alert {
                display: none !important;
            }

            #main,
            .sheet {
                border: 0;
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
            }

            .sheet {
                font-size: 12px;
            }
        }
    </style>
@endsection

@section('content')
    <div class="pagetitle">
        <h1>Hand-over Sheet</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item"><a href="{{ route('workfile.assign') }}">Give to Vendor</a></li>
                <li class="breadcrumb-item active">Hand-over Sheet</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section">
        @include('admin.party._alerts')

        <div class="card sheet-tools">
            <div class="card-body pt-4">
                <p class="text-muted">
                    What one vendor was handed on one day, to print and have signed. Two copies: one signed
                    and kept here, one with them. No rates on it.
                </p>

                <form method="GET" action="{{ route('workfile.dispatchsheet') }}" class="row g-2 align-items-end">
                    <div class="col-sm-5">
                        <label for="vendor" class="form-label">Vendor</label>
                        <select id="vendor" name="vendor" class="form-select" required>
                            <option value="">Select vendor</option>
                            @foreach ($vendors as $one)
                                <option value="{{ $one->id }}" @selected($vendor && (int) $one->id === (int) $vendor->id)>
                                    {{ $one->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-sm-4">
                        <label for="date" class="form-label">Day given</label>
                        @if ($days->isNotEmpty())
                            <select id="date" name="date" class="form-select" required>
                                <option value="">Select day</option>
                                @foreach ($days as $day)
                                    <option value="{{ $day->day }}" @selected($date === $day->day)>
                                        {{ date('d-m-Y', strtotime($day->day)) }}
                                        ({{ $day->files }} {{ Str::plural('file', $day->files) }})
                                    </option>
                                @endforeach
                            </select>
                        @else
                            <input type="date" id="date" name="date" class="form-control" value="{{ $date }}" required>
                        @endif
                    </div>

                    <div class="col-sm-auto">
                        <button type="submit" class="btn btn-outline-secondary">Show</button>
                        @if ($files->isNotEmpty())
                            <button type="button" class="btn btn-primary" onclick="window.print()">
                                <i class="bi bi-printer me-1"></i> Print
                            </button>
                        @endif
                    </div>
                </form>
            </div>
        </div>

        @if ($vendor && $date && $files->isEmpty())
            <div class="alert alert-warning">
                Nothing was given to {{ $vendor->name }} on {{ $dateText }}.
            </div>
        @endif

        @if ($files->isNotEmpty())
            <div class="sheet">
                <div class="sheet__head d-flex justify-content-between align-items-start">
                    <div>
                        <h2 class="h4 mb-1">Ac Info</h2>
                        <div>Papers handed over for work</div>
                    </div>
                    <div class="text-end">
                        <div><strong>{{ $vendor->name }}</strong></div>
                        <div>{{ $dateText }}</div>
                    </div>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th style="width: 2.5rem;">#</th>
                            <th>File No.</th>
                            <th>Vehicle</th>
                            <th>Work</th>
                            <th class="remark-col">Remark</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($files as $i => $file)
                            <tr>
                                <td>{{ $i + 1 }}</td>
                                <td>{{ $file->file_no }}</td>
                                <td>{{ $file->registration_no ?: '—' }}</td>
                                <td>{{ implode(', ', $file->works) ?: '—' }}</td>
                                <td class="remark-col"></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <p class="mt-3 mb-0">
                    <strong>{{ $files->count() }}</strong> {{ Str::plural('file', $files->count()) }},
                    <strong>{{ $works }}</strong> {{ Str::plural('work', $works) }} handed over.
                </p>

                <p class="mt-4 mb-0">Received the above papers in good order.</p>

                <div class="sheet__sign">
                    <div>Name</div>
                    <div>Signature</div>
                    <div>Date</div>
                </div>
            </div>
        @endif
    </section>
@endsection
