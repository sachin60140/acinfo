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
@endphp

<!-- ======= Sidebar ======= -->
<aside id="sidebar" class="sidebar">

  <ul class="sidebar-nav" id="sidebar-nav">

    <li class="nav-item">
      {{-- The only screen here with no route name of its own. --}}
      <a class="{{ $navClass($req->is('admin/dashboard')) }}" href="{{ url('admin/dashboard') }}">
        <i class="bi bi-grid"></i>
        <span>Dashboard</span>
      </a>
    </li><!-- End Dashboard Nav -->

    <li class="nav-heading">Client Ledger</li>

    <li class="nav-item">
      <a class="{{ $navClass($req->routeIs('addclients')) }}" href="{{ route('addclients') }}">
        <i class="bi bi-person-plus"></i>
        <span>Add Client Ledger</span>
      </a>
    </li>

    <li class="nav-item">
      {{-- A client's statement and password screens belong to this item; neither
           has a menu entry of its own, so without them the menu goes blank. --}}
      <a class="{{ $navClass($req->routeIs('viewclient', 'clientstatement', 'clientpassword')) }}" href="{{ route('viewclient') }}">
        <i class="bi bi-people"></i>
        <span>View Client</span>
      </a>
    </li>

    <li class="nav-item">
      <a class="{{ $navClass($req->routeIs('receipt')) }}" href="{{ route('receipt') }}">
        <i class="bi bi-receipt"></i>
        <span>Receipt</span>
      </a>
    </li>

    <li class="nav-item">
      <a class="{{ $navClass($req->routeIs('payment')) }}" href="{{ route('payment') }}">
        <i class="bi bi-cash-coin"></i>
        <span>Payment</span>
      </a>
    </li>

    <li class="nav-heading">Vendor &amp; Customer</li>

    @foreach (\App\Models\PartyModel::TYPES as $partyType => $partyLabel)
      <li class="nav-item">
        <a class="{{ $navClass($activePartyType === $partyType) }}" href="{{ route('party.index', $partyType) }}">
          <i class="bi {{ $partyType === 'vendor' ? 'bi-truck' : 'bi-people' }}"></i>
          <span>{{ $partyLabel }} Ledger</span>
        </a>
      </li>
    @endforeach

    <li class="nav-heading">Work Files</li>

    <li class="nav-item">
      <a class="{{ $navClass($req->routeIs('workfile.receive')) }}" href="{{ route('workfile.receive') }}">
        <i class="bi bi-folder-plus"></i>
        <span>Receive Files</span>
      </a>
    </li>

    <li class="nav-item">
      <a class="{{ $navClass($req->routeIs('workfile.assign')) }}" href="{{ route('workfile.assign') }}">
        <i class="bi bi-truck"></i>
        <span>Give to Vendor</span>
      </a>
    </li>

    <li class="nav-item">
      <a class="{{ $navClass($req->routeIs('workfile.vendorreturn')) }}" href="{{ route('workfile.vendorreturn') }}">
        <i class="bi bi-arrow-return-left"></i>
        <span>Return from Vendor</span>
      </a>
    </li>

    <li class="nav-item">
      <a class="{{ $navClass($req->routeIs('workfile.customerreturn')) }}" href="{{ route('workfile.customerreturn') }}">
        <i class="bi bi-arrow-counterclockwise"></i>
        <span>Return to Customer</span>
      </a>
    </li>

    <li class="nav-item">
      <a class="{{ $navClass($req->routeIs('workfile.status')) }}" href="{{ route('workfile.status') }}">
        <i class="bi bi-flag"></i>
        <span>Update Status</span>
      </a>
    </li>

    <li class="nav-item">
      {{-- Editing one file belongs to the list it was opened from. --}}
      <a class="{{ $navClass($req->routeIs('workfile.approved')) }}" href="{{ route('workfile.approved') }}">
        <i class="bi bi-patch-check"></i>
        <span>Approved Files</span>
      </a>
    </li>

    <li class="nav-item">
      <a class="{{ $navClass($req->routeIs('workfile.index', 'workfile.edit')) }}" href="{{ route('workfile.index') }}">
        <i class="bi bi-folder2-open"></i>
        <span>All Work Files</span>
      </a>
    </li>

    <li class="nav-item">
      <a class="{{ $navClass($req->routeIs('worktype.index', 'worktype.edit')) }}" href="{{ route('worktype.index') }}">
        <i class="bi bi-briefcase"></i>
        <span>Work Types</span>
      </a>
    </li>

    <li class="nav-heading">Reports</li>

    <li class="nav-item">
      <a class="{{ $navClass($req->routeIs('report.files')) }}" href="{{ route('report.files') }}">
        <i class="bi bi-file-earmark-bar-graph"></i>
        <span>Work Report</span>
      </a>
    </li>

  </ul>

</aside><!-- End Sidebar-->
