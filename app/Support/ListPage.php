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
     * @param  bool  $byApproval  a list of approved work, which is newest by
     *                            the day it was approved rather than the day it
     *                            came in — said, or the received dates down the
     *                            page read as out of order; see
     *                            WorkFileModel::newestPage()
     * @return array{first: int, last: int, total: int, newer: ?string, older: ?string, byApproval: bool}|null
     */
    public static function of($rows, bool $byApproval = false): ?array
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
            'byApproval' => $byApproval,
        ];
    }

    /**
     * What heads an export of the page, and names its file.
     *
     * Found in review: the PDF, the print and the spreadsheet of a page of a
     * longer list held that page alone under the list's own heading — a
     * customer's Work Report with 1,200 files printed as "All dates · All
     * statuses" with 500 rows, and nothing on the sheet to say so. So a page
     * says which part of the list it is, as the line above it does on screen.
     * The whole list on one page is headed as it always was.
     */
    public static function heading(string $title, ?array $shown): string
    {
        if (! $shown) {
            return $title;
        }

        return $title.' — '.($shown['first'] === 1
            ? 'newest '.number_format($shown['last']).' of '.number_format($shown['total']).' files'
            : 'files '.number_format($shown['first']).'–'.number_format($shown['last']).' of '.number_format($shown['total']));
    }
}
