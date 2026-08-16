/*
 * What ends up in an exported file.
 *
 * These exports were asked for by name — File No., Received, Work Type,
 * Details, the counterparty, Status, Remarks — and the format changed
 * underneath them when the grid replaced DataTables. A column silently missing
 * from a spreadsheet is invisible from inside the application: the screen looks
 * right, the file is opened somewhere else, and the discrepancy surfaces in a
 * meeting rather than to anyone who could fix it.
 *
 * So the decisions are asserted here: which columns leave, which rows leave,
 * and what a figure looks like when it arrives.
 */
import { describe, expect, test } from 'vitest';
import { balance, money } from './money';
import {
    cellValue,
    csvCell,
    exportColumns,
    exportHeader,
    exportRows,
    exportValues,
    fileName,
    toClipboard,
    toCsv,
} from './exports';

const formatters = { money, balance };

/** The work report's columns, as the controller builds them. */
const REPORT = [
    { key: 'party_id', label: 'Customer Id', hidden: true },
    { key: 'party_name', label: 'Customer' },
    { key: 'file_no', label: 'File No.' },
    { key: 'registration_no', label: 'Vehicle' },
    { key: 'received', label: 'Received', sortable: false },
    { key: 'work_type', label: 'Work Type' },
    { key: 'description', label: 'Details' },
    { key: 'counterparty', label: 'Given To' },
    { key: 'status', label: 'Status', type: 'badge' },
    { key: 'remark', label: 'Remarks' },
    { key: 'billed', label: 'Billed', type: 'money' },
    { key: 'cost', label: 'Cost', type: 'money' },
    { key: 'margin', label: 'Margin', type: 'money' },
];

const ROW = {
    party_id: 7,
    party_name: 'Arman Qadri',
    file_no: 'F-00001',
    registration_no: 'BR01DD1100',
    received: '16-08-2026',
    work_type: 'HPT+TR',
    description: 'BR260712V6889938',
    counterparty: 'In-house',
    status: 'In Office',
    remark: null,
    billed: 5000,
    cost: null,
    margin: 5000,
};

describe('which columns reach a file', () => {
    test('the seven required columns are exported, by name', () => {
        const header = exportHeader(REPORT);

        for (const required of ['File No.', 'Received', 'Work Type', 'Details', 'Given To', 'Status', 'Remarks']) {
            expect(header).toContain(required);
        }
    });

    /*
     * On screen the rows are banded under a heading naming the party. A
     * spreadsheet has no bands, so without this column every row arrives
     * detached from the customer it belongs to — and a customer-wise export
     * whose only party column is the vendor reads as a vendor report.
     */
    test('the party the report is grouped by is exported', () => {
        expect(exportHeader(REPORT)).toContain('Customer');
    });

    test('the money is exported', () => {
        const header = exportHeader(REPORT);

        expect(header).toContain('Billed');
        expect(header).toContain('Cost');
        expect(header).toContain('Margin');
    });

    /* The grouping key is an internal id; nobody should meet it in a file. */
    test('a hidden column never leaves', () => {
        expect(exportHeader(REPORT)).not.toContain('Customer Id');
        expect(exportColumns(REPORT).map((c) => c.key)).not.toContain('party_id');
    });

    /* A column of the word "Edit" is not data. */
    test('a column marked unexportable never leaves', () => {
        const columns = [...REPORT, { key: 'action', label: 'Action', type: 'link', exportable: false }];

        expect(exportHeader(columns)).not.toContain('Action');
    });
});

