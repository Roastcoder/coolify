{{-- Fork-only: public home page. Guests opening / land here; signed-in users get the dashboard. --}}
@php
    $product = config('custom_build.product_name');
    $developerUrl = 'https://github.com/'.Str::before(config('custom_build.repository'), '/');
    $contact = config('custom_build.contact_email');
    $features = [
        ['Deploy from Git or Docker', 'Push to GitHub, GitLab or Bitbucket and it builds and ships. Preview deployments for pull requests.', 'M5 12h14M12 5l7 7-7 7'],
        ['Managed databases', 'PostgreSQL, MySQL, MariaDB, MongoDB, Redis and more, with scheduled backups to S3.', 'M4 7c0-1.7 3.6-3 8-3s8 1.3 8 3-3.6 3-8 3-8-1.3-8-3zm0 0v10c0 1.7 3.6 3 8 3s8-1.3 8-3V7M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3'],
        ['Built-in mail server', 'Mailboxes, aliases and webmail on your own domain, with DKIM, SPF and DMARC checked for you.', 'M3 7l9 6 9-6M5 5h14a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2z'],
        ['Automatic HTTPS', "Free Let's Encrypt certificates for every domain, renewed on their own.", 'M12 3l7 4v5c0 4.4-3 8.3-7 9-4-.7-7-4.6-7-9V7l7-4z'],
        ['Secure sign-in', 'Two-factor codes by authenticator app or SMS, passkeys, and teams with roles.', 'M8 11V7a4 4 0 118 0v4M6 11h12v9H6z'],
        ['Logs and monitoring', 'Live build and app logs, with CPU, memory and disk charts for every server.', 'M4 19h16M7 15l3-4 3 2 4-6'],
    ];
    $plans = [
        ['name' => 'Developer', 'price' => '₹499', 'period' => '/month', 'blurb' => 'For side projects.', 'items' => ['1 small app', '1 GB RAM', 'Free HTTPS', 'Email support'], 'cta' => 'Contact us', 'href' => "mailto:{$contact}?subject=Developer plan"],
        ['name' => 'Startup', 'price' => '₹999', 'period' => '/month', 'blurb' => 'For growing products.', 'items' => ['2 apps', '2 GB RAM', 'Daily backups', 'Email support'], 'cta' => 'Contact us', 'href' => "mailto:{$contact}?subject=Startup plan", 'popular' => true],
        ['name' => 'Business', 'price' => '₹2,499', 'period' => '/month', 'blurb' => 'For teams in production.', 'items' => ['5 apps', '4 GB RAM', 'Daily backups', 'Priority support'], 'cta' => 'Contact us', 'href' => "mailto:{$contact}?subject=Business plan"],
        ['name' => 'Agency', 'price' => '₹4,999', 'period' => '/month', 'blurb' => 'For client work at scale.', 'items' => ['10 apps', '8 GB RAM', 'Daily backups', 'Priority support'], 'cta' => 'Contact us', 'href' => "mailto:{$contact}?subject=Agency plan"],
    ];
@endphp
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $product }} | Hosting for apps, databases and email</title>
    <meta name="description" content="{{ $product }} hosts your apps, databases and business email, with Git deploys, automatic HTTPS and daily backups.">
    <link rel="icon" href="/standalonecoders-favicon.png" type="image/png">
    <link rel="apple-touch-icon" href="/standalonecoders-apple-touch-icon.png">
    @vite(['resources/css/app.css'])
</head>

