<style>
    .statement-period {
        color: #6c757d;
        font-size: 0.85rem;
        margin-top: 0.2rem;
    }

    .quick-ranges a {
        display: inline-block;
        font-size: 0.8rem;
        padding: 0.25rem 0.65rem;
        margin-left: 0.25rem;
        border: 1px solid #dee2e6;
        border-radius: 4px;
        color: #4154f1;
        text-decoration: none;
    }

    .quick-ranges a:hover {
        background: #f6f9ff;
        border-color: #4154f1;
    }

    .statement-filter .form-label {
        font-size: 0.78rem;
        font-weight: 600;
        color: #334155;
        margin-bottom: 0.25rem;
    }

    .statement-filter .form-control {
        min-height: 40px;
    }

    .statement-summary {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
        gap: 0.75rem;
    }

    .statement-summary .stat {
        border: 1px solid #e5e9f2;
        border-radius: 6px;
        padding: 0.65rem 0.85rem;
        background: #fbfcfe;
        display: flex;
        flex-direction: column;
        gap: 0.15rem;
    }

    .statement-summary .stat.closing {
        background: #f6f9ff;
        border-color: #4154f1;
    }

    .statement-summary .label {
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #6c757d;
    }

    .statement-summary .value {
        font-size: 1.05rem;
        font-weight: 700;
        font-variant-numeric: tabular-nums;
        color: #2f3d4a;
    }

    .statement-table {
        font-size: 13px;
    }

    .statement-table td,
    .statement-table th {
        font-variant-numeric: tabular-nums;
        vertical-align: middle;
    }

    .statement-table tfoot th {
        border-top: 2px solid #adb5bd;
        background: #f8f9fa;
    }

    .pos {
        color: #198754;
    }

    .neg {
        color: #dc3545;
    }

    @media print {

        .quick-ranges,
        .statement-filter,
        .dt-buttons,
        .dataTables_filter,
        .dataTables_paginate,
        .dataTables_info,
        .dataTables_length {
            display: none !important;
        }
    }
</style>
