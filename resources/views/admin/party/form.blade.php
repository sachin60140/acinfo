@extends('admin.layouts.app')

@section('title', ($party ? 'Edit' : 'Add') . ' ' . $label . ' | Ac Info')

@section('style')
    @include('admin.party._style')
@endsection

@section('content')
    @php
        $isEdit = (bool) $party;
        $action = $isEdit ? route('party.edit', $party->id) : route('party.create', $type);
        // An unchecked checkbox posts nothing, so old('is_active') is absent both
        // when the form is fresh and when the user deliberately cleared it. Only
        // the presence of validation errors tells the two apart.
        $activeChecked = $isEdit ? ($errors->any() ? old('is_active') : $party->is_active) : true;
    @endphp

    <div class="pagetitle">
        <h1>{{ $isEdit ? 'Edit' : 'Add' }} {{ $label }}</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item"><a href="{{ route('party.index', $type) }}">{{ $label }}s</a></li>
                <li class="breadcrumb-item active">{{ $isEdit ? 'Edit' : 'Add' }}</li>
            </ol>
        </nav>
    </div><!-- End Page Title -->

    <section class="section dashboard party-page">
        @include('admin.party._alerts')

        <div class="row">
            <div class="col-lg-9">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
                            <h5 class="card-title mb-0">{{ $label }} Details</h5>
                            <a href="{{ route('party.index', $type) }}" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-list-ul me-1"></i> All {{ $label }}s
                            </a>
                        </div>

                        <form class="row g-3" id="party_form" action="{{ $action }}" method="POST">
                            @csrf

                            <div class="col-md-6">
                                <label for="name" class="form-label">{{ $label }} Name <span class="required-mark">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                                    <input type="text" class="form-control" id="name" name="name" value="{{ old('name', $isEdit ? $party->name : '') }}" required autofocus>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="mobile" class="form-label">Mobile Number <span class="required-mark">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">+91</span>
                                    <input type="text" class="form-control" id="mobile" name="mobile" value="{{ old('mobile', $isEdit ? $party->mobile : '') }}" inputmode="numeric" pattern="\d{10}" maxlength="10" placeholder="10 digit number" required>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="whatsapp" class="form-label">WhatsApp Number</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-whatsapp"></i></span>
                                    <input type="text" class="form-control" id="whatsapp" name="whatsapp" value="{{ old('whatsapp', $isEdit ? $party->whatsapp : '') }}" inputmode="numeric" pattern="\d{10}" maxlength="10" placeholder="10 digit number">
                                </div>
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" id="same_as_mobile">
                                    <label class="form-check-label" for="same_as_mobile" style="font-size: 0.85rem;">Same as mobile number</label>
                                </div>
                                <div class="side-hint">Leave blank if WhatsApp is on the mobile number above.</div>
                            </div>

                            @if ($isEdit)
                                <div class="col-md-6">
                                    <label class="form-label">Status</label>
                                    <div class="form-check form-switch mt-2">
                                        <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1" {{ $activeChecked ? 'checked' : '' }}>
                                        <label class="form-check-label" for="is_active">Active</label>
                                    </div>
                                    <div class="side-hint">Inactive {{ strtolower($label) }}s stay in the list and keep their statement, but are hidden from the entry form.</div>
                                </div>
                            @endif

                            <div class="col-12">
                                <label for="address" class="form-label">Address</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-geo-alt"></i></span>
                                    <input type="text" class="form-control" id="address" name="address" value="{{ old('address', $isEdit ? $party->address : '') }}" placeholder="Shop / street, city">
                                </div>
                            </div>

                            @unless ($isEdit)
                                <div class="col-12"><hr class="mt-3 mb-0"></div>

                                <div class="col-12">
                                    <h6 class="mb-0">Opening Balance <span class="text-muted fw-normal" style="font-size: 0.85rem;">(optional)</span></h6>
                                    <div class="side-hint">
                                        The balance already outstanding before this ledger starts. It is saved as the
                                        first entry in the statement, so it can be corrected later like any other entry.
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <label for="opening_balance" class="form-label">Amount</label>
                                    <div class="input-group">
                                        <span class="input-group-text">INR</span>
                                        <input type="number" min="0" step="0.01" class="form-control" id="opening_balance" name="opening_balance" value="{{ old('opening_balance') }}" placeholder="0.00">
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Side</label>
                                    <div class="side-toggle">
                                        <input type="radio" name="opening_type" id="opening_debit" value="debit" {{ old('opening_type', $defaultOpeningType) === 'debit' ? 'checked' : '' }}>
                                        <label for="opening_debit" class="dr-option">Debit (Dr)</label>

                                        <input type="radio" name="opening_type" id="opening_credit" value="credit" {{ old('opening_type', $defaultOpeningType) === 'credit' ? 'checked' : '' }}>
                                        <label for="opening_credit" class="cr-option">Credit (Cr)</label>
                                    </div>
                                    <div class="side-hint">
                                        Dr = {{ strtolower($label) }} owes you &nbsp;·&nbsp; Cr = you owe {{ strtolower($label) }}
                                    </div>
                                </div>

                                <div class="col-md-4">
                                    <label for="opening_date_display" class="form-label">As On Date</label>
                                    @include('partials._datefield', [
                                        'name' => 'opening_date',
                                        'value' => old('opening_date', date('Y-m-d')),
                                    ])
                                </div>
                            @endunless

                            <div class="col-12 d-flex flex-wrap gap-2 justify-content-end mt-3">
                                <a href="{{ route('party.index', $type) }}" class="btn btn-outline-secondary">Cancel</a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check2-circle me-1"></i> {{ $isEdit ? 'Update' : 'Save' }} {{ $label }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

@section('script')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const mobile = document.getElementById('mobile');
            const whatsapp = document.getElementById('whatsapp');
            const sameAsMobile = document.getElementById('same_as_mobile');

            const digitsOnly = function (field) {
                field.addEventListener('input', function () {
                    field.value = field.value.replace(/\D/g, '').slice(0, 10);
                });
            };

            digitsOnly(mobile);
            digitsOnly(whatsapp);

            const syncWhatsapp = function () {
                if (sameAsMobile.checked) {
                    whatsapp.value = mobile.value;
                }
            };

            sameAsMobile.addEventListener('change', function () {
                whatsapp.readOnly = sameAsMobile.checked;
                syncWhatsapp();
            });

            mobile.addEventListener('input', syncWhatsapp);

            // Reflect the state the form came back with — on a validation error the
            // browser restores the checkbox but not the readonly styling.
            if (mobile.value && whatsapp.value && mobile.value === whatsapp.value) {
                sameAsMobile.checked = true;
                whatsapp.readOnly = true;
            }

            // The opening-date field parses and validates itself, and keeps its own
            // hidden Y-m-d value in step — see assets/js/datepicker.js.
        });
    </script>
@endsection
