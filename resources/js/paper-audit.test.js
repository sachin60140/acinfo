import { afterEach, describe, expect, it } from 'vitest';
import { createApp, nextTick } from 'vue';
import PaperAudit from './components/PaperAudit.vue';
import PaperChecklist from './components/PaperChecklist.vue';

/*
 * Checking a file's papers, and marking pending ones received.
 *
 * The server checks every answer again, so what can go wrong is here: a line
 * that looks answered and posts nothing, a "mark the rest" that overwrites a
 * paper somebody marked pending on purpose, or a search that hides a ticked
 * paper and silently drops it.
 */

const mounted = [];

function mount(component, props) {
    const host = document.createElement('div');
    document.body.appendChild(host);

    const app = createApp(component, props);
    app.mount(host);
    mounted.push({ app, host });

    return host;
}

afterEach(() => {
    while (mounted.length) {
        const { app, host } = mounted.pop();
        app.unmount();
        host.remove();
    }
});

const STATES = { received: 'Received', pending: 'Pending', not_needed: 'Not needed' };

const LINES = [
    { paper_type_id: 1, name: 'RC (original)', required: true, state: null, note: '', office_note: '', received_on: null, works: ['HPT', 'TR'] },
    { paper_type_id: 7, name: 'Form 35', required: true, state: 'received', note: '', office_note: '', received_on: '12-09-2026', works: ['HPT'] },
    { paper_type_id: 10, name: 'Form 30', required: true, state: 'pending', note: 'Buyer has not signed', office_note: '', received_on: null, works: ['TR'] },
    { paper_type_id: 12, name: "Buyer's undertaking", required: false, state: 'not_needed', note: '', office_note: '', received_on: null, works: ['TR'] },
];

function checklist(overrides = {}) {
    return mount(PaperChecklist, {
        action: '/admin/file/50/papers',
        csrf: 'token',
        returnTo: '/admin/file/audit',
        backUrl: '/admin/file/audit',
        editUrl: '/admin/file/edit/50',
        file: { file_no: 'F-00050', registration_no: 'BR01JB8140', customer: 'Rakesh Ji', received: '07-09-2026', status: 'In Office', status_key: 'in_office', works: ['HPT', 'TR'] },
        lastCheck: null,
        states: STATES,
        lines: LINES,
        errors: {},
        ...overrides,
    });
}

const line = (host, id) => [...host.querySelectorAll('.pck-line')].find((li) => li.querySelector(`input[name="papers[${id}][state]"]`));
const radio = (host, id, state) => host.querySelector(`input[name="papers[${id}][state]"][value="${state}"]`);
const posted = (host, id) => host.querySelector(`input[name="papers[${id}][state]"]:checked`)?.value ?? null;
const submit = (host) => host.querySelector('button[type="submit"]');

async function pick(host, id, state) {
    const input = radio(host, id, state);
    input.checked = true;
    input.dispatchEvent(new Event('change'));
    await nextTick();
}

async function type(input, value) {
    input.value = value;
    input.dispatchEvent(new Event('input'));
    await nextTick();
}

describe('a file\'s paper checklist', () => {
    it('posts each answer under the name the server reads', () => {
        const host = checklist();

        expect(posted(host, 7)).toBe('received');
        expect(posted(host, 10)).toBe('pending');
        expect(posted(host, 1)).toBeNull();
        expect(host.querySelector('input[name="papers[10][note]"]').value).toBe('Buyer has not signed');
        expect(host.querySelector('input[name="papers[10][office_note]"]')).not.toBeNull();
        expect(host.querySelector('input[name="return_to"]').value).toBe('/admin/file/audit');
    });

    it('will not save while a paper is unanswered, and says how many', async () => {
        const host = checklist();

        expect(submit(host).disabled).toBe(true);
        expect(host.querySelector('.pck-summary').textContent).toContain('1 paper is not marked');

        await pick(host, 1, 'received');

        expect(submit(host).disabled).toBe(false);
    });

    /*
     * Most folders arrive complete, so "the rest" is one click — but it must not
     * touch a paper somebody marked pending or not needed on purpose.
     */
    it('marks the rest received without overwriting a deliberate answer', async () => {
        const host = checklist();

        host.querySelector('.pck-head button').click();
        await nextTick();

        expect(posted(host, 1)).toBe('received');
        expect(posted(host, 10)).toBe('pending');
        expect(posted(host, 12)).toBe('not_needed');
    });

    it('asks why a required paper is not needed, and accepts an office note as the reason', async () => {
        const host = checklist({ lines: [{ ...LINES[0], state: 'received' }] });

        await pick(host, 1, 'not_needed');

        expect(submit(host).disabled).toBe(true);
        expect(line(host, 1).classList.contains('is-invalid')).toBe(true);

        await type(host.querySelector('input[name="papers[1][office_note]"]'), 'RTO waived it');

        expect(submit(host).disabled).toBe(false);
    });

    it('opens the notes when a paper is marked pending', async () => {
        const host = checklist({ lines: [{ ...LINES[1] }] });
        const notes = line(host, 7).querySelector('.pck-line__notes');

        expect(notes.style.display).toBe('none');

        await pick(host, 7, 'pending');

        expect(notes.style.display).toBe('');
    });

    it('keeps a hidden note on the form, so an untouched one is not wiped', () => {
        const host = checklist({ lines: [{ ...LINES[1], office_note: 'Collected by Rakesh' }] });

        expect(host.querySelector('input[name="papers[7][office_note]"]').value).toBe('Collected by Rakesh');
    });

    it('shows what the server refused, on the line it refused', () => {
        const host = checklist({ errors: { 'papers.1.state': 'Mark RC (original) received, pending or not needed.' } });

        expect(line(host, 1).textContent).toContain('Mark RC (original) received');
    });

    it('says what the customer will be asked for', async () => {
        const host = checklist();

        await pick(host, 1, 'received');

        expect(host.querySelector('.pck-summary').textContent).toContain('Pending: Form 30');
    });
});

