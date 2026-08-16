<?php

namespace App\Support;

use RuntimeException;

/**
 * How a screen's data reaches its Vue component.
 *
 * Plain json_encode() returns false rather than throwing when it meets a byte
 * sequence that is not valid UTF-8 — and this database is old enough, and its
 * name and address columns free enough, that such a byte is a question of when.
 * Blade then prints nothing, the mount point gets an empty data-props, and the
 * component is handed {} instead of its columns and rows.
 *
 * What that looks like is the point: the page returns 200, the header, filters
 * and totals all render, and the table is simply absent. Nothing is logged and
 * nothing is shown. One bad character in one party's address would empty a
 * ledger screen with no indication that anything had gone wrong.
 *
 * So the bad bytes are substituted instead. A name that arrives with a question
 * mark in it is a small, visible, obviously-wrong thing that someone can fix in
 * the record; a blank screen is not.
 */
class VueProps
{
    public static function encode(array $props): string
    {
        $json = json_encode(
            $props,
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            /*
             * Substitution handles bad encoding, so anything still failing here
             * is structural — a resource that cannot be serialised, or nesting
             * deeper than json_encode will follow. That is a programming mistake
             * rather than bad data, and it should stop the page rather than
             * render a screen with a hole in it.
             */
            throw new RuntimeException('Could not encode Vue props: '.json_last_error_msg());
        }

        return $json;
    }
}