<body class="min-h-screen bg-white font-sans text-slate-700 antialiased">
    <header class="sticky top-0 z-50 border-b border-slate-200 bg-white/80 backdrop-blur">
        <nav class="mx-auto flex h-16 max-w-6xl items-center gap-6 px-4 sm:px-6" aria-label="Main">
            <a href="{{ route('home') }}" class="flex items-center gap-2">
                <img src="/standalonecoders-mark.svg" alt="" class="size-7">
                <span class="text-[15px] tracking-tight text-slate-900"><span class="font-extrabold">Standalone</span>Coders <span class="text-slate-600">Cloud</span></span>
            </a>
            <div class="ml-auto hidden items-center gap-6 text-sm text-slate-600 md:flex">
                <a href="#features" class="hover:text-slate-900">Features</a>
                <a href="#plans" class="hover:text-slate-900">Plans</a>
                <a href="#developer" class="hover:text-slate-900">Developer</a>
            </div>
            <div class="ml-auto flex items-center gap-2 md:ml-0">
                @auth
                    <a href="{{ route('dashboard') }}" class="rounded-lg bg-green-500 px-4 py-2 text-sm font-semibold text-green-950 hover:bg-green-400">Dashboard</a>
                @else
                    <a href="{{ route('login') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:text-slate-900">Sign in</a>
                    <a href="#plans" class="hidden rounded-lg bg-green-500 px-4 py-2 text-sm font-semibold text-green-950 hover:bg-green-400 sm:inline-block">See plans</a>
                @endauth
            </div>
        </nav>
    </header>

    <main>
        {{-- Hero --}}
        <section class="relative overflow-hidden">
            <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(800px_400px_at_50%_-10%,rgb(34_197_94/0.14),transparent_70%)]" aria-hidden="true"></div>
            <div class="relative mx-auto max-w-4xl px-4 pt-20 pb-16 text-center sm:px-6 sm:pt-28">
                <p class="inline-flex items-center gap-2 rounded-full border border-green-500/30 bg-green-500/10 px-3 py-1 text-xs font-medium text-green-800"><span class="size-1.5 rounded-full bg-green-500"></span> Managed hosting by StandaloneCoders</p>
                <h1 class="mt-6 text-4xl/tight! font-semibold tracking-tight text-slate-900 sm:text-6xl/tight!">Deploy apps, databases and email <span class="text-green-700">without managing servers</span></h1>
                <p class="mx-auto mt-6 max-w-2xl text-lg text-slate-600">{{ $product }} runs your projects for you: Git deploys, managed databases, automatic HTTPS and business email, for one fixed monthly price.</p>
                <div class="mt-10 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    <a href="#plans" class="w-full rounded-lg bg-green-500 px-6 py-3 text-sm font-semibold text-green-950 hover:bg-green-400 sm:w-auto">See plans</a>
                    <a href="mailto:{{ $contact }}" class="w-full rounded-lg border border-slate-300 bg-white px-6 py-3 text-sm font-semibold text-slate-900 hover:bg-slate-50 sm:w-auto">Talk to us</a>
                </div>
            </div>
        </section>

        {{-- Features --}}
        <section id="features" class="mx-auto max-w-6xl scroll-mt-20 px-4 py-20 sm:px-6">
            <h2 class="text-center text-3xl/tight! font-semibold tracking-tight text-slate-900">Everything to run production</h2>
            <p class="mx-auto mt-3 max-w-xl text-center text-slate-600">Everything a web project needs, set up and kept running for you.</p>
            <div class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($features as [$title, $text, $icon])
                    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-6">
                        <div class="flex size-10 items-center justify-center rounded-lg bg-green-500/10 text-green-700">
                            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icon }}" /></svg>
                        </div>
                        <h3 class="mt-4 font-semibold text-slate-900">{{ $title }}</h3>
                        <p class="mt-2 text-sm text-slate-600">{{ $text }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Plans --}}
        <section id="plans" class="scroll-mt-20 border-y border-slate-200 bg-slate-50">
            <div class="mx-auto max-w-6xl px-4 py-20 sm:px-6">
                <h2 class="text-center text-3xl/tight! font-semibold tracking-tight text-slate-900">Plans</h2>
                <p class="mx-auto mt-3 max-w-xl text-center text-slate-600">Pick a plan and we set everything up for you. Prices in Indian rupees.</p>
                <div class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($plans as $plan)
                        <div @class([
                            'relative flex flex-col rounded-2xl border p-6',
                            'border-green-500/60 bg-green-500/[0.06]' => $plan['popular'] ?? false,
                            'border-slate-200 bg-white' => ! ($plan['popular'] ?? false),
                        ])>
                            @if ($plan['popular'] ?? false)
                                <span class="absolute -top-3 left-6 rounded-full bg-green-500 px-2.5 py-0.5 text-xs font-semibold text-green-950">Popular</span>
                            @endif
                            <h3 class="font-semibold text-slate-900">{{ $plan['name'] }}</h3>
                            <p class="mt-1 text-sm text-slate-600">{{ $plan['blurb'] }}</p>
                            <p class="mt-5"><span class="text-3xl font-semibold text-slate-900">{{ $plan['price'] }}</span> <span class="text-sm text-slate-600">{{ $plan['period'] }}</span></p>
                            <ul class="mt-5 flex-1 space-y-2 text-sm text-slate-700">
                                @foreach ($plan['items'] as $item)
                                    <li class="flex gap-2"><svg class="mt-0.5 size-4 shrink-0 text-green-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M5 13l4 4L19 7" /></svg>{{ $item }}</li>
                                @endforeach
                            </ul>
                            <a href="{{ $plan['href'] }}" @class([
                                'mt-6 rounded-lg px-4 py-2.5 text-center text-sm font-semibold',
                                'bg-green-500 text-green-950 hover:bg-green-400' => $plan['popular'] ?? false,
                                'border border-slate-300 text-slate-900 hover:bg-slate-50' => ! ($plan['popular'] ?? false),
                            ])>{{ $plan['cta'] }}</a>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- Developer --}}
        <section id="developer" class="scroll-mt-20 border-t border-slate-200 bg-slate-50">
            <div class="mx-auto flex max-w-4xl flex-col items-center gap-8 px-4 py-20 text-center sm:flex-row sm:text-left sm:px-6">
                <img src="{{ $developerUrl }}.png?size=200" alt="RoastCoder" class="size-28 shrink-0 rounded-2xl border border-slate-300" loading="lazy">
                <div>
                    <p class="text-sm font-medium text-green-700">About the developer</p>
                    <h2 class="mt-1 text-2xl/tight! font-semibold text-slate-900">Built by RoastCoder at StandaloneCoders</h2>
                    <p class="mt-3 text-slate-600">StandaloneCoders builds web apps, platforms and AI tools for businesses. {{ $product }} is the platform we use to host our own client projects, now offered to everyone as a managed service.</p>
                    <div class="mt-5 flex flex-wrap justify-center gap-3 sm:justify-start">
                        <a href="{{ $developerUrl }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50">GitHub profile</a>
                        <a href="mailto:{{ $contact }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50">{{ $contact }}</a>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <footer class="border-t border-slate-200">
        <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-3 px-4 py-8 text-sm text-slate-500 sm:flex-row sm:px-6">
            <p>&copy; {{ date('Y') }} StandaloneCoders. Built on Coolify.</p>
            <div class="flex gap-5">
                <a href="mailto:{{ $contact }}" class="hover:text-slate-900">Contact</a>
                <a href="{{ route('login') }}" class="hover:text-slate-900">Sign in</a>
            </div>
        </div>
    </footer>
</body>

</html>
