{{--
    A date field: a dd-mm-yyyy box with a calendar popup, backed by a hidden
    Y-m-d value for the server. See public/assets/js/datepicker.js.

    @param string      $name      hidden input name, e.g. 'txn_date'
    @param string|null $value     current value as Y-m-d (or empty)
    @param bool        $required  default false
    @param string|null $max       latest selectable day, Y-m-d
    @param string|null $min       earliest selectable day, Y-m-d
    @param string|null $id        defaults to $name
--}}
@php
    $dfId = $id ?? $name;
    $dfValue = $value ?? '';
    $dfRequired = $required ?? false;
    $dfMax = $max ?? null;
    $dfMin = $min ?? null;

    // Only a real Y-m-d becomes visible text; anything else starts the field empty
    // rather than showing 01-01-1970, which is what strtotime() would hand back.
    $dfDisplay = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $dfValue)
        ? date('d-m-Y', strtotime($dfValue))
        : '';
@endphp

<div class="input-group">
    <span class="input-group-text"><i class="bi bi-calendar3"></i></span>
    <input type="text"
           class="form-control js-datefield"
           id="{{ $dfId }}_display"
           value="{{ $dfDisplay }}"
           data-target="{{ $dfId }}"
           @if ($dfMax) data-max="{{ $dfMax }}" @endif
           @if ($dfMin) data-min="{{ $dfMin }}" @endif
           placeholder="dd-mm-yyyy"
           inputmode="numeric"
           maxlength="10"
           autocomplete="off"
           @if ($dfRequired) required @endif>
    <input type="hidden" id="{{ $dfId }}" name="{{ $name }}" value="{{ $dfValue }}">
</div>
