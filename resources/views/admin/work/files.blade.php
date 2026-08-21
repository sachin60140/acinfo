@extends('admin.layouts.app')

@section('title', 'Work Files | Ac Info')

@section('style')
    @include('admin.layouts._statement-style')
    @include('admin.party._style')

@endsection

@section('content')

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
                                    {{ $fileCount }} {{ Str::plural('file', $fileCount) }}
                                    @if ($closedCount)
                                        &nbsp;&middot;&nbsp; {{ $closedCount }} cancelled or returned, not counted as billed
                                    @endif
                                    @if ($statusLabel)
                                        &nbsp;&middot;&nbsp; Status: {{ $statusLabel }}
                                    @endif
                                    @if ($pendingLabel)
                                        &nbsp;&middot;&nbsp; {{ $pendingLabel }}
                                    @endif
                                </div>

                                {{--
                                    Files waiting on a price.

                                    A file can be taken in and given to a vendor
                                    before either figure is agreed — the ledgers
                                    stay quiet until there is something to post,
                                    which is right, and is also why an unpriced
                                    file is invisible until someone looks. Each
                                    chip opens exactly the files it counted.
                                --}}
                                @if ($pendingCounts['any'])
                                    <div class="filter-row mt-2">
                                        <span class="filter-key">Awaiting price</span>
                                        @foreach ($pendingLabels as $key => $text)
                                            @if ($pendingCounts[$key])
                                                <a href="{{ $pendingUrls[$key] }}"
                                                   class="chip {{ $pending === $key ? 'active' : '' }}">
                                                    {{ $text }} <span class="n">{{ $pendingCounts[$key] }}</span>
                                                </a>
                                            @endif
                                        @endforeach
                                        @if ($pending)
                                            <a href="{{ $base }}" class="chip">Clear</a>
                                        @endif
                                    </div>
                                @endif
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
                                    <option value="open" {{ $status === 'open' ? 'selected' : '' }}>Work in hand</option>
                                    @foreach (\App\Models\WorkFileModel::STATUSES as $key => $text)
                                        <option value="{{ $key }}" {{ $status === $key ? 'selected' : '' }}>{{ $text }}</option>
                                    @endforeach

                                </select>
                            </div>
                            <div class="col-sm-auto">
                                <label for="from_display" class="form-label">From</label>
                                @include('partials._datefield', ['name' => 'from', 'value' => $from, 'max' => $maxDate])
                            </div>
                            <div class="col-sm-auto">
                                <label for="to_display" class="form-label">To</label>
                                @include('partials._datefield', ['name' => 'to', 'value' => $to, 'max' => $maxDate])
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
                                {{--
                                    A file with a rate still to be agreed has no margin
                                    yet, so it is not in this figure. Said out loud,
                                    because a total that quietly covers fewer files than
                                    the two beside it reads as the whole answer.
                                --}}
                                @if ($unpricedCount)
                                    <span class="stat-note">on {{ $fileCount - $unpricedCount }} of {{ $fileCount }} files &mdash; {{ $unpricedCount }} awaiting a price</span>
                                @endif
                            </div>
                        </div>

                        {{--
                            Rendered by Vue. The grid carries the search, the
                            paging and every export the CDN stack used to, from the
                            rows the controller already sent — no second query, and
                            one definition of how a figure is written on screen and
                            in the file that comes out of it.
                        --}}
                        <div data-vue="vue-files-list" data-props="{{ \App\Support\VueProps::encode($screenProps) }}"></div>

                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
