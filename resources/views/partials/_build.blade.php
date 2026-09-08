{{--
    Which build is running.

    Read straight from git's bookkeeping by App\Support\Build, so it is the code
    on the machine rather than a number somebody remembered to change. It is
    here to answer one question after a deploy — did that land? — which is why
    it is quiet on the page and exact in the markup: the title carries the full
    date and time for anyone who needs to say it out loud.
--}}
<span class="build-stamp" title="Build {{ \App\Support\Build::at()->format('d-m-Y H:i') }}">
    {{ \App\Support\Build::label() }}
</span>
