@extends('admin.layouts.app')

@section('title', 'Return Files to Customer | Ac Info')

@section('style')
    @include('admin.party._style')
@endsection

@section('content')
    <div class="pagetitle">
        <h1>Return Files to Customer</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item"><a href="{{ route('workfile.index') }}">Work Files</a></li>
                <li class="breadcrumb-item active">Return to Customer</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        @if ($files->isEmpty())
            <div class="alert alert-info">
                No files are available to return. Everything received has already been returned or cancelled.
                <a href="{{ route('workfile.receive') }}" class="alert-link">Receive files</a>.
            </div>
        @else
            {{--
                Rendered by Vue. The field names are the ones
                WorkFileController::customerReturn() already validates, so the
                form still posts normally and the server still checks every
                value — which is what makes converting a screen on a live ledger
                safe: only the rendering moves.

                Old input is cast so that a bounced batch always arrives as a
                JSON array and a JSON object, whatever keys it happens to carry.
            --}}
            <div data-vue="vue-customer-return" data-props="{{ \App\Support\VueProps::encode([
                'action' => route('workfile.customerreturn'),
                'csrf' => csrf_token(),
                'cancelUrl' => route('workfile.index'),
                'returnedOn' => old('returned_on', date('Y-m-d')),
                'oldRemark' => old('remark', ''),
                'oldFiles' => array_values((array) old('files', [])),
                'oldAmounts' => (object) old('amounts', []),
                'files' => $files->map(fn ($file) => [
                    'id' => $file->id,
                    'file_no' => $file->file_no,
                    'received_date' => date('d-m-Y', strtotime($file->received_date)),
                    'work_type' => $file->workType?->name,
                    'description' => $file->description,
                    'customer' => $file->customer?->name,
                    'status' => $file->status,
                    'status_label' => $file->statusLabel(),
                    'customer_amount' => (float) $file->customer_amount,
                ])->values(),
            ]) }}"></div>
        @endif
    </section>
@endsection

@section('script')
@endsection
