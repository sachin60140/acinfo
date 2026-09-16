<?php

namespace App\Support;

/**
 * An address for a file in public/ that changes when the file does.
 *
 * Everything Vite builds is already safe: it writes assets/app-BUsUdYYZ.css and
 * a new hash means a new name, so a browser holding the old one has no reason to
 * think it is current. The template's own stylesheets are not built. They were
 * linked at a bare path, and a browser that has assets/css/nav.css does not ask
 * for it again — for as long as its cache says, which for a stylesheet is a long
 * time and on a proxy is longer.
 *
 * So a CSS change reached whoever had never loaded the page before, and nobody
 * else. The sidebar shipped a version where the headings became buttons and the
 * rules that make a button look like a heading were in nav.css: the markup
 * arrived, the CSS did not, and every heading rendered as a grey system button
 * with a border round it. Nothing had failed. The browser was doing exactly what
 * it was told.
 *
 * The modification time is the whole mechanism. It costs one stat per file per
 * request, it cannot drift from the file the way a hand-kept version number
 * does, and deploying by git pull updates it without anybody remembering to.
 */
class Asset
{
    /** Modification times already looked up during this request. */
    private static array $stamps = [];

    /**
     * @param  string  $path  relative to public/, e.g. 'assets/css/nav.css'
     */
    public static function url(string $path): string
    {
        return url($path).'?v='.self::version($path);
    }

    /**
     * The file's modification time, or 0 where there is no file.
     *
     * Missing is not an error here: a template that links a stylesheet which is
     * not there has a problem, but refusing to render the page is not the way to
     * report it. The link is emitted and the browser 404s it, exactly as it did
     * before this class existed.
     */
    public static function version(string $path): int
    {
        if (array_key_exists($path, self::$stamps)) {
            return self::$stamps[$path];
        }

        $full = public_path($path);

        return self::$stamps[$path] = is_file($full) ? (int) filemtime($full) : 0;
    }
}
