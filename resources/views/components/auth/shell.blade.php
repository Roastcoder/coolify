@props([
    'title',
    'description' => null,
])

<section class="auth-shell application-settings-form">
    <div class="auth-shell-content">
        <div class="auth-card">
            <div class="auth-card-heading">
                <div class="mb-4 flex items-center gap-2.5">
                    <img src="/standalonecoders-mark.svg" alt="" class="size-9 shrink-0" />
                    <span class="text-lg tracking-tight text-black dark:text-white"><span class="font-extrabold">Standalone</span><span class="font-normal">Coders</span></span>
                </div>
                <h1>{{ $title }}</h1>
                @if ($description)
                    <p>{{ $description }}</p>
                @endif
            </div>

            <div class="auth-card-body">
                {{ $slot }}
            </div>

            @isset($footer)
                <footer class="auth-card-footer">
                    {{ $footer }}
                </footer>
            @endisset
        </div>
    </div>
</section>
