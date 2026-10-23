@props(['title', 'intro' => null])

<main class="page page--narrow">
    <section class="panel">
        <h1 class="panel__title">{{ $title }}</h1>
        @if ($intro)
            <p class="panel__intro">{{ $intro }}</p>
        @endif

        {{ $slot }}
    </section>

    @isset($footer)
        <p class="panel__footer">{{ $footer }}</p>
    @endisset
</main>
