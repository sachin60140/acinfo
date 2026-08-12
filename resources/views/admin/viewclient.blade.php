@extends('admin.layouts.app')

@section('title', 'Client Ledger | Ac Info')


@section('style')

    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">


@endsection

@section('content')
    <div class="pagetitle">
        <h1>Dashboard</h1>
        <nav>
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="{{ url('admin/dashboard') }}">Home</a></li>
                <li class="breadcrumb-item">Clinets</li>
                
            </ol>
        </nav>
    </div><!-- End Page Title -->
    <section class="section dashboard">
        @if (Session::has('success'))
            <div class="alert alert-primary bg-primary text-light border-0 alert-dismissible fade show" role="alert">
                {{ Session::get('success') }}
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"
                    aria-label="Close"></button>
            </div>
        @endif

        @if (Session::has('error'))
            <div class="alert alert-danger bg-danger text-light border-0 alert-dismissible fade show" role="alert">
                {{ Session::get('error') }}
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"
                    aria-label="Close"></button>
            </div>
        @endif

        <div class="row">
            <div class="col-lg-12">

                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Client Ledger</h5>
                        {{-- <h5 class="card-title"><a href="{{url("admin/employee/generate-pdf")}}" target="_blank" > click me to pdf </a></h5> --}}

                        <!-- Table with stripped rows -->
                        <table class="table display" id="example">
                            <thead>
                                <tr>
                                    <th scope="col">#</th>
                                    <th scope="col">Name</th>
                                    <th scope="col">Mobile</th>
                                    <th scope="col" style="text-align: right;">Amount</th>
                                    <th scope="col" style="text-align: right;">Statement</th>
                                    <th scope="col" style="text-align: right;">Password</th>


                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($data as $items)
                                    <tr>
                                        <td>{{ $items->id }}</td>
                                        <td>{{ $items->name }}</td>
                                        <td>{{ $items->mobile }}</td>
                                        <td style="text-align: right">
                                            {{ number_format((float) $items->current_balance, 2, '.', '') }}
                                        </td>
                                        <td>
                                            <a href="{{ url('admin/client/statement/' . $items->id) }}"
                                                class="link-primary float-end " target="_blank">Statement</a>
                                        </td>
                                        <td>
                                            <a href="{{ route('clientpassword', $items->id) }}"
                                                class="link-primary float-end">Set Password</a>
                                        </td>
                                    </tr>
                                @endforeach

                            </tbody>
                        </table>
                        <!-- End Table with stripped rows -->

                    </div>
                </div>

            </div>
        </div>

    @endsection

    @section('script')
        <script src="https://code.jquery.com/jquery-3.7.0.js"></script>
        <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
        <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
        <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>

        <script>
            $(document).ready(function() {
                $('#example').DataTable({
                    dom: 'Bfrtip',
                    buttons: [
                        'copyHtml5',
                        'excelHtml5',
                        'csvHtml5',
                        'pdfHtml5',
                    ],
                    "pageLength": 50,

                    "aaSorting": [
                        [3, 'desc']
                    ],
                });
            });
        </script>
    @endsection
