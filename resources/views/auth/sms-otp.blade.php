<x-layout-simple>
    <x-auth.shell title="Coolify" description="Verify your identity to finish signing in.">
        <div class="flex flex-col gap-4" x-data="{ submitting: false }">
            @if (session('status'))
                <x-auth.alert type="success">{{ session('status') }}</x-auth.alert>
            @endif

            @if ($errors->any())
                <x-auth.alert type="error">
                    <div class="flex flex-col gap-1">
                        @foreach ($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                </x-auth.alert>
            @endif

            <div class="auth-guidance">
                <x-reicon name="info-circle" class="mt-0.5 size-4 shrink-0" />
                <p>Enter the 6-digit code we sent by SMS to {{ $phone }}. It expires in 5 minutes.</p>
            </div>

            <form x-ref="otpForm" action="{{ route('login.sms-otp.verify') }}" method="POST" class="flex flex-col gap-4"
                @submit="if (submitting) { $event.preventDefault() } submitting = true">
                @csrf
                <input type="text" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6"
                    autocomplete="one-time-code" autofocus required aria-label="SMS code"
                    @input="$event.target.value = $event.target.value.replace(/\D/g, '').slice(0, 6); if ($event.target.value.length === 6) $nextTick(() => $refs.otpForm.requestSubmit())"
                    class="mx-auto h-14 w-64 rounded-md border border-neutral-300 bg-white px-4 text-center text-xl font-semibold tracking-[0.5em] text-neutral-900 transition-colors focus:border-warning focus:outline-none focus:ring-1 focus:ring-warning dark:border-white/10 dark:bg-coolgray-100 dark:text-white" />
                <x-forms.button type="submit" class="w-full justify-center" isHighlighted>Verify</x-forms.button>
            </form>

            <form action="{{ route('login.sms-otp.resend') }}" method="POST" class="flex justify-center">
                @csrf
                <button type="submit" class="text-sm underline underline-offset-2">Send a new code</button>
            </form>
        </div>
    </x-auth.shell>
</x-layout-simple>
