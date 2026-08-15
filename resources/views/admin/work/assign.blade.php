@extends('admin.layouts.app')

@section('title', 'Give Work to Vendor | Ac Info')

@section('style')
    @include('admin.party._style')
@endsection

@section('content')
    @php
        // The visible half of the date field, worked out the same way
        // partials/_datefield.blade.php does it: only a real Y-m-d becomes text,
        // so a junk value starts the box empty rather than showing 01-01-1970.
        $vendorDate = old('vendor_date', date('Y-m-d'));
        $vendorDateDisplay = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $vendorDate)
            ? date('d-m-Y', strtotime($vendorDate))
            : '';

        // What came back from a failed validation. Ids are cast so they match
        // the values the component renders, and the amounts are cast to an
        // object so an empty set arrives as {} rather than [] — the component
        // reads it by file id.
        $oldVendorId = old('vendor_id') ? (int) old('vendor_id') : '';
        $oldFiles = array_map('intval', (array) old('files', []));
        $oldAmounts = (object) (array) old('amounts', []);
    @endphp

    <div class="pagetitle">
        <h1>Give Work to Vendor</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item"><a href="{{ route('workfile.index') }}">Work Files</a></li>
                <li class="breadcrumb-item active">Give to Vendor</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        @if ($vendors->isEmpty())
            <div class="alert alert-warning">
                Add a <a href="{{ route('party.create', 'vendor') }}" class="alert-link">vendor</a> before giving work out.
            </div>
        @elseif ($files->isEmpty())
            <div class="alert alert-info">
                No files are waiting to be given out. Everything received is either already with a vendor or cancelled.
                <a href="{{ route('workfile.receive') }}" class="alert-link">Receive more files</a>.
            </div>
        @else
            {{--
                Rendered by Vue. The field names are the ones
                WorkFileController::assign() already validates, so the form still
                posts normally and the server still checks every value — which is
                what makes converting a screen on a live ledger safe: only the
                rendering moves.
            --}}
            <div data-vue="vue-give-to-vendor" data-props="{{ json_encode([
                'action' => route('workfile.assign'),
                'csrf' => csrf_token(),
                'cancelUrl' => route('workfile.index'),
                'vendorId' => $oldVendorId,
                'vendorDate' => $vendorDate,
                'vendorDateDisplay' => $vendorDateDisplay,
                'remark' => (string) old('remark'),
                'pickedFiles' => $oldFiles,
                'oldAmounts' => $oldAmounts,
                'vendors' => $vendors->map(fn ($vendor) => [
                    'id' => (int) $vendor->id,
                    'name' => $vendor->name,
                    'mobile' => $vendor->mobile,
                    'current_balance' => (float) $vendor->current_balance,
                ])->values(),
                'files' => $files->map(fn ($file) => [
                    'id' => (int) $file->id,
                    'file_no' => $file->file_no,
                    'received_date' => date('d-m-Y', strtotime($file->received_date)),
                    'work_type' => $file->workType?->name,
                    'description' => $file->description,
                    'customer' => $file->customer?->name,
                    'customer_amount' => (float) $file->customer_amount,
                ])->values(),
            ]) }}"></div>
        @endif
    </section>
@endsection

@section('script')
@endsection
