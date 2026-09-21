@if ($errors->any())
    <div class="alert alert-danger bg-danger text-light border-0 alert-dismissible fade show">
        <ul class="mb-0">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif

@if (Session::has('success'))
    <div class="alert alert-success bg-success text-light border-0 alert-dismissible fade show" role="alert">
        {{ Session::get('success') }}
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif

{{--
    Work just approved: offer to tell each customer, on WhatsApp. Here because
    every page a status is saved from comes back to one of the pages this is
    on — the board, the Work Report, In-house Work, the files list.
--}}
@if (is_array(session('approved')) && session('approved'))
    <div data-vue="vue-approval-share" data-props="{{ \App\Support\VueProps::encode(['files' => session('approved'), 'todayLabel' => now()->format('d-m-Y')]) }}"></div>
@endif

@if (Session::has('error'))
    <div class="alert alert-danger bg-danger text-light border-0 alert-dismissible fade show" role="alert">
        {{ Session::get('error') }}
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif
