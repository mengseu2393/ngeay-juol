<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('ngeay juol') }} - {{ __('Sign In') }}</title>
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        <link rel="icon" href="{{ asset('Khmer%20House%20Key.svg') }}" type="image/svg+xml">
        
        <!-- Theme Detection Script -->
        <script>
            const theme = localStorage.getItem('theme') || localStorage.getItem('color-theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            if (theme === 'dark') {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        </script>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

        <!-- Styles / Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        
        <style>
            body {
                font-family: 'Plus Jakarta Sans', sans-serif;
            }
            .font-display {
                font-family: 'Outfit', sans-serif;
            }
            .gradient-text {
                background: linear-gradient(135deg, #10b981 0%, #06b6d4 100%);
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
            }
            .glow-bg {
                filter: blur(130px);
                opacity: 0.15;
            }
        </style>
    </head>
    <body class="bg-slate-50 dark:bg-slate-950 text-slate-900 dark:text-slate-100 min-h-screen flex items-center justify-center p-6 relative overflow-x-hidden transition-colors duration-300">
        
        <!-- Decorative Ambient Lights -->
        <div class="absolute top-[-10%] left-[-10%] w-[400px] h-[400px] rounded-full bg-emerald-400 glow-bg pointer-events-none"></div>
        <div class="absolute bottom-[-10%] right-[-10%] w-[400px] h-[400px] rounded-full bg-cyan-400 glow-bg pointer-events-none"></div>

        <!-- Main Card container -->
        <div class="w-full max-w-5xl bg-white dark:bg-slate-900 rounded-3xl shadow-2xl border border-slate-200/50 dark:border-slate-800/50 overflow-hidden grid grid-cols-1 lg:grid-cols-12 relative z-10 lg:min-h-[600px]">
            
            <!-- Left Panel (Illustration & Info) -->
            <div class="hidden lg:flex lg:col-span-5 bg-gradient-to-tr from-slate-900 via-slate-850 to-slate-900 p-8 lg:p-12 text-white flex-col justify-between relative overflow-hidden">
                <!-- Inner ambient glow -->
                <div class="absolute bottom-0 right-0 w-64 h-64 bg-emerald-500/10 rounded-full blur-3xl pointer-events-none"></div>
                
                <!-- Logo -->
                <a href="/" class="flex items-center gap-2 group relative z-10">
                    <img src="{{ asset('Khmer%20House%20Key.svg') }}" alt="{{ __('ngeay juol') }}" class="h-10 w-10 rounded-xl shadow-md shadow-emerald-500/20 group-hover:scale-105 transition-transform">
                    <span class="font-display font-extrabold text-xl tracking-tight">{{ __('ngeay juol') }}</span>
                </a>

                <!-- Marketing Text -->
                <div class="my-auto py-8 relative z-10">
                    <h2 class="font-display font-bold text-3xl lg:text-4xl leading-tight mb-4">
                        {{ __('One portal. Complete control.') }}
                    </h2>
                    <p class="text-slate-400 text-sm leading-relaxed mb-8">
                        {{ __('ngeay juol unites landlords, property managers, and tenants under a single workspace. Sign in to view dashboards, record utility metrics, or download print-ready receipts.') }}
                    </p>

                    <div class="space-y-4">
                        <div class="flex items-start gap-3.5">
                            <div class="w-5 h-5 rounded bg-emerald-500/20 text-emerald-400 flex items-center justify-center shrink-0 mt-0.5">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path></svg>
                            </div>
                            <p class="text-xs text-slate-300">{{ __('Landlords & Admins: Log in using your registered email address to manage properties.') }}</p>
                        </div>
                        <div class="flex items-start gap-3.5">
                            <div class="w-5 h-5 rounded bg-cyan-500/20 text-cyan-400 flex items-center justify-center shrink-0 mt-0.5">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path></svg>
                            </div>
                            <p class="text-xs text-slate-300">{{ __('Tenants: Log in with the unique account username supplied by your landlord.') }}</p>
                        </div>
                    </div>
                </div>

                <!-- Footer/Help Link -->
                <div class="flex justify-between items-center text-xs text-slate-500 relative z-10">
                    <span>{{ __('Need assistance? Contact your landlord.') }}</span>
                </div>
            </div>

            <!-- Right Panel (Login Form) -->
            <div class="lg:col-span-7 p-8 lg:p-16 flex flex-col justify-center relative">
                

                <div class="max-w-md w-full mx-auto">
                    <!-- Heading -->
                    <h1 class="font-display font-bold text-3xl mb-2">{{ __('Welcome Back') }}</h1>
                    <p class="text-slate-500 dark:text-slate-400 text-sm mb-8">{{ __('Please enter your credentials to access your ngeay juol account.') }}</p>

                    <!-- Alert message for errors -->
                    @if ($errors->any())
                        <div class="mb-6 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-600 dark:text-red-400 text-sm flex items-start gap-2.5">
                            <svg class="w-5 h-5 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                            <div>
                                <span class="font-semibold">{{ __('Authentication failed') }}</span>
                                <p class="text-xs mt-0.5">{{ $errors->first() }}</p>
                            </div>
                        </div>
                    @endif

                    <!-- Form -->
                    <form method="POST" action="{{ route('login') }}" class="space-y-5">
                        @csrf
                        
                        <!-- Login Field -->
                        <div>
                            <label for="login" class="block text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">{{ __('Email or Username') }}</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                                </div>
                                <input id="login" name="login" type="text" value="{{ request('qr_login', old('login')) }}" required autofocus
                                    class="block w-full pl-11 pr-4 py-3 rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50 focus:bg-white dark:focus:bg-slate-900 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/25 outline-none transition-all text-sm"
                                    placeholder="Enter your email or username">
                            </div>
                        </div>

                        <!-- Password Field -->
                        <div>
                            <div class="flex justify-between items-center mb-1.5">
                                <label for="password" class="block text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">{{ __('Password') }}</label>
                                @if (Route::has('password.request'))
                                    <a href="{{ route('password.request') }}" class="text-xs font-medium text-emerald-500 hover:text-emerald-600 transition-colors">Forgot password?</a>
                                @endif
                            </div>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                                </div>
                                <input id="password" name="password" type="password" required
                                    class="block w-full pl-11 pr-4 py-3 rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50 focus:bg-white dark:focus:bg-slate-900 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/25 outline-none transition-all text-sm"
                                    placeholder="••••••••">
                            </div>
                        </div>

                        <!-- Remember Me -->
                        <div class="flex items-center justify-between">
                            <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400 cursor-pointer">
                                <input type="checkbox" name="remember" class="w-4.5 h-4.5 rounded border-slate-200 dark:border-slate-800 text-emerald-600 focus:ring-emerald-500/20 bg-slate-50 dark:bg-slate-950">
                                <span class="select-none">{{ __('Remember me') }}</span>
                            </label>
                        </div>

                        <!-- Submit -->
                        <button type="submit" class="w-full py-3.5 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-bold text-center shadow-lg shadow-emerald-500/10 hover:shadow-emerald-500/25 hover:scale-[1.01] active:scale-[0.99] transition-all cursor-pointer text-sm">
                            {{ __('Sign In') }}
                        </button>
                    </form>

                    <!-- Back to Landing Page -->
                    <div class="mt-8 text-center">
                        <a href="/" class="inline-flex items-center gap-2 text-xs font-semibold text-slate-500 dark:text-slate-400 hover:text-emerald-500 transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                            {{ __('Back to landing page') }}
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Scripts -->
        <script>
            // ── QR Code Auto-Fill ─────────────────────────────────────────
            // When the login page is opened from a QR code scan, the URL
            // contains ?qr_login=...&qr_password=... query params. We read
            // them to prefill the form and optionally auto-submit.
            (function () {
                const params = new URLSearchParams(window.location.search);
                const qrPassword = params.get('qr_password');

                if (qrPassword) {
                    const pwField = document.getElementById('password');
                    if (pwField) {
                        pwField.value = qrPassword;
                    }

                    // Clean the URL so credentials don't linger in the address bar.
                    if (window.history.replaceState) {
                        const cleanUrl = window.location.origin + window.location.pathname;
                        window.history.replaceState({}, document.title, cleanUrl);
                    }

                    // Auto-submit the form after a brief delay so the user sees the prefill.
                    const loginField = document.getElementById('login');
                    if (loginField && loginField.value && pwField.value) {
                        setTimeout(function () {
                            document.querySelector('form').submit();
                        }, 600);
                    }
                }
            })();
        </script>
    </body>
</html>
