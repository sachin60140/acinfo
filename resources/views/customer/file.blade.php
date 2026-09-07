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
    </section>
@endsection
