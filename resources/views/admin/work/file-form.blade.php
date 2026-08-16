@extends('admin.layouts.app')

@section('title', ($file ? 'Edit File' : 'Receive File') . ' | Ac Info')

@section('style')
    @include('admin.party._style')
@endsection

@section('content')
    @php
        $isEdit = (bool) $file;
        // Only ever reached for an existing file — receiving is its own screen now.
        $action = $isEdit ? route('workfile.edit', $file->id) : route('workfile.receive');
        $blocked = $workTypes->isEmpty() || $customers->isEmpty();

        $work = \App\Models\WorkFileModel::class;
    @endphp

    <div class="pagetitle">
        <h1>{{ $isEdit ? 'Edit File ' . $file->file_no : 'Receive File' }}</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item"><a href="{{ route('workfile.index') }}">Work Files</a></li>
                <li class="breadcrumb-item active">{{ $isEdit ? 'Edit' : 'Receive' }}</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        @if ($blocked)
            <div class="alert alert-warning">
                Before a file can be received you need
                @if ($workTypes->isEmpty())
                    at least one <a href="{{ route('worktype.index') }}" class="alert-link">work type</a>
                @endif
                @if ($workTypes->isEmpty() && $customers->isEmpty())
                    and
                @endif
                @if ($customers->isEmpty())
                    at least one <a href="{{ route('party.create', 'customer') }}" class="alert-link">customer</a>
                @endif
                .
            </div>
        @else
            {{--
                Rendered by Vue — the form, the ledger-effect panel beside it and
                the history below that. The field names are the ones
                WorkFileController::receive() and ::edit() already validate, so the
                form still posts normally and the server still checks every value —
                which is what makes converting a screen on a live ledger safe: only
                the rendering moves.
            --}}
            <div data-vue="vue-file-form" data-props="{{ \App\Support\VueProps::encode([
                'action' => $action,
                'csrf' => csrf_token(),
                'indexUrl' => route('workfile.index'),
                'isEdit' => $isEdit,
                'statuses' => $statuses,

                /*
                 * Option text is built here rather than in the component: what a
                 * work type or a party is called on screen is the server's to
                 * decide, and the rate and balance travel beside the label because
                 * the panel needs them as numbers.
                 */
                'workTypes' => $workTypes->map(fn ($wt) => [
                    'id' => $wt->id,
                    'label' => $wt->name
                        .($wt->default_rate !== null ? ' — '.number_format((float) $wt->default_rate, 2, '.', ',') : '')
                        .($wt->is_active ? '' : ' (retired)'),
                    'rate' => $wt->default_rate,
                ])->values(),
                'customers' => $customers->map(fn ($c) => [
                    'id' => $c->id,
                    'label' => $c->name.' ('.$c->mobile.')'.($c->is_active ? '' : ' — inactive'),
                    'balance' => (float) $c->current_balance,
                ])->values(),
                'vendors' => $vendors->map(fn ($v) => [
                    'id' => $v->id,
                    'label' => $v->name.' ('.$v->mobile.')'.($v->is_active ? '' : ' — inactive'),
                    'balance' => (float) $v->current_balance,
                ])->values(),

                'values' => [
                    'file_no' => old('file_no', $isEdit ? $file->file_no : ''),
                    'status' => old('status', $isEdit ? $file->status : 'pending'),
                    'returned_amount' => old('returned_amount', $isEdit ? $file->returned_amount : ''),
                    'work_type_id' => old('work_type_id', $isEdit ? $file->work_type_id : ''),
                    'registration_no' => old('registration_no', $isEdit ? $file->registration_no : ''),
                    'description' => old('description', $isEdit ? $file->description : ''),
                    'customer_id' => old('customer_id', $isEdit ? $file->customer_id : ''),
                    'customer_amount' => old('customer_amount', $isEdit ? $file->customer_amount : ''),
                    'vendor_id' => old('vendor_id', $isEdit ? $file->vendor_id : ''),
                    'vendor_amount' => old('vendor_amount', $isEdit ? $file->vendor_amount : ''),
                    'remarks' => old('remarks', $isEdit ? $file->remarks : ''),
                ],

                // Rendered here rather than rebuilt in the component: both date
                // boxes keep one markup contract, the one assets/js/datepicker.js
                // binds by class.
                'receivedDateField' => view('partials._datefield', [
                    'name' => 'received_date',
                    'value' => old('received_date', $isEdit ? $file->received_date : date('Y-m-d')),
                    'required' => true,
                ])->render(),
                'vendorDateField' => view('partials._datefield', [
                    'name' => 'vendor_date',
                    'value' => old('vendor_date', $isEdit ? $file->vendor_date : ''),
                ])->render(),

                // A blank refund gives the whole charge back, so the box shows what
                // that would be rather than a bare zero.
                'refundPlaceholder' => $isEdit
                    ? number_format((float) $file->customer_amount, 2, '.', '')
                    : '0.00',
                'screenshotUrl' => $isEdit && $file->approval_screenshot ? $file->screenshotUrl() : '',

                /*
                 * What this file already contributes to each party's balance.
                 *
                 * The balance carried on each option is the party's current one,
                 * which for a file being edited already includes that file's own
                 * entries. Adding the amount on top counted it twice, so the panel
                 * promised a balance the statement would never show. Discount the
                 * existing effect first — but only while the party is still the one
                 * those entries were posted against.
                 */
                'alreadyPosted' => [
                    'customerId' => $isEdit ? $file->customer_id : null,
                    'vendorId' => $isEdit ? $file->vendor_id : null,
                    'customer' => $isEdit ? $work::netCustomer($file->status, $file->customer_amount, $file->returned_amount) : 0,
                    'vendor' => $isEdit ? $work::netVendor($file->status, $file->vendor_amount, $file->vendor_returned_on !== null, $file->vendor_returned_amount) : 0,
                ],

                // Flattened here because the component cannot call a model's
                // methods; the labels and the date formatting stay the server's.
                'timeline' => $isEdit
                    ? collect($timeline)->map(fn ($entry) => [
                        'id' => $entry->id,
                        'kind' => $entry->isOpening() ? 'opening' : ($entry->isNoteOnly() ? 'note' : 'move'),
                        'from' => $entry->fromLabel(),
                        'to' => $entry->toLabel(),
                        'remark' => $entry->remark,
                        'date' => date('d-m-Y', strtotime($entry->created_at)),
                        'time' => date('h:i A', strtotime($entry->created_at)),
                        'user' => $entry->user?->name,
                    ])->values()
                    : [],

                // The three statuses the form changes shape for, named by the model
                // rather than spelled out again here.
                'returnedKey' => $work::RETURNED,
                'approvedKey' => $work::APPROVED,
                'cancelledKey' => $work::CANCELLED,

                'errors' => (object) array_map(fn ($messages) => $messages[0], $errors->messages()),
            ]) }}"></div>
        @endif
    </section>
@endsection

{{-- The ledger-effect panel, the status-driven boxes and the default-rate fill
     all moved into the component. Both date boxes still parse and validate
     themselves and keep their hidden Y-m-d values in step — see
     assets/js/datepicker.js. --}}
@section('script')
@endsection
