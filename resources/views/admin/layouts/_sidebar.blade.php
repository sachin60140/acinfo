@php
  $req = request();

  /*
   * Which menu item is current, decided by route name rather than URL segment.
   * Segment counting broke as soon as a route's shape changed — admin/party/
   * edit/{id} has an id where the type used to be, so editing a party lit up
   * nothing at all. Route names cannot drift out of step with routes/web.php.
   */

  // The party screens that carry their type in the URL say so directly; edit and
  // statement carry only an id, so the type is looked up — one lightweight
  // query, and only on those two routes.
  $activePartyType = match (true) {
      $req->routeIs('party.index', 'party.create', 'party.entry') => $req->route('type'),
      $req->routeIs('party.edit', 'party.statement') => \App\Models\PartyModel::whereKey($req->route('id'))->value('party_type'),
      default => null,
  };

  /*
   * NiceAdmin has no .active rule of its own — its highlighted look is the
   * absence of .collapsed. Emitting both, as this sidebar used to, styled every
   * item as inactive. assets/css/nav.css then makes the state obvious.
   */
  $navClass = fn (bool $active) => 'nav-link'.($active ? ' active' : ' collapsed');

  /*
   * The menu as data.
   *
   * Written out four times over before, once per section, which is why a group
   * could not be asked whether it holds the current page without repeating its
   * route names a fifth time. As data both questions — what to draw, and which
   * section is the one being worked in — are answered from one list.
   *
   * Each group's key is what the collapse state is stored under, so renaming one
   * silently reopens that section for everybody. They are not labels; leave them
   * alone even when the heading above them changes.
   */
  /*
   * The old Client Ledger, closed by the owner on 2026-09-23: nothing new goes
   * into it, so Add, Receipt and Payment are gone from the menu — their pages
   * say where to go instead, and belong to View Client. While any client still
   * holds a balance in it, the screen that carries those balances to Customers.
   */
  $oldBookOpen = \App\Models\ClientLedgerModel::hasOpenBalances();

  $groups = [
      'client-ledger' => [
          'label' => 'Client Ledger',
          'items' => array_values(array_filter([
              // A client's statement and password screens belong to this item;
              // neither has a menu entry of its own, so without them the menu
              // goes blank on those pages. So do the closed ones.
              ['label' => 'View Client', 'icon' => 'bi-people', 'href' => route('viewclient'), 'active' => $req->routeIs('viewclient', 'clientstatement', 'clientpassword', 'addclients', 'receipt', 'payment')
                  || (! $oldBookOpen && $req->routeIs('client.closebook'))],
              $oldBookOpen
                  ? ['label' => 'Close Old Book', 'icon' => 'bi-journal-check', 'href' => route('client.closebook'), 'active' => $req->routeIs('client.closebook')]
                  : null,
          ])),
      ],
      'vendor-customer' => [
          'label' => 'Vendor & Customer',
          'items' => collect(\App\Models\PartyModel::TYPES)->map(fn ($partyLabel, $partyType) => [
              'label' => $partyLabel.' Ledger',
              'icon' => $partyType === 'vendor' ? 'bi-truck' : 'bi-people',
              'href' => route('party.index', $partyType),
              'active' => $activePartyType === $partyType,
          ])->values()->all(),
      ],
      'work-files' => [
          'label' => 'Work Files',
          'items' => [
              ['label' => 'Receive Files', 'icon' => 'bi-folder-plus', 'href' => route('workfile.receive'), 'active' => $req->routeIs('workfile.receive')],
              // Between receiving papers and sending them out: the order the work happens in.
              ['label' => 'Paper Audit', 'icon' => 'bi-clipboard-check', 'href' => route('workfile.paperaudit'), 'active' => $req->routeIs('workfile.paperaudit', 'workfile.papers')],
              ['label' => 'Give to Vendor', 'icon' => 'bi-truck', 'href' => route('workfile.assign'), 'active' => $req->routeIs('workfile.assign')],
              ['label' => 'Hand-over Sheet', 'icon' => 'bi-file-earmark-text', 'href' => route('workfile.dispatchsheet'), 'active' => $req->routeIs('workfile.dispatchsheet')],
              // The other way work leaves that screen, so beside it.
              ['label' => 'In-house Work', 'icon' => 'bi-house-gear', 'href' => route('workfile.inhouse'), 'active' => $req->routeIs('workfile.inhouse')],
              ['label' => 'Return from Vendor', 'icon' => 'bi-arrow-return-left', 'href' => route('workfile.vendorreturn'), 'active' => $req->routeIs('workfile.vendorreturn')],
              ['label' => 'Return to Customer', 'icon' => 'bi-arrow-counterclockwise', 'href' => route('workfile.customerreturn'), 'active' => $req->routeIs('workfile.customerreturn')],
              // Beside Return to Customer and named apart from it: that one is a
              // refund, this one is finished work going home.
              ['label' => 'Hand Over Papers', 'icon' => 'bi-send-check', 'href' => route('workfile.handover'), 'active' => $req->routeIs('workfile.handover')],
              ['label' => 'Update Status', 'icon' => 'bi-flag', 'href' => route('workfile.status'), 'active' => $req->routeIs('workfile.status')],
              ['label' => 'Approved Files', 'icon' => 'bi-patch-check', 'href' => route('workfile.approved'), 'active' => $req->routeIs('workfile.approved')],
              // Editing one file belongs to the list it was opened from.
              ['label' => 'All Work Files', 'icon' => 'bi-folder2-open', 'href' => route('workfile.index'), 'active' => $req->routeIs('workfile.index', 'workfile.edit')],
          ],
      ],
      'reports' => [
          'label' => 'Reports',
          'items' => [
              ['label' => 'Profit Report', 'icon' => 'bi-graph-up-arrow', 'href' => route('report.profit'), 'active' => $req->routeIs('report.profit')],
              ['label' => 'Work Report', 'icon' => 'bi-file-earmark-bar-graph', 'href' => route('report.files'), 'active' => $req->routeIs('report.files')],
              ['label' => 'Expense Report', 'icon' => 'bi-cash-coin', 'href' => route('report.expenses'), 'active' => $req->routeIs('report.expenses')],
              ['label' => 'Vendor Report', 'icon' => 'bi-truck', 'href' => route('report.vendors'), 'active' => $req->routeIs('report.vendors')],
              ['label' => 'Not Yet Collected', 'icon' => 'bi-hourglass-split', 'href' => route('report.uncollected'), 'active' => $req->routeIs('report.uncollected')],
          ],
      ],
      /*
       * The lists the rest of the application is built out of.
       *
       * They sat among the work screens, where twelve of the menu's twenty-four
       * entries were one section and three of them were things the office set up
       * once and then never opened again. Kept apart, and shut to begin with:
       * somebody adding a paper type goes looking for it, and nobody else should
       * have to read past it every day to reach Give to Vendor.
       */
      'setup' => [
          'label' => 'Setup',
          'shut' => true,
          'items' => [
              ['label' => 'Work Types', 'icon' => 'bi-briefcase', 'href' => route('worktype.index'), 'active' => $req->routeIs('worktype.index', 'worktype.edit')],
              ['label' => 'Expense Types', 'icon' => 'bi-cash-stack', 'href' => route('expensetype.index'), 'active' => $req->routeIs('expensetype.index', 'expensetype.edit')],
              ['label' => 'Paper Types', 'icon' => 'bi-card-checklist', 'href' => route('papertype.index'), 'active' => $req->routeIs('papertype.index', 'papertype.edit')],
              ['label' => 'Limits', 'icon' => 'bi-sliders', 'href' => route('setting.index'), 'active' => $req->routeIs('setting.index')],
          ],
      ],
  ];

  /*
   * Which sections the reader has changed their mind about, read from their own
   * cookie and rendered into the markup rather than applied by script
   * afterwards.
   *
   * Afterwards would mean every shut section appearing for an instant on every
   * page load, and this sidebar is replaced wholesale on every visit — see
   * REGIONS in resources/js/navigate.js — so it would flicker on each one. The
   * server already knows; it may as well say so in the markup it is already
   * sending. The cookie is left unencrypted for this reason, named in
   * bootstrap/app.php, and holds nothing but these keys.
   *
   * A key means "not how this section starts", not "shut". It read as "shut"
   * while every section started open, and every cookie already written still
   * says the same thing about those sections — but Setup starts shut, and a
   * cookie that could only record shutting would have no way to remember
   * somebody opening it.
   */
  $flipped = array_flip(array_filter(explode(',', (string) $req->cookie('nav_collapsed'))));