// ---------------------------------------------------------------------------

const PENDING = [
    { id: 101, file_id: 50, file_no: 'F-00050', registration_no: 'BR01JB8140', customer: 'Rakesh Ji', paper: 'Form 30', works: ['TR'], note: 'Buyer has not signed', office_note: null, since: '12-09-2026', papers_url: '/admin/file/50/papers' },
    { id: 102, file_id: 50, file_no: 'F-00050', registration_no: 'BR01JB8140', customer: 'Rakesh Ji', paper: 'Form 34', works: ['HPA'], note: null, office_note: 'Bank slow', since: '12-09-2026', papers_url: '/admin/file/50/papers' },
    { id: 103, file_id: 56, file_no: 'F-00056', registration_no: 'BR01HD8416', customer: 'Car4Sales', paper: 'Bank NOC', works: ['HPT'], note: null, office_note: null, since: '13-09-2026', papers_url: '/admin/file/56/papers' },
];

const TO_CHECK = [
    { id: 60, file_no: 'F-00060', registration_no: 'BR06AB1234', customer: 'Car4Sales', work_type: 'TR', received_date: '15-09-2026', papers_url: '/admin/file/60/papers' },
];

function audit(overrides = {}) {
    return mount(PaperAudit, {
        action: '/admin/file/audit',
        csrf: 'token',
        today: '2026-09-17',
        toCheck: TO_CHECK,
        pending: PENDING,
        ...overrides,
    });
}

const box = (host, id) => host.querySelector(`input[name="received[]"][value="${id}"]`);
const ticked = (host) => [...host.querySelectorAll('input[name="received[]"]')].filter((b) => b.checked).map((b) => Number(b.value));
const visiblePapers = (host) => [...host.querySelectorAll('input[name="received[]"]')]
    .filter((b) => b.closest('tr').style.display !== 'none')
    .map((b) => Number(b.value));

async function search(host, text) {
    const input = host.querySelector('.pau-search input');
    input.value = text;
    input.dispatchEvent(new Event('input'));
    await nextTick();
}

describe('the paper audit screen', () => {
    it('posts ticked papers and the day they came in', async () => {
        const host = audit();

        box(host, 101).click();
        await nextTick();

        expect(ticked(host)).toEqual([101]);
        expect(host.querySelector('input[name="received_on"]').value).toBe('2026-09-17');
        expect(host.querySelector('#received_on_display').dataset.max).toBe('2026-09-17');
    });

    it('will not submit until something is ticked', async () => {
        const host = audit();
        const button = host.querySelector('form button[type="submit"]');

        expect(button.disabled).toBe(true);

        box(host, 103).click();
        await nextTick();

        expect(button.disabled).toBe(false);
        expect(host.querySelector('form .ui-card__foot').textContent).toContain('1 paper on 1 file');
    });

    it('narrows both lists by customer, vehicle or paper', async () => {
        const host = audit();

        await search(host, 'rakesh form 30');

        expect(visiblePapers(host)).toEqual([101]);
        expect([...host.querySelectorAll('.pau-table')][0].textContent).toContain('No file to check matches');
    });

    it('keeps a ticked paper ticked while the search hides it, and says so', async () => {
        const host = audit();

        box(host, 103).click();
        await nextTick();
        await search(host, 'rakesh');

        expect(ticked(host)).toEqual([103]);
        expect(host.querySelector('form .ui-card__foot').textContent).toContain('hidden by the search');
    });

    it('selects only what is on screen', async () => {
        const host = audit();

        await search(host, 'rakesh');

        host.querySelector('.pau-all input').click();
        await nextTick();

        expect(ticked(host).sort()).toEqual([101, 102]);
    });

    it('shows the office note as the office\'s', () => {
        const host = audit();

        expect(box(host, 102).closest('tr').textContent).toContain('Office: Bank slow');
    });

    it('says so when there is nothing to do', () => {
        const host = audit({ toCheck: [], pending: [] });

        expect(host.textContent).toContain('Every received file has had its papers checked.');
        expect(host.textContent).toContain('No papers are pending.');
        expect(host.querySelector('form button[type="submit"]')).toBeNull();
    });
});
