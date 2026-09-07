@extends('customer.layouts.app')

@section('title', 'File ' . $fileNo . ' | Ac Info')

@section('style')
    @include('admin.layouts._statement-style')

    <style>
        .file-facts {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr));
            gap: 0.75rem 1.5rem;
        }

        .file-facts .label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #6c757d;
            display: block;
        }

        .file-facts .value {
            font-size: 1rem;
            font-weight: 600;
            color: #2f3d4a;
        }

        .file-remark {
            background: #fbfcfe;
            border: 1px solid #e5e9f2;
            border-left: 3px solid #4154f1;
            border-radius: 4px;
            padding: 0.6rem 0.85rem;
        }

        .file-remark .label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #6c757d;
            display: block;
            margin-bottom: 0.15rem;
        }

        /* The history. A rail down the left with a dot per entry, coloured the
           same way the status badges are. */
        .tl {
            list-style: none;
            margin: 0;
            padding: 0 0 0 1.35rem;
            border-left: 2px solid #e5e9f2;
        }

        .tl__item {
            position: relative;
            padding: 0 0 1.1rem 0;
        }

        .tl__item:last-child {
            padding-bottom: 0;
        }

        .tl__dot {
            position: absolute;
            left: -1.78rem;
            top: 0.3rem;
            width: 0.65rem;
            height: 0.65rem;
            border-radius: 50%;
            background: #cbd3e2;
            box-shadow: 0 0 0 3px #fff;
        }

        .tl__dot[data-state="approved"] { background: #198754; }
        .tl__dot[data-state="cancelled"] { background: #dc3545; }
        .tl__dot[data-state="needs-you"] { background: #d97706; }
        .tl__dot[data-state="moving"] { background: #0369a1; }

        .tl__head {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.5rem;
        }

        .tl__work {
            font-size: 0.8rem;
            font-weight: 600;
            color: #2f3d4a;
        }

        .tl__when {
            font-size: 0.75rem;
            color: #6c757d;
        }

        .tl__remark {
            margin: 0.3rem 0 0;
            font-size: 0.9rem;
            color: #3d4560;
        }

        /* Ctrl+P on this page should produce something worth keeping beside the
           papers: the file, its works and the approval dates. The navigation,
           the grid's controls and the buttons are screen furniture. */
        @media print {
            #header,
            #sidebar,
            #footer,
            .breadcrumb,
            .back-to-top,
            .btn,
            .grid__bar,
            .grid__pages {
                display: none !important;
            }

            #main {
                margin: 0 !important;
                padding: 0 !important;
            }

            .card {
                border: 0 !important;
                box-shadow: none !important;
            }
        }
    </style>
@endsection

@section('content')

    <div class="pagetitle">
        <h1>File {{ $fileNo }}</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ route('customer.dashboard') }}">Home</a></li>
                <li class="breadcrumb-item"><a href="{{ $filesUrl }}">My Files</a></li>
                <li class="breadcrumb-item active">{{ $fileNo }}</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section">
        <div class="row">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-body pt-4">

                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                            <div>
                                <h5 class="card-title p-0 m-0">
                                    {{ $registrationNo ?: 'No registration number' }}
                                </h5>
                                @if ($description)
                                    <div class="statement-period">{{ $description }}</div>
                                @endif
                            </div>
                            <span class="ui-badge" data-state="{{ $statusTone }}">{{ $status }}</span>
                        </div>

                        <div class="file-facts mb-3">
                            <div>
                                <span class="label">Received</span>
                                <span class="value">{{ $received }}</span>
                            </div>
                            <div>
                                <span class="label">{{ Str::plural('Work', $workCount) }}</span>
                                <span class="value">{{ $workCount }}</span>
                            </div>
                            <div>
                                <span class="label">Amount</span>
                                <span class="value">{{ number_format((float) $charged, 2, '.', ',') }}</span>
                            </div>
                            @if ($returnedOn)
                                <div>
                                    <span class="label">Returned to You</span>
                                    <span class="value">{{ $returnedOn }}</span>
                                </div>
                            @endif
                        </div>

                        @if ($remarks)
                            <div class="file-remark mb-3">
                                <span class="label">Remarks</span>
                                {{ $remarks }}
                            </div>
                        @endif

                        @if ($fileScreenshot)
                            {{-- A file received before works were priced one by one
                                 carries its approval on the folder rather than on a
                                 work, so there is nowhere in the table below to hang
                                 it. Opens in a tab of its own: the grid's viewer
                                 belongs to the grid. --}}
                            <a href="{{ $fileScreenshot }}" target="_blank" rel="noopener"
                               class="btn btn-outline-primary btn-sm mb-3">
                                <i class="bi bi-image"></i> View Approval
                            </a>
                        @endif

                        {{--
                            One row per work, with its own status, the day it came
                            through and the approval behind it. Rendered by the same
                            grid as everything else, so the approval opens over the
                            page rather than replacing it.
                        --}}
                        <div data-vue="vue-customer-file" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>

                    </div>
                </div>
            </div>
        </div>

        @if (count($timeline))
            <div class="row">
                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-body pt-4">
                            <h5 class="card-title p-0 mb-3">History</h5>

                            {{-- Oldest first, so it reads as the story of the file
                                 rather than as a list of alerts. Every movement is
                                 here; what is filtered is the wording, not the
                                 entry — see WorkFileModel::customerTimeline. --}}
                            <ol class="tl">
                                @foreach ($timeline as $entry)
                                    <li class="tl__item">
                                        <span class="tl__dot" data-state="{{ $entry['tone'] }}"></span>
                                        <div class="tl__body">
                                            <div class="tl__head">
                                                <span class="ui-badge" data-state="{{ $entry['tone'] }}">{{ $entry['to'] }}</span>
                                                @if ($entry['work_type'])
                                                    <span class="tl__work">{{ $entry['work_type'] }}</span>
                                                @endif
                                                <span class="tl__when">{{ $entry['date'] }} · {{ $entry['time'] }}</span>
                                            </div>

                                            @if ($entry['remark'])
                                                <p class="tl__remark">{{ $entry['remark'] }}</p>
                                            @endif
                                        </div>
                                    </li>
                                @endforeach
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </section>
@endsection
