<!DOCTYPE html>
<html lang="en" data-swap-nav="{{ config('app.swap_navigation') ? '1' : '0' }}">

<head>
  <meta charset="utf-8">
  <meta content="width=device-width, initial-scale=1.0" name="viewport">

  <title> @yield('title')</title>

  <meta content="" name="description">
  <meta content="" name="keywords">

  <!-- Favicons -->
  <link href="{{url('assets/img/favicon.png')}}" rel="icon">
  <link href="{{url('assets/img/apple-touch-icon.png')}}" rel="apple-touch-icon">

  <!-- Google Fonts -->
  <link href="https://fonts.gstatic.com" rel="preconnect">
  <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,300i,400,400i,600,600i,700,700i|Nunito:300,300i,400,400i,600,600i,700,700i|Poppins:300,300i,400,400i,500,500i,600,600i,700,700i" rel="stylesheet">

  <!-- Vendor CSS Files -->
  <link href="{{url('assets/vendor/bootstrap/css/bootstrap.min.css')}}" rel="stylesheet">
  <link href="{{url('assets/vendor/bootstrap-icons/bootstrap-icons.css')}}" rel="stylesheet">
  <link href="{{url('assets/vendor/boxicons/css/boxicons.min.css')}}" rel="stylesheet">
  <link href="{{url('assets/vendor/quill/quill.snow.css')}}" rel="stylesheet">
  <link href="{{url('assets/vendor/quill/quill.bubble.css')}}" rel="stylesheet">
  <link href="{{url('assets/vendor/remixicon/remixicon.css')}}" rel="stylesheet">
  <link href="{{url('assets/vendor/simple-datatables/style.css')}}" rel="stylesheet">

  <!-- Template Main CSS File -->
  <link href="{{url('assets/css/style.css')}}" rel="stylesheet">
  <link href="{{url('assets/css/datepicker.css')}}" rel="stylesheet">
  @yield('style')
  {{-- After @yield so a page's own styles cannot outrank these. --}}
  <link href="{{url('assets/css/nav.css')}}" rel="stylesheet">
  <link href="{{url('assets/css/responsive.css')}}" rel="stylesheet">

  {{-- Vue bundle. Built into public/build, which is committed, because the
       production host deploys by git pull and has no npm step. --}}
  @vite(['resources/css/app.css', 'resources/js/app.js'])

</head>

<body>
    {{-- Mounted once for the whole admin area: it listens for form submissions
         and navigations, so no page has to opt in. --}}
    <div data-vue="vue-loader"></div>

    @include('admin.layouts._header')
    @include('admin.layouts._sidebar')

    <main id="main" class="main">
        @yield('content')
    </main>

    @include('admin.layouts._footer')

<!-- Vendor JS Files -->
<script src="{{url('assets/vendor/apexcharts/apexcharts.min.js')}}"></script>
<script src="{{url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js')}}"></script>
<script src="{{url('assets/vendor/chart.js/chart.umd.js')}}"></script>
<script src="{{url('assets/vendor/echarts/echarts.min.js')}}"></script>
<script src="{{url('assets/vendor/quill/quill.min.js')}}"></script>
<script src="{{url('assets/vendor/simple-datatables/simple-datatables.js')}}"></script>
<script src="{{url('assets/vendor/tinymce/tinymce.min.js')}}"></script>
<script src="{{url('assets/vendor/php-email-form/validate.js')}}"></script>

<!-- Template Main JS File -->
<script src="{{url('assets/js/main.js')}}"></script>
<script src="{{url('assets/js/datepicker.js')}}"></script>
@yield('script')

</body>

</html>