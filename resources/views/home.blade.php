{{-- Fork-only: public home page. Guests opening / land here; signed-in users get the dashboard. --}}
@php
    $product = config('custom_build.product_name');
    $github = 'https://github.com/'.config('custom_build.repository');
    $developerUrl = 'https://github.com/'.Str::before(config('custom_build.repository'), '/');
    $contact = config('custom_build.contact_email');
    $installCommand = 'curl -fsSL https://raw.githubusercontent.com/'.config('custom_build.repository').'/main/custom/install.sh | sudo bash';
    $features = [
        ['Deploy from Git or Docker', 'Push to GitHub, GitLab or Bitbucket and it builds and ships. Preview deployments for pull requests.', 'M5 12h14M12 5l7 7-7 7'],
        ['Managed databases', 'PostgreSQL, MySQL, MariaDB, MongoDB, Redis and more, with scheduled backups to S3.', 'M4 7c0-1.7 3.6-3 8-3s8 1.3 8 3-3.6 3-8 3-8-1.3-8-3zm0 0v10c0 1.7 3.6 3 8 3s8-1.3 8-3V7M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3'],
        ['Built-in mail server', 'Mailboxes, aliases and webmail on your own domain, with DKIM, SPF and DMARC checked for you.', 'M3 7l9 6 9-6M5 5h14a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2z'],
        ['Automatic HTTPS', "Free Let's Encrypt certificates for every domain, renewed on their own.", 'M12 3l7 4v5c0 4.4-3 8.3-7 9-4-.7-7-4.6-7-9V7l7-4z'],
        ['Secure sign-in', 'Two-factor codes by authenticator app or SMS, passkeys, and teams with roles.', 'M8 11V7a4 4 0 118 0v4M6 11h12v9H6z'],
        ['One-click updates', 'New builds show up in Settings and install with a single click.', 'M4 4v6h6M20 20v-6h-6M5.6 15A8 8 0 0018.4 9M18.4 9H14m-8.4 6H10'],
    ];
    $plans = [
        ['name' => 'Self-host', 'price' => 'Free', 'period' => 'forever', 'blurb' => 'Run it on your own server.', 'items' => ['Unlimited apps and databases', 'Built-in mail server', 'All features, open source', 'Community support on GitHub'], 'cta' => 'Install now', 'href' => '#open-source'],
        ['name' => 'Developer', 'price' => '₹499', 'period' => '/month', 'blurb' => 'For side projects.', 'items' => ['1 small app', '1 GB RAM', 'Free HTTPS', 'Email support'], 'cta' => 'Contact us', 'href' => "mailto:{$contact}?subject=Developer plan"],
        ['name' => 'Startup', 'price' => '₹999', 'period' => '/month', 'blurb' => 'For growing products.', 'items' => ['2 apps', '2 GB RAM', 'Daily backups', 'Email support'], 'cta' => 'Contact us', 'href' => "mailto:{$contact}?subject=Startup plan", 'popular' => true],
        ['name' => 'Business', 'price' => '₹2,499', 'period' => '/month', 'blurb' => 'For teams in production.', 'items' => ['5 apps', '4 GB RAM', 'Daily backups', 'Priority support'], 'cta' => 'Contact us', 'href' => "mailto:{$contact}?subject=Business plan"],
        ['name' => 'Agency', 'price' => '₹4,999', 'period' => '/month', 'blurb' => 'For client work at scale.', 'items' => ['10 apps', '8 GB RAM', 'Daily backups', 'Priority support'], 'cta' => 'Contact us', 'href' => "mailto:{$contact}?subject=Agency plan"],
    ];
@endphp
<!DOCTYPE html>
<html lang="en" class="dark scroll-smooth">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $product }} | Deploy apps, databases and email on your own servers</title>
    <meta name="description" content="{{ $product }} is an open-source platform to deploy apps, databases and email on your own servers.">
    <link rel="icon" href="/standalonecoders-favicon.png" type="image/png">
    <link rel="apple-touch-icon" href="/standalonecoders-apple-touch-icon.png">
    @vite(['resources/css/app.css'])
</head>

