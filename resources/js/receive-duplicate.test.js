import { afterEach, describe, expect, it, vi } from 'vitest';
import { createApp, nextTick } from 'vue';
import ReceiveFileRows from './components/ReceiveFileRows.vue';

/*
 * The same papers taken in twice.
 *
 * The counter has the vehicle's history on screen already; what it did not have
 * was the one sentence that matters — this vehicle already has this work in
 * hand. Work finished long ago is a different thing and must not stop anybody,
 * or the warning stops being read.
 */

const mounted = [];

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }

    vi.restoreAllMocks();
    vi.useRealTimers();
});

const TR = { id: 2, name: 'TR', default_rate: null };
const HPA = { id: 1, name: 'HPA', default_rate: '2500.00' };

const past = (attributes) => ({
    id: 11,
    file_no: 'F-00050',
    received_date: '07-09-2026',
    work_type: 'TR',
    work_type_id: 2,
    customer: 'Car4Sales',
    vendor: null,
    status: 'file_dispatch',
    status_label: 'File Dispatch',
    charged: '5,000.00',
    net: '5,000.00',
    was_returned: false,
    works: [{ work_type_id: 2, work_type: 'TR' }],
    open: true,
    ...attributes,
});

const byName = (host, name) => host.querySelector(`[name="${name}"]`);
const submit = (host) => host.querySelector('button[type="submit"]');

/** The screen, with this vehicle's history already answered, and one work chosen. */
async function screen(files, workTypeId = '2') {
    global.fetch = () => Promise.resolve({
        ok: true,
        json: () => Promise.resolve({ registration_no: 'BR01JB8140', count: files.length, files }),
    });

    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(ReceiveFileRows, {
        workTypes: [HPA, TR],
        historyUrl: '/admin/api/work-files/history',
        cancelUrl: '/admin/files',
        oldRows: [],
    });

    app.mount(host);
    mounted.push({ app, host });

    const plate = byName(host, 'rows[0][registration_no]');
    plate.value = 'BR01JB8140';
    plate.dispatchEvent(new window.Event('input'));

    const type = byName(host, 'rows[0][works][0][work_type_id]');
    type.value = workTypeId;
    type.dispatchEvent(new window.Event('change'));

    // The lookup is debounced by 400ms, then the reply resolves, then Vue renders.
    await new Promise((resolve) => setTimeout(resolve, 450));
    await Promise.resolve();
    await Promise.resolve();
    await nextTick();

    return host;
}

describe('work already in hand', () => {
    it('says which file has it, and will not send until the office says it is deliberate', async () => {
        const host = await screen([past({})]);

        const alert = host.querySelector('.rcv-clash');

        expect(alert).not.toBeNull();
        expect(alert.textContent).toContain('F-00050');
        expect(alert.textContent).toContain('TR');
        expect(alert.textContent).toContain('File Dispatch');

        expect(submit(host).disabled).toBe(true);
        expect(byName(host, 'rows[0][duplicate_ok]')).toBeNull();

        alert.querySelector('input[type="checkbox"]').click();
        await nextTick();

        expect(submit(host).disabled).toBe(false);
        expect(byName(host, 'rows[0][duplicate_ok]').value).toBe('1');
    });

    it('says nothing when the earlier file is for other work', async () => {
        const host = await screen([past({ works: [{ work_type_id: 1, work_type: 'HPA' }], work_type: 'HPA' })]);

        expect(host.querySelector('.rcv-clash')).toBeNull();
        expect(submit(host).disabled).toBe(false);
    });

    /*
     * A folder holding a transfer and a hypothecation addition names both, so
     * the transfer on it is found even though the folder is labelled HPA.
     */
    it('looks at every work on an earlier folder, not just its label', async () => {
        const host = await screen([past({
            work_type: 'HPA',
            works: [{ work_type_id: 1, work_type: 'HPA' }, { work_type_id: 2, work_type: 'TR' }],
        })]);

        expect(host.querySelector('.rcv-clash')).not.toBeNull();
    });
});

describe('work done before and finished', () => {
    it('is worth saying and never worth stopping', async () => {
        const host = await screen([past({ open: false, status: 'approval_done', status_label: 'Approval Done' })]);

        expect(host.querySelector('.rcv-clash')).toBeNull();

        const note = host.querySelector('.rcv-again');

        expect(note).not.toBeNull();
        expect(note.textContent).toContain('TR');
        expect(note.textContent).toContain('F-00050');
        expect(submit(host).disabled).toBe(false);
    });

    /** One in hand and one finished: the one that stops the file wins the space. */
    it('gives way to work still in hand', async () => {
        const host = await screen([
            past({ open: false, status_label: 'Approval Done' }),
            past({ id: 12, file_no: 'F-00061' }),
        ]);

        expect(host.querySelector('.rcv-clash').textContent).toContain('F-00061');
        expect(host.querySelector('.rcv-again')).toBeNull();
    });
});

describe('a vehicle with no history', () => {
    it('is taken in with nothing in the way', async () => {
        const host = await screen([]);

        expect(host.querySelector('.rcv-clash')).toBeNull();
        expect(host.querySelector('.rcv-again')).toBeNull();
        expect(submit(host).disabled).toBe(false);
    });
});
