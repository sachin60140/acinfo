<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * The application on a phone.
 *
 * Most of the work is done at a counter, and a good deal of it standing up with
 * the phone in one hand and the papers in the other. A screen that needs
 * scrolling sideways to read a figure is a screen nobody uses there.
 *
 * These are the failures that are invisible to every other test: the markup is
 * right, the data is right, the response is 200, and the page is unusable at
 * 360 pixels. They were all found by reading the stylesheets the way a narrow
 * screen meets them, and each one is checked here so it cannot come back.
 *
 * See the note in PartyLedgerTest: DatabaseTransactions, never RefreshDatabase.
 */
class ResponsiveTest extends TestCase
{
    use DatabaseTransactions;

    /** A phone in CSS pixels, and the narrowest this has to hold at. */
    private const PHONE = 360;

    /** What the design system promises nothing interactive will be under. */
    private const TAP = 44;

    /** Classes the shared sheet already sizes for touch. */
    private const SIZED_BY_THE_SYSTEM = ['ui-btn', 'ui-input', 'ui-select', 'chip'];

    private function admin(): User
    {
        $user = new User;
        $user->name = 'Responsive Admin';
        $user->email = 'responsive-'.uniqid().'@example.com';
        $user->password = Hash::make('password-for-tests');
        $user->user_type = 1;
        $user->save();

        return $user;
    }

