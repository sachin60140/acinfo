@extends('admin.layouts.app')

@section('title', 'View Ledger | Ac Info')


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
                <li class="breadcrumb-item">Jobs</li>
                <li class="breadcrumb-item active">View Client Statement</li>
                
            </ol>
        </nav>
    </div><!-- End Page Title -->
    <section class="section dashboard">
        <div class="row">
            <div class="col-lg-12">
    
              <div class="card">
                <div class="card-body">
                  <h5 class="card-title">Statement : {{ $getRecords['0']['name'] }}</h5>
                  
                  
                  <!-- Table with stripped rows -->
                  <table class="table display" id="example" style="font-size: 13px;">
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Name</th>
                            <th scope="col">Details</th>
                            <th scope="col">Txn Date</th>
                            <th scope="col">Mode</th>
                            <th scope="col">Amount</th>
                            <th scope="col">Balance</th>
                            <th scope="col">Entry Date</th>
                            

                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $bal = 0;
                        @endphp

                        @foreach ($getRecords as $items)
                            <tr>
                                <td>{{ $items->id }}</td>
                                <td>{{ $items->client_name }}</td>
                                <td>{{ $items->particular }}</td>
                                <td>{{ date('d-M-Y', strtotime($items->txn_date))  }}</td>
                                <td>{{ $items->payment_type }}</td>
                                <td>{{ $items->amount }}</td>
                                <td>
                                    @php
                                        $tot = $bal += $items->amount;
                                        echo $tot;
                                    @endphp

                                </td>
                                <td>{{ $items->created_at }}</td>
                        @endforeach

                    </tbody>
                      
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
    $('#example').DataTable( {
        dom: 'Bfrtip',
        buttons: [
            'copyHtml5',
            'excelHtml5',
            'csvHtml5',
            'pdfHtml5',
        ],
        "pageLength": 50,
        order: 
            [
                [0, 'desc']
            ]
    } );
} );
    </script>
    @endsection
