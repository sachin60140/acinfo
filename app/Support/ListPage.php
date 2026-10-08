<?php

namespace App\Support;

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Where a page of a long list stands among the rest, for the line above it.
 *
 * A list of finished work is drawn a page at a time, newest first; see
 * WorkFileModel::LIST_LIMIT. A page that is not the whole list has to say so —
 * how many it shows, of how many — and lead to the rest, or it reads as
 * everything there is, and its totals as the whole answer.
 *
 * Plain values rather than the paginator, because a screen's furniture goes
 * over the wire as well as to the view; see Screen.
 */
class ListPage
{
    /**
     * What the line says, or null when the whole list is on the page.
     *
     * @return array{first: int, last: int, total: int, newer: ?string, older: ?string}|null
     */
    public static function of($rows): ?array
    {
        if (! $rows instanceof LengthAwarePaginator || ! $rows->hasPages()) {
            return null;
        }

        return [
            'first' => (int) $rows->firstItem(),
            'last' => (int) $rows->lastItem(),
            'total' => $rows->total(),
            'newer' => $rows->previousPageUrl(),
            'older' => $rows->nextPageUrl(),
        ];
    }
}