<body class="min-h-screen bg-[#0b1120] font-sans text-slate-200 antialiased">
    <header class="sticky top-0 z-50 border-b border-white/[0.06] bg-[#0b1120]/80 backdrop-blur">
        <nav class="mx-auto flex h-16 max-w-6xl items-center gap-6 px-4 sm:px-6" aria-label="Main">
            <a href="{{ route('home') }}" class="flex items-center gap-2">
                <img src="/standalonecoders-mark.svg" alt="" class="size-7">
                <span class="text-[15px] tracking-tight text-white"><span class="font-extrabold">Standalone</span>Coders <span class="text-slate-400">Cloud</span></span>
            </a>
            <div class="ml-auto hidden items-center gap-6 text-sm text-slate-400 md:flex">
                <a href="#features" class="hover:text-white">Features</a>
                <a href="#plans" class="hover:text-white">Plans</a>
                <a href="#open-source" class="hover:text-white">Open source</a>
                <a href="#developer" class="hover:text-white">Developer</a>
            </div>
            <div class="ml-auto flex items-center gap-2 md:ml-0">
                @auth
                    <a href="{{ route('dashboard') }}" class="rounded-lg bg-green-500 px-4 py-2 text-sm font-semibold text-green-950 hover:bg-green-400">Dashboard</a>
                @else
                    <a href="{{ route('login') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-300 hover:text-white">Sign in</a>
                    <a href="{{ $github }}" class="hidden rounded-lg bg-green-500 px-4 py-2 text-sm font-semibold text-green-950 hover:bg-green-400 sm:inline-block">Get it free</a>
                @endauth
            </div>
        </nav>
    </header>

    <main>
        {{-- Hero --}}
        <section class="relative overflow-hidden">
            <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(800px_400px_at_50%_-10%,rgb(34_197_94/0.18),transparent_70%)]" aria-hidden="true"></div>
            <div class="relative mx-auto max-w-4xl px-4 pt-20 pb-16 text-center sm:px-6 sm:pt-28">
                <a href="{{ $github }}" class="inline-flex items-center gap-2 rounded-full border border-green-500/30 bg-green-500/10 px-3 py-1 text-xs font-medium text-green-300 hover:border-green-400/60">
                    <span class="size-1.5 rounded-full bg-green-400"></span> Open source, Apache-2.0
                </a>
                <h1 class="mt-6 text-4xl font-semibold tracking-tight text-white sm:text-6xl">Deploy apps, databases and email on <span class="text-green-400">your own servers</span></h1>
                <p class="mx-auto mt-6 max-w-2xl text-lg text-slate-400">{{ $product }} turns any VPS into your own cloud: Git deploys, managed databases, automatic HTTPS and a built-in mail server, without per-seat pricing.</p>
                <div class="mt-10 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    <a href="#plans" class="w-full rounded-lg bg-green-500 px-6 py-3 text-sm font-semibold text-green-950 hover:bg-green-400 sm:w-auto">See plans</a>
                    <a href="{{ $github }}" class="flex w-full items-center justify-center gap-2 rounded-lg border border-white/10 bg-white/[0.04] px-6 py-3 text-sm font-semibold text-white hover:bg-white/[0.08] sm:w-auto">
                        <svg class="size-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 .5a12 12 0 00-3.8 23.4c.6.1.8-.3.8-.6v-2c-3.3.7-4-1.6-4-1.6-.6-1.4-1.4-1.8-1.4-1.8-1.1-.7.1-.7.1-.7 1.2.1 1.9 1.3 1.9 1.3 1.1 1.8 2.8 1.3 3.5 1 .1-.8.4-1.3.8-1.6-2.7-.3-5.5-1.3-5.5-6 0-1.3.5-2.4 1.2-3.2-.1-.3-.5-1.5.1-3.2 0 0 1-.3 3.3 1.2a11.5 11.5 0 016 0C17.3 4.6 18.3 5 18.3 5c.6 1.7.2 2.9.1 3.2.8.8 1.2 1.9 1.2 3.2 0 4.6-2.8 5.6-5.5 5.9.4.4.8 1.1.8 2.2v3.3c0 .3.2.7.8.6A12 12 0 0012 .5z" /></svg>
                        View on GitHub
                    </a>
                </div>
            </div>
        </section>

        {{-- Features --}}
        <section id="features" class="mx-auto max-w-6xl scroll-mt-20 px-4 py-20 sm:px-6">
            <h2 class="text-center text-3xl font-semibold tracking-tight text-white">Everything to run production</h2>
            <p class="mx-auto mt-3 max-w-xl text-center text-slate-400">Built on Coolify and kept up to date with it every day, with email and security added on top.</p>
            <div class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($features as [$title, $text, $icon])
                    <div class="rounded-2xl border border-white/[0.08] bg-white/[0.03] p-6">
                        <div class="flex size-10 items-center justify-center rounded-lg bg-green-500/10 text-green-400">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icon }}" /></svg>
                        </div>
                        <h3 class="mt-4 font-semibold text-white">{{ $title }}</h3>
                        <p class="mt-2 text-sm text-slate-400">{{ $text }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Plans --}}
        <section id="plans" class="scroll-mt-20 border-y border-white/[0.06] bg-white/[0.02]">
            <div class="mx-auto max-w-6xl px-4 py-20 sm:px-6">
                <h2 class="text-center text-3xl font-semibold tracking-tight text-white">Plans</h2>
                <p class="mx-auto mt-3 max-w-xl text-center text-slate-400">Self-host for free, or let us run it for you. Prices in Indian rupees.</p>
                <div class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    @foreach ($plans as $plan)
                        <div @class([
                            'relative flex flex-col rounded-2xl border p-6',
                            'border-green-500/60 bg-green-500/[0.06]' => $plan['popular'] ?? false,
                            'border-white/[0.08] bg-[#0f172a]' => ! ($plan['popular'] ?? false),
                        ])>
                            @if ($plan['popular'] ?? false)
                                <span class="absolute -top-3 left-6 rounded-full bg-green-500 px-2.5 py-0.5 text-xs font-semibold text-green-950">Popular</span>
                            @endif
                            <h3 class="font-semibold text-white">{{ $plan['name'] }}</h3>
                            <p class="mt-1 text-sm text-slate-400">{{ $plan['blurb'] }}</p>
                            <p class="mt-5"><span class="text-3xl font-semibold text-white">{{ $plan['price'] }}</span> <span class="text-sm text-slate-400">{{ $plan['period'] }}</span></p>
                            <ul class="mt-5 flex-1 space-y-2 text-sm text-slate-300">
                                @foreach ($plan['items'] as $item)
                                    <li class="flex gap-2"><svg class="mt-0.5 size-4 shrink-0 text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M5 13l4 4L19 7" /></svg>{{ $item }}</li>
                                @endforeach
                            </ul>
                            <a href="{{ $plan['href'] }}" @class([
                                'mt-6 rounded-lg px-4 py-2.5 text-center text-sm font-semibold',
                                'bg-green-500 text-green-950 hover:bg-green-400' => $plan['popular'] ?? false,
                                'border border-white/10 text-white hover:bg-white/[0.06]' => ! ($plan['popular'] ?? false),
                            ])>{{ $plan['cta'] }}</a>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- Open source --}}
        <section id="open-source" class="mx-auto max-w-4xl scroll-mt-20 px-4 py-20 text-center sm:px-6">
            <h2 class="text-3xl font-semibold tracking-tight text-white">Open source, on GitHub</h2>
            <p class="mx-auto mt-3 max-w-xl text-slate-400">The full source is public under the Apache-2.0 license. Read it, fork it, or install it on your server with one command.</p>
            <div class="mt-8 flex items-center gap-2 rounded-xl border border-white/[0.08] bg-[#060a14] p-2 pl-4 text-left">
                <code class="min-w-0 flex-1 overflow-x-auto whitespace-nowrap py-2 font-mono text-sm text-green-300">{{ $installCommand }}</code>
                <button type="button" class="shrink-0 rounded-lg border border-white/10 px-3 py-2 text-xs font-semibold text-white hover:bg-white/[0.06]"
                    onclick="navigator.clipboard.writeText(@js($installCommand)).then(() => { this.textContent = 'Copied'; setTimeout(() => this.textContent = 'Copy', 1500); })">Copy</button>
            </div>
            <a href="{{ $github }}" class="mt-6 inline-flex items-center gap-2 font-medium text-green-400 hover:text-green-300">{{ Str::after($github, 'https://') }} <span aria-hidden="true">&rarr;</span></a>
        </section>

        {{-- Developer --}}
        <section id="developer" class="scroll-mt-20 border-t border-white/[0.06] bg-white/[0.02]">
            <div class="mx-auto flex max-w-4xl flex-col items-center gap-8 px-4 py-20 text-center sm:flex-row sm:text-left sm:px-6">
                <img src="{{ $developerUrl }}.png?size=200" alt="RoastCoder" class="size-28 shrink-0 rounded-2xl border border-white/10" loading="lazy">
                <div>
                    <p class="text-sm font-medium text-green-400">About the developer</p>
                    <h2 class="mt-1 text-2xl font-semibold text-white">Built by RoastCoder at StandaloneCoders</h2>
                    <p class="mt-3 text-slate-400">StandaloneCoders builds web apps, platforms and AI tools for businesses. {{ $product }} is the platform we use to host our own client projects, opened up so anyone can run it.</p>
                    <div class="mt-5 flex flex-wrap justify-center gap-3 sm:justify-start">
                        <a href="{{ $developerUrl }}" class="rounded-lg border border-white/10 px-4 py-2 text-sm font-semibold text-white hover:bg-white/[0.06]">GitHub profile</a>
                        <a href="mailto:{{ $contact }}" class="rounded-lg border border-white/10 px-4 py-2 text-sm font-semibold text-white hover:bg-white/[0.06]">{{ $contact }}</a>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="border-t border-white/[0.06]">
        <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-3 px-4 py-8 text-sm text-slate-500 sm:flex-row sm:px-6">
            <p>&copy; {{ date('Y') }} StandaloneCoders. A modified version of <a href="https://github.com/coollabsio/coolify" class="hover:text-slate-300">Coolify</a>.</p>
            <div class="flex gap-5">
                <a href="{{ $github }}" class="hover:text-slate-300">GitHub</a>
                <a href="{{ route('login') }}" class="hover:text-slate-300">Sign in</a>
            </div>
        </div>
    </footer>
</body>

</html>
