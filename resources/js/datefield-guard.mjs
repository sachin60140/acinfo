/*
 * Checks that no component hands a date field to Vue to re-render.
 *
 * public/assets/js/datepicker.js owns every .js-datefield: it writes the chosen
 * date into the visible box and the Y-m-d into the hidden one beside it. Vue has
 * to leave both alone afterwards, and a :value binding does not — it force-
 * patches the "value" key on every re-render even when the bound value has not
 * changed (runtime-core patchProps: `next !== prev || key === "value"`), and
 * compares against the live DOM value, so it writes the page-load default back
 * over whatever the operator picked.
 *
 * That is a quiet failure: the box still holds a well-formed date, so
 * date_format:Y-m-d passes and the entry saves against the wrong day with
 * nothing shown to anyone. It shipped on two screens before being caught.
 *
 * Two ways to be safe, and this checks for both:
 *   - wrap the field in v-once, so the subtree is rendered once and cached
 *   - hand the field over as server-rendered markup and v-html it
 *
 * Run by tests/Feature/VueMountTest.php. Exits non-zero and names the file.
 */
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { parse } from '@vue/compiler-sfc';
import { compile } from '@vue/compiler-dom';

const dir = new URL('./components/', import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1');
const problems = [];
let checked = 0;

for (const file of readdirSync(dir).filter((name) => name.endsWith('.vue'))) {
    const source = readFileSync(join(dir, file), 'utf8');
    const { descriptor, errors } = parse(source);

    if (errors.length) {
        problems.push(`${file}: does not parse — ${errors[0].message}`);
        continue;
    }

    const template = descriptor.template?.content ?? '';

    if (!template.includes('js-datefield')) {
        continue;
    }

    checked++;

    const { code } = compile(template, { mode: 'module' });

    // v-html hands the field over whole; Vue only rewrites innerHTML when the
    // string itself changes, and it never does.
    if (/innerHTML/.test(code)) {
        continue;
    }

    /*
     * v-once compiles the subtree into a _cache entry, so finding the field
     * inside one is proof it is rendered once rather than proof someone wrote
     * the words "v-once" somewhere in the file.
     */
    const cached = code
        .split('_cache[')
        .slice(1)
        .some((segment) => segment.slice(0, 2000).includes('js-datefield'));

    if (!cached) {
        problems.push(
            `${file}: a .js-datefield is re-rendered by Vue. Wrap it in v-once, or pass the ` +
                'field in as server-rendered markup and v-html it — otherwise the date the ' +
                'operator picks is silently replaced by the page-load default on the next render.'
        );
    }
}

if (problems.length) {
    console.error(problems.join('\n'));
    process.exit(1);
}

console.log(`ok — ${checked} date field${checked === 1 ? '' : 's'} guarded`);
