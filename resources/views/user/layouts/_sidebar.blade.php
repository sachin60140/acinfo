@php
  $req = request();

  // Same rule as the admin sidebar: NiceAdmin's highlighted look is the absence
  // of .collapsed, so an item must not carry both. Neither item here had an
  // active check at all, which left the client's menu permanently blank.
  $navClass = fn (bool $active) => 'nav-link'.($active ? ' active' : ' collapsed');
@endphp

<!-- ======= Sidebar ======= -->
<aside id="sidebar" class="sidebar">

  <ul class="sidebar-nav" id="sidebar-nav">

    <li class="nav-item">
      <a class="{{ $navClass($req->routeIs('userdashboard')) }}" href="{{ route('userdashboard') }}">
        <i class="bi bi-grid"></i>
        <span>Dashboard</span>
      </a>
    </li><!-- End Dashboard Nav -->

    <li class="nav-item">
      <a class="{{ $navClass($req->routeIs('userstatement')) }}" href="{{ route('userstatement') }}">
        <i class="bi bi-journal-text"></i>
        <span>Ledger</span>
      </a>
    </li>

  </ul>

</aside><!-- End Sidebar-->
