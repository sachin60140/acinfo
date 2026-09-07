<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">

    <title>Customer Login | Ac Info</title>
    <meta content="" name="description">
    <meta content="" name="keywords">
    <meta name="robots" content="noindex, nofollow">

    <!-- Favicons -->
    <link href="{{ url('assets/img/favicon.png') }}" rel="icon">
    <link href="{{ url('assets/img/apple-touch-icon.png') }}" rel="apple-touch-icon">

    <!-- Google Fonts -->
    <link href="https://fonts.gstatic.com" rel="preconnect">
    <link href="https://fonts.googleapis.com/css?family=Open+Sans:300,300i,400,400i,600,600i,700,700i|Nunito:300,300i,400,400i,600,600i,700,700i|Poppins:300,300i,400,400i,500,500i,600,600i,700,700i" rel="stylesheet">

    <!-- Vendor CSS Files -->
    <link href="{{ url('assets/vendor/bootstrap/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ url('assets/vendor/bootstrap-icons/bootstrap-icons.css') }}" rel="stylesheet">
    <link href="{{ url('assets/vendor/boxicons/css/boxicons.min.css') }}" rel="stylesheet">

    <!-- Template Main CSS File -->
    <link href="{{ url('assets/css/style.css') }}" rel="stylesheet">
    <link href="{{ url('assets/css/responsive.css') }}" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body>

    <main>
        <div class="container">

            <section class="section register min-vh-100 d-flex flex-column align-items-center justify-content-center py-4">
                <div class="container">
                    <div class="row justify-content-center">
                        <div class="col-lg-4 col-md-6 d-flex flex-column align-items-center justify-content-center">

                            <div class="d-flex justify-content-center py-4">
                                <a href="{{ url('/customer') }}" class="logo d-flex align-items-center w-auto">
                                    <img src="{{ url('assets/img/logo.png') }}" alt="">
                                    <span class="d-none d-lg-block">Acinfo</span>
                                </a>
                            </div><!-- End Logo -->

                            <div class="card mb-3">

                                <div class="card-body">

                                    <div class="pt-4 pb-2">
                                        <h5 class="card-title text-center pb-0 fs-4">Customer Login</h5>
                                        <p class="text-center small">
                                            Sign in to see your files and your account statement
                                        </p>
                                    </div>

                                    @if (session('error'))
                                        <div class="alert alert-danger text-center">
                                            <p class="mb-0">{{ session('error') }}</p>
                                        </div>
                                    @endif

                                    @if ($errors->any())
                                        <div class="alert alert-danger">
                                            <ul class="mb-0 ps-3">
                                                @foreach ($errors->all() as $error)
                                                    <li>{{ $error }}</li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif

                                    <form class="row g-3 needs-validation" novalidate method="POST"
                                        action="{{ route('customer.authenticate') }}">
                                        @csrf

                                        <div class="col-12">
                                            <label for="yourMobile" class="form-label">Mobile Number</label>
                                            <div class="input-group has-validation">
                                                <span class="input-group-text" id="inputGroupPrepend">+91</span>
                                                <input type="tel" name="mobile" class="form-control"
                                                    id="yourMobile" inputmode="numeric" autocomplete="username"
                                                    value="{{ old('mobile') }}" required autofocus>
                                                <div class="invalid-feedback">Please enter your mobile number.</div>
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <label for="yourPassword" class="form-label">Password</label>
                                            <input type="password" name="password" class="form-control"
                                                id="yourPassword" autocomplete="current-password" required>
                                            <div class="invalid-feedback">Please enter your password.</div>
                                            {{-- Enhancement only, as on the other two login screens: the
                                                 field above is server-rendered and submits with JavaScript
                                                 switched off. This adds the show/hide button beside it. --}}
                                            <div data-vue="vue-password-toggle" data-props="{{ \App\Support\VueProps::encode(['target' => 'yourPassword']) }}"></div>
                                        </div>

                                        <div class="col-12">
                                            <button class="btn btn-primary w-100" type="submit">Sign In</button>
                                        </div>

                                        <div class="col-12">
                                            <p class="small mb-0 text-center text-muted">
                                                No password yet? Ask the office to set one for you.
                                            </p>
                                        </div>

                                    </form>

                                </div>
                            </div>

                            <div class="credits">
                                Designed by <a href="https://sarsinfotech.com/">Sars Infotech Pvt Ltd</a>
                            </div>

                        </div>
                    </div>
                </div>

            </section>

        </div>
    </main><!-- End #main -->

    <script src="{{ url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ url('assets/js/main.js') }}"></script>

</body>

</html>