    /** Every component and view, keyed by a path worth printing. */
    private function sources(): array
    {
        $out = [];

        foreach ([resource_path('js/components'), resource_path('views')] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
                if ($file->isDir()) {
                    continue;
                }

                $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());

                if (preg_match('/\.(vue|blade\.php)$/', $path)) {
                    $out[str_replace(str_replace(DIRECTORY_SEPARATOR, '/', resource_path()).'/', '', $path)]
                        = file_get_contents($path);
                }
            }
        }

        return $out;
    }

    /** The CSS in one file, comments stripped. */
    private function styles(string $body): string
    {
        if (preg_match_all('#<style[^>]*>(.*?)</style>#s', $body, $blocks)) {
            return preg_replace('#/\*.*?\*/#s', '', implode("\n", $blocks[1]));
        }

        return '';
    }

    /**
     * Every rule in a stylesheet, with the media conditions it sits under.
     *
     * The braces are walked rather than matched: a regex over CSS quietly skips
     * whatever it was not written to expect and reports the rest as clean,
     * which is how a first attempt at this test passed while reading a fifth of
     * the rules it claimed to.
     *
     * @return array<int, array{selector: string, declarations: string, media: string}>
     */
    private function rules(string $css): array
    {
        $out = [];
        $stack = [];
        $buffer = '';

        foreach (str_split($css) as $char) {
            if ($char === '{') {
                $stack[] = trim(preg_replace('/\s+/', ' ', $buffer));
                $buffer = '';

                continue;
            }

            if ($char === '}') {
                $selector = array_pop($stack) ?? '';
                $declarations = trim($buffer);
                $buffer = '';

                if ($declarations !== '' && str_contains($declarations, ':')) {
                    $out[] = [
                        'selector' => $selector,
                        'declarations' => $declarations,
                        'media' => implode(' ', array_filter($stack, fn ($s) => str_starts_with($s, '@'))),
                    ];
                }

                continue;
            }

            $buffer .= $char;
        }

        return $out;
    }

    /** @return array<string, string> */
    private function declared(string $block): array
    {
        $out = [];

        foreach (explode(';', $block) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $out[trim($name)] = trim($value);
        }

        return $out;
    }

    private function pixels(string $value): ?float
    {
        if (! preg_match('/^(\d+(?:\.\d+)?)(px|rem)$/', $value, $m)) {
            return null;
        }

        return $m[2] === 'rem' ? ((float) $m[1]) * 16 : (float) $m[1];
    }

    /**
     * A grid of several columns has to say what it becomes on a narrow screen.
     *
     * Two columns of form fields at 360 pixels is two columns of about 150,
     * which fits a label and nothing else. The fix is always the same — one
     * column — but it has to be written down, and a grid added without it looks
     * perfectly correct on the machine it was built on.
     */
    public function test_every_grid_of_several_columns_collapses_on_a_phone(): void
    {
        $problems = [];

        foreach ($this->sources() as $file => $body) {
            $css = $this->styles($body);

            if ($css === '') {
                continue;
            }

            $rules = $this->rules($css);
            $narrow = [];

            foreach ($rules as $rule) {
                if (! str_contains($rule['media'], 'max-width')) {
                    continue;
                }

                foreach (explode(',', $rule['selector']) as $one) {
                    if (isset($this->declared($rule['declarations'])['grid-template-columns'])) {
                        $narrow[trim($one)] = true;
                    }
                }
            }

            foreach ($rules as $rule) {
                // A rule under min-width is the wide-screen answer; the rule it
                // overrides is already the narrow one.
                if (str_contains($rule['media'], 'max-width') || str_contains($rule['media'], 'min-width')) {
                    continue;
                }

                $columns = $this->declared($rule['declarations'])['grid-template-columns'] ?? null;

                if ($columns === null) {
                    continue;
                }

                // These collapse by themselves.
                if (str_contains($columns, 'auto-fit')
                    || str_contains($columns, 'auto-fill')
                    || preg_match('/^(1fr|100%|minmax\([^()]*\))$/', $columns)) {
                    continue;
                }

                $answered = false;

                foreach (explode(',', $rule['selector']) as $one) {
                    $answered = $answered || isset($narrow[trim($one)]);
                }

                if (! $answered) {
                    $problems[] = "$file: \"{$rule['selector']}\" is \"$columns\" and is never restated for a narrow screen";
                }
            }
        }

        $this->assertSame([], $problems, "These grids keep their columns on a phone:\n  ".implode("\n  ", $problems));
    }

    /**
     * Nothing is fixed wider than the screen it has to fit on.
     */
    public function test_nothing_is_fixed_wider_than_a_phone(): void
    {
        $problems = [];

        foreach ($this->sources() as $file => $body) {
            foreach ($this->rules($this->styles($body)) as $rule) {
                if (str_contains($rule['media'], 'max-width') || str_contains($rule['media'], 'min-width')) {
                    continue;
                }

                foreach (['width', 'min-width', 'flex-basis'] as $property) {
                    $value = $this->declared($rule['declarations'])[$property] ?? null;
                    $px = $value === null ? null : $this->pixels($value);

                    if ($px !== null && $px > self::PHONE) {
                        $problems[] = "$file: \"{$rule['selector']}\" fixes $property at $value";
                    }
                }
            }
        }

        $this->assertSame([], $problems, "These are wider than a phone:\n  ".implode("\n  ", $problems));
    }

    /**
     * Every control can be hit with a thumb.
     *
     * The design system names 44 pixels and sizes four classes to it, which was
     * every control when it was written. Each bespoke button added since is its
     * own size, and a 36 pixel cross that takes a charge off a customer's
     * statement is the wrong control to make hard to hit — and, missed, the
     * wrong one to hit by accident on the second try.
     */
    public function test_every_control_can_be_hit_with_a_thumb(): void
    {
        $sources = $this->sources();
        $css = file_get_contents(resource_path('css/app.css'));

        foreach ($sources as $body) {
            $css .= "\n".$this->styles($body);
        }

        $css = preg_replace('#/\*.*?\*/#s', '', $css);
        $rules = $this->rules($css);

        $problems = [];

        foreach ($sources as $file => $body) {
            if (! preg_match_all('/<(?:button|a)\b[^>]*?\bclass="([^"{]*)"/s', $body, $found)) {
                continue;
            }

            foreach ($found[1] as $classes) {
                $names = array_filter(preg_split('/\s+/', trim($classes)));

                if (array_intersect($names, self::SIZED_BY_THE_SYSTEM)) {
                    continue;
                }

                foreach ($names as $class) {
                    // A modifier is sized by whatever it modifies.
                    if (str_contains($class, '--')) {
                        continue;
                    }

                    $tallest = null;

                    foreach ($rules as $rule) {
                        $sizesThis = false;

                        foreach (explode(',', $rule['selector']) as $one) {
                            // The class has to be the last thing in the selector:
                            // a rule for ".card .btn i" sizes the icon, not the
                            // button.
                            if (preg_match('/\.'.preg_quote($class, '/').'(?![\w-])(?::[\w-]+(?:\([^)]*\))?)*$/', trim($one))) {
                                $sizesThis = true;
                            }
                        }

                        if (! $sizesThis) {
                            continue;
                        }

                        $props = $this->declared($rule['declarations']);

                        foreach (['height', 'min-height'] as $property) {
                            $value = $props[$property] ?? null;

                            if ($value === null) {
                                continue;
                            }

                            $px = $value === 'var(--tap)' ? self::TAP : $this->pixels($value);

                            if ($px !== null) {
                                $tallest = max($tallest ?? 0, $px);
                            }
                        }
                    }

                    if ($tallest !== null && $tallest < self::TAP) {
                        $problems[".$class"] = "$file: .$class is {$tallest}px, under the ".self::TAP.'px the design system promises';
                    }
                }
            }
        }

        $this->assertSame(
            [],
            array_values($problems),
            "These are too small to hit on a phone:\n  ".implode("\n  ", $problems)
        );
    }

    /**
     * And the pages themselves, as the browser receives them.
     *
     * A stylesheet can be faultless while the markup sets its own width, or a
     * table is printed with nowhere to scroll — neither of which the checks
     * above can see.
     */
    public function test_no_screen_forces_a_phone_to_scroll_sideways(): void
    {
        $this->actingAs($this->admin());

        $party = \App\Models\PartyModel::first();
        $file = \App\Models\WorkFileModel::first();

        $screens = array_filter([
            'admin/dashboard',
            'admin/parties/customer',
            'admin/parties/vendor',
            'admin/party/add/customer',
            'admin/party/entry/customer',
            $party ? 'admin/party/statement/'.$party->id : null,
            'admin/files',
            'admin/file/receive',
            'admin/file/assign',
            'admin/file/status',
            'admin/file/customer-return',
            'admin/file/vendor-return',
            $file ? 'admin/file/edit/'.$file->id : null,
            'admin/work-types',
            'admin/reports/files',
            'admin/view-clients',
            'admin/add-clients',
            'admin/payment',
            'admin/receipt',
        ]);

        $problems = [];

        foreach ($screens as $screen) {
            $html = $this->get($screen)->assertOk()->getContent();

            if (! str_contains($html, 'width=device-width')) {
                $problems[] = "$screen: no viewport, so a phone renders it at desktop width and shrinks it";
            }

            if (preg_match_all('/style="[^"]*?(?<!max-)(?<!-)\b(?:min-)?width:\s*(\d{3,})px/i', $html, $wide)) {
                foreach ($wide[1] as $px) {
                    if ((int) $px > self::PHONE) {
                        $problems[] = "$screen: markup sets its own width of {$px}px";
                    }
                }
            }

            if (preg_match_all('/<table\b/', $html, $tables, PREG_OFFSET_CAPTURE)) {
                foreach ($tables[0] as [, $at]) {
                    $before = substr($html, max(0, $at - 600), min($at, 600));

                    $held = str_contains($before, 'table-responsive')
                        || str_contains($before, 'ui-table-wrap')
                        || str_contains($before, 'overflow-x');

                    if (! $held) {
                        $problems[] = "$screen: a table with nowhere to scroll";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($problems)),
            "These screens need scrolling sideways on a phone:\n  ".implode("\n  ", array_unique($problems))
        );
    }
}
