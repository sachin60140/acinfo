<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * A screen, served either as a page or as data.
 *
 * Client-side routing needs every screen's payload available as JSON. The
 * temptation is to write a second set of endpoints for it, and the reason not
 * to is that the two would drift: a column added to the page and forgotten in
 * the API is a report that disagrees with itself depending on how you arrived
 * at it.
 *
 * So a screen is described once — which component draws it, and what that
 * component is handed — and this decides the representation. A normal request
 * gets the Blade page it always got. A request that wants JSON gets the same
 * props, from the same code, one step earlier.
 *
 * The props therefore have to be built in the controller rather than in a @php
 * block in the view, which is the whole reason those blocks are moving.
 */
class Screen
{
    /**
     * @param  array  $props  what the component is handed
     * @param  array  $page   furniture around it — headings, filter state,
     *                        summary figures. Scalars only: it goes to the view
     *                        AND over the wire, so a route component can render
     *                        the same furniture once it takes that over.
     * @param  array  $view   Blade-only extras, never serialised. Eloquent
     *                        collections and Carbon instances belong here: the
     *                        rows are already in $props, and sending them twice
     *                        both bloats the response and ships database columns
     *                        the browser never asked for.
     */
    public function __construct(
        private string $view,
        private string $mount,
        private array $props,
        private array $page = [],
        private array $viewOnly = [],
    ) {}

    public static function make(string $view, string $mount, array $props, array $page = [], array $viewOnly = []): self
    {
        return new self($view, $mount, $props, $page, $viewOnly);
    }

    public function toResponse(Request $request)
    {
        if ($request->wantsJson()) {
            return response()->json([
                'mount' => $this->mount,
                'props' => $this->props,
                'page' => $this->page,
            ]);
        }

        return view($this->view, $this->page + $this->viewOnly + [
            'screenMount' => $this->mount,
            'screenProps' => $this->props,
        ]);
    }

    /** The component's props, for tests and for callers that need them directly. */
    public function props(): array
    {
        return $this->props;
    }

    public function mount(): string
    {
        return $this->mount;
    }
}
