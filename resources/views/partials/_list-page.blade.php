{{--
    Where this page of a long list stands among the rest.

    A list of finished work is drawn a page at a time, newest first; see
    WorkFileModel::LIST_LIMIT. A page that does not say so reads as everything
    there is, and its totals as the whole answer — so it says how many it
    shows, of how many, and leads to the newer and the older ones.

    @param array|null  $shown  App\Support\ListPage::of(); nothing is drawn
                               when the whole list is on the page
    @param string|null $note   what on this page covers only what it shows
--}}
@if ($shown)
    <div class="alert alert-info d-flex flex-wrap align-items-center justify-content-between gap-2 py-2 mb-3 list-page">
        <span>
            @if ($shown['first'] === 1)
                Showing the newest {{ number_format($shown['last']) }} of {{ number_format($shown['total']) }} files.
            @else
                Showing files {{ number_format($shown['first']) }}&ndash;{{ number_format($shown['last']) }}
                of {{ number_format($shown['total']) }}, newest first.
            @endif
            {{ $note ?? '' }}
        </span>
        <span class="d-flex gap-2">
            @if ($shown['newer'])
                <a href="{{ $shown['newer'] }}" class="btn btn-sm btn-outline-secondary">&larr; Newer</a>
            @endif
            @if ($shown['older'])
                <a href="{{ $shown['older'] }}" class="btn btn-sm btn-primary">Older &rarr;</a>
            @endif
        </span>
    </div>
@endif
