 <!-- ======= Sidebar ======= -->
 <aside id="sidebar" class="sidebar">

    <ul class="sidebar-nav" id="sidebar-nav">

      <li class="nav-item">
        <a class="nav-link " href="{{url('admin/dashboard') }}">
          <i class="bi bi-grid"></i>
          <span>Dashboard </span>
        </a>
      </li><!-- End Dashboard Nav -->

      <li class="nav-item">
        <a class="nav-link collapsed @if(Request::segment(2) == 'addjobs') active @endif" href="{{url('admin/add-clients')}}">
          <i class="bi bi-person"></i>
          <span>Add Client Ledger</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link collapsed" href="{{route('viewclient')}}">
          <i class="bi bi-person"></i>
          <span>View Client</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link collapsed" href="{{route('receipt')}}">
          <i class="bi bi-person"></i>
          <span>Reciept</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link collapsed" href="{{route('payment')}}">
          <i class="bi bi-person"></i>
          <span>Payment</span>
        </a>
      </li>
      
    </ul>

  </aside><!-- End Sidebar-->
