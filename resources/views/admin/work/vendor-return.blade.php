@extends('admin.layouts.app')

@section('title', 'Papers Returned by Vendor | Ac Info')

@section('style')
    @include('admin.party._style')
@endsection

@section('content')
    <div class="pagetitle">
        <h1>Papers Returned by Vendor</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item"><a href="{{ route('workfile.index') }}">Work Files</a></li>
                <li class="breadcrumb-item active">Returned by Vendor</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        @if ($files->isEmpty())
            <div class="alert alert-info">
                No files are out with a vendor at the moment.
                <a href="{{ route('workfile.assign') }}" class="alert-link">Give work to a vendor</a> first.
            </div>
        @else
            @php
                // The same guard partials/_datefield uses: only a real Y-m-d
                // becomes visible text, so a bad old() value starts the field
                // empty rather than showing 01-01-1970.
                $returnedOn = old('returned_on', date('Y-m-d'));
                $returnedOnDisplay = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $returnedOn)
                    ? date('d-m-Y', strtotime($returnedOn))
                    : '';
            @endphp

            {{--
                Rendered by Vue. The field names are the ones
                WorkFileController::vendorReturn() already validates, so the form
                still posts normally and the server still checks every value —
                which is what makes converting a screen on a live ledger safe:
                only the rendering moves.

                The date box keeps the markup contract in
                public/assets/js/datepicker.js, so the calendar still binds to it.
            --}}
            <div data-vue="vue-vendor-return" data-props="{{ json_encode([
                'action' => route('workfile.vendorreturn'),
                'csrf' => csrf_token(),
                'cancelUrl' => route('workfile.index'),
                'returnedOn' => $returnedOn,
                'returnedOnDisplay' => $returnedOnDisplay,
                'remark' => old('remark', ''),
                // A bounced batch comes back ticked and filled in as it was sent.
                'pickedIds' => array_map('intval', (array) old('files', [])),
                'oldAmounts' => (object) (array) old('amounts', []),
                'files' => $files->map(fn ($file) => [
                    'id' => $file->id,
                    'file_no' => $file->file_no,
                    'vendor' => $file->vendor?->name,
                    'vendor_date' => $file->vendor_date ? date('d-m-Y', strtotime($file->vendor_date)) : null,
                    'work_type' => $file->workType?->name,
                    'description' => $file->description,
                    'customer' => $file->customer?->name,
                    'vendor_amount' => $file->vendor_amount === null ? null : (float) $file->vendor_amount,
                ])->values(),
            ]) }}"></div>
        @endif
    </section>
@endsection

@section('script')
@endsection