describe('what a figure looks like when it arrives', () => {
    /*
     * A spreadsheet is opened to add things up. A grouped string will not sum,
     * sort or filter, and the reader has to retype the column.
     */
    test('the spreadsheet gets numbers, not text', () => {
        const [row] = exportValues(REPORT, [ROW], formatters);
        const billed = row[exportHeader(REPORT).indexOf('Billed')];

        expect(typeof billed).toBe('number');
        expect(billed).toBe(5000);
    });

    test('everything read rather than calculated gets the grouped form', () => {
        const [row] = exportRows(REPORT, [ROW], formatters);

        expect(row[exportHeader(REPORT).indexOf('Billed')]).toBe('5,000.00');
    });

    /*
     * An In-house file has no vendor and so no cost. Number(null) is 0, and a
     * zero in a cell the screen leaves blank states a cost that was never
     * incurred.
     */
    test('a blank cell stays blank rather than becoming zero', () => {
        const index = exportHeader(REPORT).indexOf('Cost');

        expect(exportValues(REPORT, [ROW], formatters)[0][index]).toBe('');
        expect(exportRows(REPORT, [ROW], formatters)[0][index]).toBe('');
    });

    test('a real zero is still a zero', () => {
        const column = { key: 'cost', label: 'Cost', type: 'money' };

        expect(cellValue({ cost: 0 }, column, formatters)).toBe(0);
    });

    /* Never a minus sign: a balance carries the side it falls on instead. */
    test('a balance exports the way the ledger writes it', () => {
        const columns = [{ key: 'balance', label: 'Balance', type: 'balance' }];

        expect(exportRows(columns, [{ balance: -1200 }], formatters)[0][0]).toBe('1,200.00 Cr');
        expect(exportRows(columns, [{ balance: 1200 }], formatters)[0][0]).toBe('1,200.00 Dr');

        // The spreadsheet still gets the signed number, so it can be summed.
        expect(exportValues(columns, [{ balance: -1200 }], formatters)[0][0]).toBe(-1200);
    });
});

describe('CSV', () => {
    /*
     * A grouped figure carries a comma. Unquoted, 1,200.00 becomes two fields
     * and shifts every column after it by one — the kind of corruption that
     * still opens without complaint.
     */
    test('every field is quoted, so a figure cannot split a row', () => {
        const csv = toCsv(REPORT, [{ ...ROW, billed: 1200 }], formatters);
        const line = csv.split('\r\n')[1];

        expect(line).toContain('"1,200.00"');
        expect(line.split('","').length).toBe(exportHeader(REPORT).length);
    });

    test('a quote inside a value is doubled rather than ending the field', () => {
        expect(csvCell('12" pipe')).toBe('"12"" pipe"');
    });

    /* Without the mark Excel reads it as the local codepage and mangles ₹. */
    test('it starts with a byte-order mark', () => {
        expect(toCsv(REPORT, [ROW], formatters).charCodeAt(0)).toBe(0xfeff);
    });

    test('the header comes first, then one line per row', () => {
        const lines = toCsv(REPORT, [ROW, ROW], formatters).split('\r\n');

        expect(lines).toHaveLength(3);
        expect(lines[0]).toContain('"File No."');
    });
});

describe('the rows that are exported', () => {
    /*
     * The grid passes the filtered, sorted set rather than the visible page.
     * Exporting page 1 of 8 after someone has searched for a party is quiet
     * wrongness that nobody notices until it matters.
     */
    test('every row given is exported, however many there are', () => {
        const many = Array.from({ length: 120 }, (_, i) => ({ ...ROW, file_no: `F-${i}` }));

        expect(exportRows(REPORT, many, formatters)).toHaveLength(120);
    });

    test('no rows is a header and nothing else', () => {
        expect(toCsv(REPORT, [], formatters).split('\r\n')).toHaveLength(1);
    });
});

describe('the clipboard', () => {
    test('is tab separated, for pasting into a sheet', () => {
        const text = toClipboard(REPORT, [ROW], formatters);

        expect(text.split('\n')[0].split('\t')).toHaveLength(exportHeader(REPORT).length);
    });
});

describe('the filename', () => {
    test('survives a title full of punctuation', () => {
        expect(fileName('Customer-wise Work Report — 01-04-2026 to 15-08-2026 · Work in hand', 'xlsx'))
            .toBe('Customer-wise-Work-Report-01-04-2026-to-15-08-2026-Work-in-hand.xlsx');
    });
});
