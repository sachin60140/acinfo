<style>
    /* Chrome, Safari, Edge, Opera */
    input::-webkit-outer-spin-button,
    input::-webkit-inner-spin-button {
        -webkit-appearance: none;
        margin: 0;
    }

    .party-page .form-label {
        color: #334155;
        font-size: 0.86rem;
        font-weight: 700;
        margin-bottom: 0.4rem;
    }

    .party-page .form-control,
    .party-page .form-select,
    .party-page .input-group-text {
        border-color: #d9e2ef;
        border-radius: 7px;
        min-height: 44px;
    }

    .party-page .input-group > .form-control,
    .party-page .input-group > .form-select {
        border-top-left-radius: 0;
        border-bottom-left-radius: 0;
    }

    .party-page .input-group-text {
        background: #f8fafc;
        color: #64748b;
        min-width: 44px;
        justify-content: center;
    }

    .party-page .required-mark {
        color: #dc3545;
    }

    .party-table {
        font-size: 13px;
    }

    .party-table td,
    .party-table th {
        font-variant-numeric: tabular-nums;
        vertical-align: middle;
    }

    .wa-link {
        color: #25d366;
        text-decoration: none;
    }

    .wa-link:hover {
        color: #128c7e;
    }

    .dr {
        color: #198754;
    }

    .cr {
        color: #dc3545;
    }

    .side-toggle {
        display: flex;
        gap: 0.5rem;
    }

    .side-toggle input {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }

    .side-toggle label {
        border: 1px solid #d9e2ef;
        border-radius: 7px;
        cursor: pointer;
        flex: 1;
        font-weight: 700;
        padding: 0.55rem 0.75rem;
        text-align: center;
    }

    .side-toggle input:checked + label.dr-option {
        background: #eaf7f0;
        border-color: #198754;
        color: #198754;
    }

    .side-toggle input:checked + label.cr-option {
        background: #fdecee;
        border-color: #dc3545;
        color: #dc3545;
    }

    .side-hint {
        color: #64748b;
        font-size: 0.78rem;
        margin-top: 0.4rem;
    }
</style>