@endphp

<!-- ======= Sidebar ======= -->
<aside id="sidebar" class="sidebar">

  <ul class="sidebar-nav" id="sidebar-nav">

    <li class="nav-item">
      {{-- Outside every group: the only screen here with no route name of its
           own, and the one there is never a reason to hide. --}}
      <a class="{{ $navClass($req->is('admin/dashboard')) }}" href="{{ url('admin/dashboard') }}">
        <i class="bi bi-grid"></i>
        <span>Dashboard</span>
      </a>
    </li><!-- End Dashboard Nav -->

    @foreach ($groups as $key => $group)
      @php
        $holdsCurrent = collect($group['items'])->contains('active', true);
        $startsOpen = ! ($group['shut'] ?? false);
        $open = isset($flipped[$key]) ? ! $startsOpen : $startsOpen;
      @endphp

      {{-- data-nav-starts-open: how this section begins for somebody who has
           never touched it, so the script knows whether a click is a change of
           mind worth writing down or a return to the way things were. --}}
      <li
        class="nav-item nav-group{{ $open ? '' : ' is-shut' }}"
        data-nav-group="{{ $key }}"
        data-nav-starts-open="{{ $startsOpen ? 'true' : 'false' }}">
        {{-- A button rather than a heading with a handler hung on it: this does
             something instead of going somewhere, so it has to be reachable by
             keyboard and has to announce its own state. --}}
        <button
          type="button"
          class="nav-heading nav-heading--toggle{{ $holdsCurrent ? ' nav-heading--current' : '' }}"
          aria-expanded="{{ $open ? 'true' : 'false' }}"
          aria-controls="nav-group-{{ $key }}">
          <span>{{ $group['label'] }}</span>
          {{-- Marks the section the reader is inside even while it is shut, so
               closing the section you are working in does not lose you. --}}
          @if ($holdsCurrent)
            <i class="bi bi-dot nav-heading__here" title="The page you are on is in this section"></i>
          @endif
          <i class="bi bi-chevron-down nav-heading__caret"></i>
        </button>

        {{-- hidden rather than a class, so a section that is shut is shut for a
             screen reader and for find-in-page too, not only to the eye. --}}
        <ul class="nav-group__items" id="nav-group-{{ $key }}" @if (! $open) hidden @endif>
          @foreach ($group['items'] as $item)
            <li class="nav-item">
              <a class="{{ $navClass($item['active']) }}" href="{{ $item['href'] }}">
                <i class="bi {{ $item['icon'] }}"></i>
                <span>{{ $item['label'] }}</span>
              </a>
            </li>
          @endforeach
        </ul>
      </li>
    @endforeach

  </ul>

</aside><!-- End Sidebar-->
