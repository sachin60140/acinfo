@php
  $req = request();

  // Same rule as the admin and client sidebars: NiceAdmin's highlighted look is
  // the absence of .collapsed, so an item must not carry both.
  $navClass = fn (bool $active) => 'nav-link'.($active ? ' active' : ' collapsed');
@endphp

<!-- ======= Sidebar ======= -->
<aside id="sidebar" class="sidebar">

  <ul class="sidebar-nav" id="sidebar-nav">

    <li class="nav-item">
      <a class="{{ $navClass($req->routeIs('customer.dashboard')) }}" href="{{ route('customer.dashboard') }}">
        <i class="bi bi-grid"></i>
        <span>Home</span>
      </a>
    </li>

    <li class="nav-item">
      <a class="{{ $navClass($req->routeIs('customer.files')) }}" href="{{ route('customer.files') }}">
        <i class="bi bi-folder2-open"></i>
        <span>My Files</span>
      </a>
    </li>

    <li class="nav-item">
      <a class="{{ $navClass($req->routeIs('customer.statement')) }}" href="{{ route('customer.statement') }}">
        <i class="bi bi-journal-text"></i>
        <span>My Statement</span>
      </a>
    </li>

  </ul>

</aside><!-- End Sidebar -->
