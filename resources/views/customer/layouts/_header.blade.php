<!-- ======= Header ======= -->
<header id="header" class="header fixed-top d-flex align-items-center">

  <div class="d-flex align-items-center justify-content-between">
    <a href="{{ route('customer.dashboard') }}" class="logo d-flex align-items-center">
      <img src="{{ url('assets/img/logo.png') }}" alt="">
      <span class="d-none d-lg-block">Ac Info</span>
    </a>
    <i class="bi bi-list toggle-sidebar-btn"></i>
  </div><!-- End Logo -->

  <nav class="header-nav ms-auto">
    <ul class="d-flex align-items-center">

      <li class="nav-item dropdown pe-3">

        <a class="nav-link nav-profile d-flex align-items-center pe-0" href="#" data-bs-toggle="dropdown">
          <img src="{{ url('assets/img/profile-img.jpg') }}" alt="Profile" class="rounded-circle">
          <span class="d-none d-md-block dropdown-toggle ps-2">{{ session('customer_name') }}</span>
        </a><!-- End Profile Image Icon -->

        <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow profile">
          <li class="dropdown-header">
            <h6>{{ session('customer_name') }}</h6>
          </li>
          <li>
            <hr class="dropdown-divider">
          </li>

          <li>
            <a class="dropdown-item d-flex align-items-center" href="{{ route('customer.password') }}">
              <i class="bi bi-key"></i>
              <span>Change Password</span>
            </a>
          </li>

          <li>
            <hr class="dropdown-divider">
          </li>

          <li>
            <form action="{{ route('customer.logout') }}" method="POST">
              @csrf
              <button type="submit" class="dropdown-item d-flex align-items-center">
                <i class="bi bi-box-arrow-right"></i>
                <span>Sign Out</span>
              </button>
            </form>
          </li>

        </ul><!-- End Profile Dropdown Items -->
      </li><!-- End Profile Nav -->

    </ul>
  </nav><!-- End Icons Navigation -->

</header><!-- End Header -->
