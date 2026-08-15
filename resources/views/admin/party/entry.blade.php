@extends('admin.layouts.app')

@section('title', $label . ' Entry | Ac Info')

@section('style')
    @include('admin.party._style')
@endsection

@section('content')
    <div class="pagetitle">
        <h1>{{ $label }} Entry</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item"><a href="{{ route('party.index', $type) }}">{{ $label }}s</a></li>
                <li class="breadcrumb-item active">Entry</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        @if ($partylist->isEmpty())
            <div class="card">
                <div class="card-body pt-4">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                        <h5 class="card-title mb-0">New Ledger Entry</h5>
                        <a href="{{ route('party.index', $type) }}" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-list-ul me-1"></i> All {{ $label }}s
                        </a>
                    </div>

                    <div class="alert alert-warning mb-0">
                        No active {{ strtolower($label) }}s yet.
                        <a href="{{ route('party.create', $type) }}" class="alert-link">Add one first</a>.
                    </div>
                </div>
            </div>
        @else
            {{--
                Rendered by Vue. The field names are the ones
                PartyController::entry() already validates, so the form still
                posts normally and the server still checks every value — which is
                what makes converting a screen on a live ledger safe: only the
                rendering moves.
            --}}
            <div data-vue="vue-party-entry" data-props="{{ json_encode([
                'action' => route('party.entry', $type),
                'csrf' => csrf_token(),
                'label' => $label,
                'indexUrl' => route('party.index', $type),
                // __ID__ is swapped client-side rather than the URL being built
                // by concatenation, so it always matches what the route
                // actually generates.
                'statementUrl' => route('party.statement', ['id' => '__ID__']),
                'sideHint' => $type === 'customer'
                    ? 'Debit = sale / amount charged · Credit = payment received'
                    : 'Debit = payment made to vendor · Credit = purchase / bill received',
                'paymentModes' => $paymentModes,
                'parties' => $partylist->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'mobile' => $p->mobile,
                    'current_balance' => (float) $p->current_balance,
                ])->values(),
                // The date field stays the shared partial rather than being
                // rebuilt in Vue: assets/js/datepicker.js owns that markup, and
                // dd-mm-yyyy everywhere is the whole reason it exists.
                'dateField' => view('partials._datefield', [
                    'name' => 'txn_date',
                    'value' => old('txn_date', date('Y-m-d')),
                    'required' => true,
                ])->render(),
                // What Reset puts back, which is what the page loaded with —
                // including a rejected submission's own values.
                'initial' => [
                    'party_id' => (string) old('party_id'),
                    'entry_type' => old('entry_type', $defaultEntryType),
                    'amount' => (string) old('amount'),
                    'payment_mode' => (string) old('payment_mode'),
                    'ref_no' => (string) old('ref_no'),
                    'particular' => (string) old('particular'),
                ],
            ]) }}"></div>
        @endif
    </section>
@endsection

@section('script')
@endsection
