<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('ngeay juol') }} - {{ __('Register as Landlord') }}</title>
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        <link rel="icon" href="{{ asset('Khmer%20House%20Key.svg') }}" type="image/svg+xml">

        <script>
            const theme = localStorage.getItem('theme') || localStorage.getItem('color-theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.classList.toggle('dark', theme === 'dark');
        </script>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Kantumruy+Pro:wght@400;500;600;700&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @include('components.rw-loader-head')

        <style>
            body { font-family: 'Plus Jakarta Sans', 'Kantumruy Pro', sans-serif; }
            .font-display { font-family: 'Outfit', 'Kantumruy Pro', sans-serif; }
            .glow-bg { filter: blur(130px); opacity: 0.15; }
        </style>
    </head>
    @php
        $input = 'block w-full px-4 py-3.5 rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50 focus:bg-white dark:focus:bg-slate-900 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/25 outline-none transition-all text-base';
        $label = 'block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5';
        $hint = 'text-xs text-slate-500 dark:text-slate-400 mt-1';
        $error = 'text-xs text-red-600 dark:text-red-400 mt-1';
    @endphp
    <body class="bg-slate-50 dark:bg-slate-950 text-slate-900 dark:text-slate-100 min-h-screen relative overflow-x-hidden">
        <div class="absolute top-[-10%] left-[-10%] w-[400px] h-[400px] rounded-full bg-emerald-400 glow-bg pointer-events-none"></div>
        <div class="absolute bottom-[-10%] right-[-10%] w-[400px] h-[400px] rounded-full bg-cyan-400 glow-bg pointer-events-none"></div>

        <main class="relative z-10 w-full max-w-md mx-auto px-4 py-6 sm:py-12">
            {{-- Brand --}}
            <a href="/" class="flex items-center justify-center gap-2 mb-6">
                <img src="{{ asset('Khmer%20House%20Key.svg') }}" alt="{{ __('ngeay juol') }}" class="h-10 w-10 rounded-xl shadow-md shadow-emerald-500/20">
                <span class="font-display font-extrabold text-xl tracking-tight">{{ __('ngeay juol') }}</span>
            </a>

            <div class="bg-white dark:bg-slate-900 rounded-3xl shadow-xl border border-slate-200/50 dark:border-slate-800/50 p-5 sm:p-8">
                {{-- Progress: account is step 1 of the setup --}}
                <div class="mb-6">
                    <div class="flex items-center justify-between text-xs font-semibold text-slate-500 dark:text-slate-400 mb-2">
                        <span>{{ __('Step :current of :total', ['current' => 1, 'total' => \App\Enums\OnboardingStep::TOTAL]) }}</span>
                        <span class="text-emerald-600 dark:text-emerald-400">{{ __('Account') }}</span>
                    </div>
                    <div class="h-2 rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden">
                        <div class="h-2 rounded-full bg-gradient-to-r from-emerald-500 to-teal-500" style="width: {{ round(100 / \App\Enums\OnboardingStep::TOTAL) }}%"></div>
                    </div>
                </div>

                <h1 class="font-display font-bold text-2xl mb-1">{{ __('Register as Landlord') }}</h1>
                <p class="text-slate-500 dark:text-slate-400 text-sm mb-6">{{ __('Create your account, then add your properties, utilities and room prices step by step.') }}</p>

                {{-- 'register' plus any error without an inline slot (e.g. the honeypot,
                     which phone autofill/password managers can fill) — otherwise the form
                     just bounces back with nothing shown. --}}
                @php
                    $orphanErrors = collect($errors->getMessages())
                        ->except(['name', 'phone_number', 'email', 'company_name', 'password'])
                        ->flatten();
                @endphp
                @if ($orphanErrors->isNotEmpty())
                    <div class="mb-5 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-600 dark:text-red-400 text-sm">
                        @foreach ($orphanErrors as $message)
                            <p>{{ $message }}</p>
                        @endforeach
                    </div>
                @endif

                <form method="POST" action="{{ route('register') }}" class="space-y-4" novalidate>
                    @csrf

                    {{-- Honeypot — hidden from people --}}
                    <div class="hidden" aria-hidden="true">
                        <label for="website">Website</label>
                        <input id="website" name="website" type="text" tabindex="-1" autocomplete="off">
                    </div>

                    <div>
                        <label for="name" class="{{ $label }}">{{ __('Full name') }}</label>
                        <input id="name" name="name" type="text" value="{{ old('name') }}" required autofocus autocomplete="name" class="{{ $input }}">
                        @error('name') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="phone_number" class="{{ $label }}">{{ __('Phone number') }}</label>
                        <input id="phone_number" name="phone_number" type="tel" inputmode="tel" value="{{ old('phone_number') }}" autocomplete="tel" placeholder="012 345 678" class="{{ $input }}">
                        @error('phone_number') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="email" class="{{ $label }}">{{ __('Email') }} <span class="font-normal text-slate-400">({{ __('optional if you entered a phone number') }})</span></label>
                        <input id="email" name="email" type="email" inputmode="email" value="{{ old('email') }}" autocomplete="email" class="{{ $input }}">
                        @error('email') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="company_name" class="{{ $label }}">{{ __('Business name') }} <span class="font-normal text-slate-400">({{ __('optional') }})</span></label>
                        <input id="company_name" name="company_name" type="text" value="{{ old('company_name') }}" autocomplete="organization" class="{{ $input }}">
                        @error('company_name') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="password" class="{{ $label }}">{{ __('Password') }}</label>
                        <input id="password" name="password" type="password" required autocomplete="new-password" class="{{ $input }}">
                        @error('password') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="password_confirmation" class="{{ $label }}">{{ __('Confirm password') }}</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="{{ $input }}">
                    </div>

                    <button type="submit" class="w-full py-4 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-bold text-base shadow-lg shadow-emerald-500/10 active:scale-[0.99] transition-all cursor-pointer">
                        {{ __('Create account & continue') }}
                    </button>
                </form>

                <p class="mt-6 text-center text-sm text-slate-500 dark:text-slate-400">
                    {{ __('Already have an account?') }}
                    <a href="{{ route('login') }}" class="font-semibold text-emerald-600 dark:text-emerald-400">{{ __('Sign In') }}</a>
                </p>
            </div>

            <p class="mt-6 text-center text-xs text-slate-500 dark:text-slate-400">
                {{ __('Tenants: your landlord creates your account for you.') }}
            </p>
        </main>
    </body>
</html>
