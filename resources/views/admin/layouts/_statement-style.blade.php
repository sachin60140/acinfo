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

    /* A qualifier under a figure, for when the figure covers fewer rows than
       the ones above it. */
    .stat-note {
        color: #64748b;
        display: block;
        font-size: 0.68rem;
        font-weight: 600;
        line-height: 1.3;
        margin-top: 0.15rem;
    }

    .neg {
        color: #dc3545;
    }

    /* The grid prints itself into a clean window, but Ctrl+P on the page still
       has to produce something worth handing over — so the controls that only
       make sense on screen come off the paper. The old .dataTables_* selectors
       went with DataTables; the grid's own controls are .grid__bar and
       .grid__pages. */
    @media print {

        .quick-ranges,
        .statement-filter,
        .grid__bar,
        .grid__pages {
            display: none !important;
        }
    }
</style>
