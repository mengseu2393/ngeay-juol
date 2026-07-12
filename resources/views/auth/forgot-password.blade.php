<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('ngeay juol') }} - {{ __('Forgot Password') }}</title>
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
                        {{ __('Password recovery made simple.') }}
                    </h2>
                    <p class="text-slate-400 text-sm leading-relaxed mb-8">
                        {{ __('If you have forgotten your password, enter your registered email address here. We will generate and transmit a secure recovery link so you can regain control of your account.') }}
                    </p>

                    <div class="space-y-4">
                        <div class="flex items-start gap-3.5">
                            <div class="w-5 h-5 rounded bg-emerald-500/20 text-emerald-400 flex items-center justify-center shrink-0 mt-0.5">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path></svg>
                            </div>
                            <p class="text-xs text-slate-300">{{ __('Check your spam folder if the recovery message does not appear in your inbox within a few minutes.') }}</p>
                        </div>
                    </div>
                </div>

                <!-- Footer/Help Link -->
                <div class="flex justify-between items-center text-xs text-slate-500 relative z-10">
                    <span>{{ __('Need assistance? Contact your landlord.') }}</span>
                </div>
            </div>

            <!-- Right Panel (Form) -->
            <div class="lg:col-span-7 p-8 lg:p-16 flex flex-col justify-center relative">
                
                <div class="max-w-md w-full mx-auto">
                    <!-- Heading -->
                    <h1 class="font-display font-bold text-3xl mb-2">{{ __('Forgot Password?') }}</h1>
                    <p class="text-slate-500 dark:text-slate-400 text-sm mb-8">{{ __('Provide your email address, and we will send a password reset link to your inbox.') }}</p>

                    <!-- Success Alert -->
                    @if (session('status'))
                        <div class="mb-6 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-600 dark:text-emerald-400 text-sm flex items-start gap-2.5">
                            <svg class="w-5 h-5 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            <div>
                                <span class="font-semibold">{{ __('Link transmitted') }}</span>
                                <p class="text-xs mt-0.5">{{ session('status') }}</p>
                            </div>
                        </div>
                    @endif

                    <!-- Error Alert -->
                    @if ($errors->any())
                        <div class="mb-6 p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-600 dark:text-red-400 text-sm flex items-start gap-2.5">
                            <svg class="w-5 h-5 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                            <div>
                                <span class="font-semibold">{{ __('Verification failed') }}</span>
                                <p class="text-xs mt-0.5">{{ $errors->first() }}</p>
                            </div>
                        </div>
                    @endif

                    <!-- Form -->
                    <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
                        @csrf
                        
                        <!-- Email Field -->
                        <div>
                            <label for="email" class="block text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">{{ __('Email Address') }}</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207"></path></svg>
                                </div>
                                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                                    class="block w-full pl-11 pr-4 py-3 rounded-xl border border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50 focus:bg-white dark:focus:bg-slate-900 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500/25 outline-none transition-all text-sm"
                                    placeholder="Enter your email address">
                            </div>
                        </div>

                        <!-- Submit -->
                        <button type="submit" class="w-full py-3.5 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-bold text-center shadow-lg shadow-emerald-500/10 hover:shadow-emerald-500/25 hover:scale-[1.01] active:scale-[0.99] transition-all cursor-pointer text-sm">
                            {{ __('Send Password Reset Link') }}
                        </button>
                    </form>

                    <!-- Back to Login / Landing -->
                    <div class="mt-8 flex justify-between items-center text-xs">
                        <a href="{{ route('login') }}" class="inline-flex items-center gap-1.5 font-semibold text-slate-500 dark:text-slate-400 hover:text-emerald-500 transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"></path></svg>
                            {{ __('Back to login') }}
                        </a>
                        <a href="/" class="inline-flex items-center gap-1.5 font-semibold text-slate-500 dark:text-slate-400 hover:text-emerald-500 transition-colors">
                            {{ __('Back to landing page') }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </body>
</html>
