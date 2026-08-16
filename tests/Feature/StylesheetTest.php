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
}
