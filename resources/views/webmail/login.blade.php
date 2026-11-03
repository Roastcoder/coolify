<x-webmail-layout title="Sign in | StandaloneCoders Mail">
    <div class="flex min-h-screen">
        <aside class="relative hidden w-[44%] flex-col justify-between overflow-hidden bg-[linear-gradient(160deg,#15803d,#14532d)] px-12 py-10 text-white lg:flex" aria-hidden="true">
            <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(rgb(255_255_255/0.12)_1px,transparent_1px)] [mask-image:linear-gradient(180deg,transparent,#000_60%)] bg-[size:22px_22px]"></div>
            <div class="relative flex items-center gap-3 text-[17px] font-semibold">
                <img src="/standalonecoders-mark.svg" alt="" class="size-9 rounded-lg ring-1 ring-white/25">
                StandaloneCoders Mail
            </div>
            <div class="relative max-w-md">
                <p class="mb-6 text-[38px]/tight font-semibold tracking-tight">Your business email, in one place.</p>
                <ul class="space-y-3 text-base text-white/90">
                    @foreach (['Read and send from any browser', 'Works with Outlook, Apple Mail and phone apps', 'Encrypted connections and signed mail'] as $point)
                        <li class="flex items-center gap-3">
                            <span class="grid size-5 place-items-center rounded-full bg-white/20">
                                <svg viewBox="0 0 24 24" class="size-3" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7" /></svg>
                            </span>
                            {{ $point }}
                        </li>
                    @endforeach
                </ul>
            </div>
            <p class="relative text-sm text-white/70">&copy; {{ date('Y') }} StandaloneCoders</p>
        </aside>

        <main class="flex flex-1 items-center justify-center px-6 py-12">
            <div class="w-full max-w-sm">
                <div class="mb-8 flex items-center gap-3 lg:hidden">
                    <img src="/standalonecoders-mark.svg" alt="" class="size-9">
                    <span class="text-lg font-semibold text-slate-900">StandaloneCoders Mail</span>
                </div>
                <h1 class="text-3xl! font-semibold tracking-tight text-slate-900">Sign in</h1>
                <p class="mt-2 text-slate-500">Use your full email address and password.</p>

                <form method="POST" action="{{ route('webmail.login.submit') }}" class="mt-8 space-y-5">
                    @csrf
                    <div>
                        <label for="email" class="mb-1.5 block text-sm font-medium text-slate-700">Email</label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                            placeholder="you@company.com" @error('email') aria-invalid="true" aria-describedby="email-error" @enderror
                            class="block h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-slate-900 placeholder:text-slate-400 focus:border-green-600 focus:outline-none focus:ring-2 focus:ring-green-600/20">
                        @error('email')
                            <p id="email-error" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="password" class="mb-1.5 block text-sm font-medium text-slate-700">Password</label>
                        <input id="password" name="password" type="password" required autocomplete="current-password"
                            class="block h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-slate-900 focus:border-green-600 focus:outline-none focus:ring-2 focus:ring-green-600/20">
                    </div>
                    <label class="flex items-center gap-2.5 text-sm text-slate-700">
                        <input type="checkbox" name="remember" value="1" class="size-4 rounded border-slate-300 accent-green-600" @checked(old('remember'))>
                        Remember me for 30 days
                    </label>
                    <button type="submit" class="h-11 w-full rounded-lg bg-green-600 font-semibold text-white hover:bg-green-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-600 focus-visible:ring-offset-2">Sign in</button>
                </form>
            </div>
        </main>
    </div>
</x-webmail-layout>
