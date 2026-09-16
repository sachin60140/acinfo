<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Classes used on a screen that cannot reach the rules that style them.
 *
 * The pending-price chips rendered as bare blue text on the file list, because
 * .chip lived inside the status board's own <style> block while the file list
 * used the name. Nothing errored, nothing was logged, and the only symptom was
 * a filter row that did not look like a filter row — found because someone
 * opened the page and said so.
 *
 * That is the whole category: a page borrowing a class from a page it does not
 * include. It is invisible to every other test here, because the markup is
 * right, the data is right, and the response is 200.
 */
class StylesheetTest extends TestCase
{
    /** Every Blade file, keyed with forward slashes so includes resolve. */
    private function views(): array
    {
        $views = [];
        $root = resource_path('views');

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isDir() && str_ends_with($file->getFilename(), '.blade.php')) {
                $views[] = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());
            }
        }

        return $views;
    }

    /** Class names appearing in a stylesheet or a <style> block. */
    private function classesIn(string $css): array
    {
        preg_match_all('/(?<![\w-])\.([a-z][\w-]*)/i', $css, $found);

        return array_values(array_unique($found[1]));
    }

    /** What each view defines in its own <style> blocks. */
    private function definitions(): array
    {
        $defines = [];

        foreach ($this->views() as $view) {
            if (preg_match_all('#<style>(.*?)</style>#s', file_get_contents($view), $blocks)) {
                $defines[$view] = $this->classesIn(implode("\n", $blocks[1]));
            }
        }

        return $defines;
    }

    /**
     * Everything every page gets regardless: the design system, the template,
     * and Bootstrap. A class from here is always safe to use anywhere.
     */
    private function globalClasses(): array
    {
        $classes = [];

        foreach ([
            resource_path('css/app.css'),
            public_path('assets/css/nav.css'),
            public_path('assets/css/responsive.css'),
            public_path('assets/css/datepicker.css'),
            public_path('assets/css/style.css'),
            public_path('assets/vendor/bootstrap/css/bootstrap.min.css'),
            public_path('assets/vendor/bootstrap-icons/bootstrap-icons.css'),
        ] as $sheet) {
            if (file_exists($sheet)) {
                $classes = array_merge($classes, $this->classesIn(file_get_contents($sheet)));
            }
        }

        return array_flip(array_unique($classes));
    }

    /** A view's own style blocks, plus everything it includes or extends. */
    private function reachable(string $view, array $defines, array $seen = []): array
    {
        if (isset($seen[$view])) {
            return [];
        }

        $seen[$view] = true;
        $classes = $defines[$view] ?? [];
        $source = @file_get_contents($view);

        if ($source === false) {
            return $classes;
        }

        preg_match_all("#@(?:include|extends)\('([\w.\-]+)'#", $source, $refs);

        foreach ($refs[1] as $ref) {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', resource_path('views/'.str_replace('.', '/', $ref).'.blade.php'));

            if (file_exists($path)) {
                $classes = array_merge($classes, $this->reachable($path, $defines, $seen));
            }
        }

        return $classes;
    }

    public function test_no_screen_uses_a_class_it_cannot_reach(): void
    {
        $defines = $this->definitions();
        $global = $this->globalClasses();

        $this->assertNotEmpty($global, 'no global stylesheets were read — the check would pass vacuously');

        $problems = [];

        foreach ($this->views() as $view) {
            preg_match_all('/class="([^"]*)"/', file_get_contents($view), $attrs);

            $used = [];
            foreach ($attrs[1] as $attr) {
                // Drop Blade expressions so a conditional class is not read as
                // a literal one.
                foreach (preg_split('/\s+/', trim(preg_replace('/\{\{.*?\}\}/s', ' ', $attr))) as $class) {
                    if ($class !== '' && preg_match('/^[a-z][\w-]*$/i', $class)) {
                        $used[$class] = true;
                    }
                }
            }

            $canReach = array_flip($this->reachable($view, $defines));

            foreach (array_keys($used) as $class) {
                if (isset($global[$class]) || isset($canReach[$class])) {
                    continue;
                }

                /*
                 * Only a class defined somewhere in the application counts. An
                 * unknown name is far more likely to be a limitation of this
                 * parser than a real orphan, and a check that cries wolf gets
                 * switched off.
                 */
                foreach ($defines as $owner => $classes) {
                    if (in_array($class, $classes, true)) {
                        $problems[] = sprintf(
                            '%s uses .%s, which is styled only in %s',
                            str_replace(str_replace(DIRECTORY_SEPARATOR, '/', resource_path('views')).'/', '', $view),
                            $class,
                            str_replace(str_replace(DIRECTORY_SEPARATOR, '/', resource_path('views')).'/', '', $owner)
                        );
                        break;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $problems,
            "A screen is using a class whose rules it never receives, so it renders unstyled:\n  ".
            implode("\n  ", $problems)."\n\nMove the rules into resources/css/app.css, or include the partial that holds them."
        );
    }
    /**
     * A grid column can carry a class, and the grid stamps it on the <td>.
     *
     * So those names are not decoration: they land on a table cell, and a rule
     * that gives one of them a display takes that cell out of its table. The
     * row then has one fewer column than the heading promises, every cell after
     * it slides one place left, and the last column is empty — a whole listing
     * misread, from one property, with no error anywhere.
     *
     * That is what .cr did. CustomerReturn.vue kept a private copy of .ui-page
     * under the name "cr", unscoped, and the ledger has used .cr for a credit
     * figure since long before it. Every Cost and Expenses cell on every grid
     * in the application quietly stopped being a table cell.
     *
     * Two-letter names in a global stylesheet is the underlying fault; this
     * catches the consequence, which is the part that shows.
     */
    private const LAYOUT = ["display", "position", "float", "grid-area", "grid-column", "grid-row"];

    /** Class names the controllers hand to a column, which end up on a cell. */
    private function cellClasses(): array
    {
        $classes = [];
        $root = app_path("Http/Controllers");

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isDir() || ! str_ends_with($file->getFilename(), ".php")) {
                continue;
            }

            if (preg_match_all("/'class'\s*=>\s*'([^']+)'/", file_get_contents($file->getPathname()), $found)) {
                foreach ($found[1] as $attr) {
                    foreach (preg_split("/\s+/", trim($attr)) as $class) {
                        if ($class !== "") {
                            $classes[$class] = true;
                        }
                    }
                }
            }
        }

        return array_keys($classes);
    }

    /** Every rule in the application, as [where, selector, declarations]. */
    private function rules(): array
    {
        $sources = [
            resource_path("css/app.css"),
            public_path("assets/css/nav.css"),
            public_path("assets/css/responsive.css"),
            public_path("assets/css/style.css"),
        ];

        foreach (glob(resource_path("js/components/*.vue")) as $component) {
            $sources[] = $component;
        }

        $rules = [];

        foreach ($sources as $source) {
            if (! file_exists($source)) {
                continue;
            }

            $css = file_get_contents($source);

            // A component is mostly not CSS, so take only its style blocks.
            if (str_ends_with($source, ".vue")) {
                preg_match_all("#<style[^>]*>(.*?)</style>#s", $css, $blocks);
                $css = implode("\n", $blocks[1]);
            }

            // Naive, and deliberately so: the inner rule of an @media block is
            // matched the same as a top-level one, which is what we want — a
            // display inside a media query removes the cell just as thoroughly.
            preg_match_all("/([^{}]+)\{([^{}]*)\}/", $css, $found, PREG_SET_ORDER);

            foreach ($found as $rule) {
                $rules[] = [basename($source), trim($rule[1]), $rule[2]];
            }
        }

        return $rules;
    }

    public function test_a_cell_class_is_never_given_a_layout_of_its_own(): void
    {
        $classes = $this->cellClasses();
        $rules = $this->rules();

        $this->assertContains("cr", $classes, "the controllers no longer name the class this guards");
        $this->assertNotEmpty($rules, "no CSS was read — the check would pass vacuously");

        $problems = [];

        foreach ($rules as [$where, $selector, $body]) {
            foreach (explode(",", $selector) as $one) {
                $one = trim($one);

                // Only a bare class selector: .cr on its own is received by
                // every element in the application carrying the name, which is
                // the form that does the damage. A scoped one — .give-past .cr
                // — is a deliberate statement about one screen.
                if (! preg_match("/^\.([a-z][\w-]*)$/i", $one, $name)) {
                    continue;
                }

                if (! in_array($name[1], $classes, true)) {
                    continue;
                }

                foreach (self::LAYOUT as $property) {
                    if (preg_match("/(?<![\w-])".preg_quote($property, "/")."\s*:/i", $body)) {
                        $problems[] = sprintf("%s: %s { %s: ... }", $where, $one, $property);
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $problems,
            "A class a grid puts on a <td> is given a layout of its own, which stops the cell being a cell:\n  ".
            implode("\n  ", $problems)."\n\nRename the rule, or scope it to the screen that wants it."
        );
    }
}