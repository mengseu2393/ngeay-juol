@php
    $registrationOpen = \App\Services\LandlordRegistrationService::isOpen();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('ngeay juol') }} - {{ __('Property Management, Beautifully Simplified.') }}</title>
        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        <link rel="icon" href="{{ asset('Khmer%20House%20Key.svg') }}" type="image/svg+xml">
        <!-- PWA -->
        <link rel="manifest" href="/manifest.json">
        <meta name="theme-color" content="#059669">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="ងាយជួល">
        <link rel="apple-touch-icon" href="/icons/icon-192.png">
        
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
        <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">

        <!-- Styles / Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @include('components.rw-loader-head')
        
        <style>
            body {
                font-family: 'Plus Jakarta Sans', sans-serif;
            }
            .font-display {
                font-family: 'Outfit', sans-serif;
            }
            .gradient-text {
                background: linear-gradient(135deg, #10b981 0%, #06b6d4 50%, #3b82f6 100%);
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
            }
            .glow-bg {
                filter: blur(150px);
                opacity: 0.15;
            }
            .glass {
                background: rgba(255, 255, 255, 0.45);
                backdrop-filter: blur(20px);
                -webkit-backdrop-filter: blur(20px);
                border: 1px solid rgba(255, 255, 255, 0.25);
            }
            .dark .glass {
                background: rgba(15, 23, 42, 0.45);
                backdrop-filter: blur(20px);
                -webkit-backdrop-filter: blur(20px);
                border: 1px solid rgba(255, 255, 255, 0.05);
            }
        </style>
    </head>
    <body class="bg-slate-50 dark:bg-slate-950 text-slate-900 dark:text-slate-100 min-h-screen relative overflow-x-hidden transition-colors duration-300">
        <div class="absolute inset-0 overflow-hidden pointer-events-none z-0">
            <div class="absolute top-[-20%] left-[-10%] w-[600px] h-[600px] rounded-full bg-emerald-400 glow-bg"></div>
            <div class="absolute bottom-[-10%] right-[-10%] w-[500px] h-[500px] rounded-full bg-cyan-400 glow-bg"></div>
        </div>

        <!-- Header -->
        <!-- Header -->
        <header class="fixed top-0 left-0 right-0 z-50 w-full glass border-b border-slate-200/50 dark:border-slate-800/50 transition-all duration-300">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 py-3 sm:py-4 flex items-center justify-between">
                <!-- Logo -->
                <a href="{{ url('/') }}" class="flex items-center gap-1.5 sm:gap-2 group<img src="{{ asset('Khmer%20House%20Key.svg') }}" alt="{{ __('ngeay juol') }}" class="h-8 w-8 sm:h-10 sm:w-10 rounded-xl shadow-lg shadow-emerald-500/20 group-hover:scale-105 transition-transform duration-300">
                    <span class="font-display font-extrabold text-xl sm:text-2xl tracking-tight text-slate-900 dark:text-white">{{ __('ngeay juol') }}</span>
                </a>

                <!-- Navigation Links (Desktop) -->
                <nav class="hidden md:flex items-center gap-8">
                    <a href="{{ route('info') }}#features" class="font-medium text-slate-600 dark:text-slate-300 hover:text-emerald-500 dark:hover:text-emerald-400 transition-colors">{{ __('Features') }}</a>
                    <a href="{{ route('info') }}#how-it-works" class="font-medium text-slate-600 dark:text-slate-300 hover:text-emerald-500 dark:hover:text-emerald-400 transition-colors">{{ __('How it works') }}</a>
                    <a href="{{ route('info') }}#roles" class="font-medium text-slate-600 dark:text-slate-300 hover:text-emerald-500 dark:hover:text-emerald-400 transition-colors">{{ __('Roles') }}</a>
                    <a href="{{ route('info') }}#support" class="font-medium text-slate-600 dark:text-slate-300 hover:text-emerald-500 dark:hover:text-emerald-400 transition-colors">{{ __('Support') }}</a>
                </nav>

                <!-- Action Buttons -->
                <div class="flex items-center gap-2 sm:gap-4">
                    <!-- Light/Dark Toggle -->
                    <button id="theme-toggle" class="p-2 sm:p-2.5 rounded-xl bg-white/80 dark:bg-slate-900/80 border border-slate-200 dark:border-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all cursor-pointer shadow-sm">
                        <!-- Sun Icon -->
                        <svg id="sun-icon" class="w-4 h-4 sm:w-5 sm:h-5 hidden" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="5"></circle>
                            <line x1="12" y1="1" x2="12" y2="3"></line>
                            <line x1="12" y1="21" x2="12" y2="23"></line>
                            <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
                            <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
                            <line x1="1" y1="12" x2="3" y2="12"></line>
                            <line x1="21" y1="12" x2="23" y2="12"></line>
                            <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
                            <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
                        </svg>
                        <!-- Moon Icon -->
                        <svg id="moon-icon" class="w-4 h-4 sm:w-5 sm:h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                        </svg>
                    </button>

                    <!-- Language Selector Dropdown -->
                    <div class="hidden sm:block relative" id="lang-dropdown-container">
                        <button id="lang-dropdown-btn" class="flex items-center gap-1.5 px-3 py-2 rounded-xl bg-white/80 dark:bg-slate-900/80 border border-slate-200 dark:border-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-all cursor-pointer shadow-sm text-sm font-semibold">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 21a9.07 9.07 0 01-3.058-2.517 8.997 8.997 0 01-2.222-3.838A8.901 8.901 0 014.5 12c0-1.74.5-3.364 1.358-4.743a8.997 8.997 0 012.222-2.838A9.07 9.07 0 0110.5 3m0 18a9.07 9.07 0 003.058-2.517 8.997 8.997 0 002.222-3.838A8.9 8.9 0 0019.5 12c0-1.74-.5-3.364-1.358-4.743a9.006 9.006 0 00-2.222-2.838A9.07 9.07 0 0013.5 3M10.5 21V3m0 18c-3.13 0-5.659-4.03-5.659-9a13.3 13.3 0 01.127-1.74A13.066 13.066 0 015.63 7m4.87 14c3.13 0 5.659-4.03 5.659-9a13.3 13.3 0 00-.127-1.74A13.066 13.066 0 0015.37 7m-4.87-4c-3.13 0-5.659 4.03-5.659 9m5.659-9c3.13 0 5.659 4.03 5.659 9m-11.318 0h11.318m0 0h11.318"></path></svg>
                            <span>{{ app()->getLocale() === 'km' ? 'ខ្មែរ' : 'English' }}</span>
                            <svg class="w-3 h-3 text-slate-400" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path></svg>
                        </button>
                        <div id="lang-dropdown-menu" class="hidden absolute right-0 mt-2 w-32 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-xl py-1.5 z-50">
                            <a href="{{ route('locale.switch', 'en') }}" class="block px-4 py-2 text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors {{ app()->getLocale() === 'en' ? 'font-bold text-emerald-500' : '' }}">
                                English
                            </a>
                            <a href="{{ route('locale.switch', 'km') }}" class="block px-4 py-2 text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors {{ app()->getLocale() === 'km' ? 'font-bold text-emerald-500' : '' }}">
                                ខ្មែរ
                            </a>
                        </div>
                    </div>

                    @auth
                        @php
                            $user = Auth::user();
                            if ($user->isPlatformStaff()) {
                                $dashboardUrl = route('filament.admin.pages.dashboard');
                            } elseif ($user->hasAnyRole(['landlord', 'landlord_manager'])) {
                                $dashboardUrl = route('filament.landlord.pages.dashboard');
                            } else {
                                $dashboardUrl = route('portal.dashboard');
                            }
                        @endphp
                        <a href="{{ $dashboardUrl }}" class="hidden sm:inline-block px-5 py-2.5 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-semibold shadow-md shadow-emerald-500/20 hover:shadow-lg hover:shadow-emerald-500/30 hover:scale-[1.02] active:scale-[0.98] transition-all text-sm">
                            {{ __('Go to Dashboard') }}
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="hidden sm:inline-block font-semibold text-slate-700 dark:text-slate-200 hover:text-emerald-500 dark:hover:text-emerald-400 transition-colors mr-2 text-sm">
                            {{ __('Log In') }}
                        </a>
                        @if ($registrationOpen)
                            <a href="{{ route('register') }}" class="hidden sm:inline-block px-5 py-2.5 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-semibold shadow-md shadow-emerald-500/20 hover:shadow-lg hover:shadow-emerald-500/30 hover:scale-[1.02] active:scale-[0.98] transition-all text-sm">
                                {{ __('Register') }}
                            </a>
                        @else
                            <a href="{{ route('login') }}" class="hidden sm:inline-block px-5 py-2.5 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-semibold shadow-md shadow-emerald-500/20 hover:shadow-lg hover:shadow-emerald-500/30 hover:scale-[1.02] active:scale-[0.98] transition-all text-sm">
                                {{ __('Sign In') }}
                            </a>
                        @endif
                    @endauth

                    <!-- Mobile Menu Button -->
                    <button id="mobile-menu-btn" class="md:hidden p-1.5 sm:p-2 rounded-xl text-slate-700 dark:text-slate-300">
                        <svg class="w-5 h-5 sm:w-6 sm:h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"></path>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Mobile Menu Dropdown -->
            <div id="mobile-menu" class="hidden border-t border-slate-100 dark:border-slate-800/50 px-6 py-4 flex flex-col gap-4 bg-white/95 dark:bg-slate-900/95 backdrop-blur-lg rounded-b-2xl">
                <a href="{{ route('info') }}#features" class="font-medium py-1.5 text-slate-600 dark:text-slate-300 hover:text-emerald-500 transition-colors">{{ __('Features') }}</a>
                <a href="{{ route('info') }}#how-it-works" class="font-medium py-1.5 text-slate-600 dark:text-slate-300 hover:text-emerald-500 transition-colors">{{ __('How it works') }}</a>
                <a href="{{ route('info') }}#roles" class="font-medium py-1.5 text-slate-600 dark:text-slate-300 hover:text-emerald-500 transition-colors">{{ __('Roles') }}</a>
                <a href="{{ route('info') }}#support" class="font-medium py-1.5 text-slate-600 dark:text-slate-300 hover:text-emerald-500 transition-colors">{{ __('Support') }}</a>
                <div class="flex items-center gap-4 py-1.5">
                    <span class="text-sm font-medium text-slate-500">{{ __('Language') }}:</span>
                    <a href="{{ route('locale.switch', 'en') }}" class="text-sm {{ app()->getLocale() === 'en' ? 'font-bold text-emerald-500' : 'text-slate-600 dark:text-slate-400' }}">EN</a>
                    <span class="text-slate-300">|</span>
                    <a href="{{ route('locale.switch', 'km') }}" class="text-sm {{ app()->getLocale() === 'km' ? 'font-bold text-emerald-500' : 'text-slate-600 dark:text-slate-400' }}">ខ្មែរ</a>
                </div>
                @auth
                    <div class="sm:hidden flex flex-col gap-2 pt-2 border-t border-slate-100 dark:border-slate-800">
                        <a href="{{ $dashboardUrl }}" class="w-full text-center px-5 py-2.5 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-semibold text-sm">
                            {{ __('Go to Dashboard') }}
                        </a>
                    </div>
                @else
                    <div class="sm:hidden flex flex-col gap-2 pt-2 border-t border-slate-100 dark:border-slate-800">
                        <a href="{{ route('login') }}" class="w-full text-center py-2.5 rounded-xl border border-slate-200 dark:border-slate-800 text-slate-700 dark:text-slate-200 font-semibold text-sm">
                            {{ __('Log In') }}
                        </a>
                        @if ($registrationOpen)
                            <a href="{{ route('register') }}" class="w-full text-center px-5 py-2.5 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-semibold text-sm">
                                {{ __('Register as Landlord') }}
                            </a>
                        @else
                            <a href="{{ route('login') }}" class="w-full text-center px-5 py-2.5 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-semibold text-sm">
                                {{ __('Sign In') }}
                            </a>
                        @endif
                    </div>
                @endauth
            </div>
        </header>

        <main class="min-h-screen px-6 pt-28 pb-16 flex flex-col items-center justify-center text-center max-w-4xl mx-auto relative z-10">
            <h1 class="font-display font-extrabold text-4xl sm:text-6xl tracking-tight text-slate-900 dark:text-white leading-[1.15] mb-6">
                {{ __('Rental management made simple for landlords in Cambodia') }}
            </h1>

            <p class="text-lg md:text-xl text-slate-600 dark:text-slate-400 max-w-3xl leading-relaxed mb-10">
                {{ __('Manage rooms, tenants, utilities, maintenance, documents, and tenant access in one Khmer-ready workspace.') }}
            </p>

            <div class="flex flex-col sm:flex-row gap-4">
                @guest
                    @if ($registrationOpen)
                        <a href="{{ route('register') }}" class="px-8 py-4 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 text-white font-bold shadow-lg shadow-emerald-500/20 hover:scale-[1.03] active:scale-[0.97] transition-all">
                            {{ __('Register as Landlord') }}
                        </a>
                    @endif
                @endguest
                <a href="{{ route('login') }}" class="px-8 py-4 rounded-xl {{ $registrationOpen && auth()->guest() ? 'bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 text-slate-800 dark:text-slate-200' : 'bg-gradient-to-r from-emerald-500 to-teal-500 text-white shadow-lg shadow-emerald-500/20' }} font-bold hover:scale-[1.03] active:scale-[0.97] transition-all">
                    {{ __('Sign In') }}
                </a>
                <a href="{{ route('info') }}" class="px-8 py-4 rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 text-slate-800 dark:text-slate-200 font-bold hover:bg-slate-50 dark:hover:bg-slate-800 transition-all">
                    {{ __('Explore Features') }}
                </a>
            </div>
        </main>

        <!-- Scripts -->
        <script>
            // Theme toggle logic
            const themeToggleBtn = document.getElementById('theme-toggle');
            const sunIcon = document.getElementById('sun-icon');
            const moonIcon = document.getElementById('moon-icon');

            function updateIcons() {
                const isDark = document.documentElement.classList.contains('dark');
                if (isDark) {
                    sunIcon?.classList.remove('hidden');
                    moonIcon?.classList.add('hidden');
                } else {
                    sunIcon?.classList.add('hidden');
                    moonIcon?.classList.remove('hidden');
                }
            }

            // Set initial icons based on document class
            updateIcons();

            themeToggleBtn?.addEventListener('click', function() {
                if (document.documentElement.classList.contains('dark')) {
                    document.documentElement.classList.remove('dark');
                    localStorage.setItem('theme', 'light');
                    localStorage.setItem('color-theme', 'light');
                } else {
                    document.documentElement.classList.add('dark');
                    localStorage.setItem('theme', 'dark');
                    localStorage.setItem('color-theme', 'dark');
                }
                updateIcons();
            });

            // Language switcher logic
            const langBtn = document.getElementById('lang-dropdown-btn');
            const langMenu = document.getElementById('lang-dropdown-menu');

            langBtn?.addEventListener('click', function(e) {
                e.stopPropagation();
                langMenu?.classList.toggle('hidden');
            });

            document.addEventListener('click', function() {
                langMenu?.classList.add('hidden');
            });

            // Mobile menu toggle logic
            const mobileMenuBtn = document.getElementById('mobile-menu-btn');
            const mobileMenu = document.getElementById('mobile-menu');

            mobileMenuBtn?.addEventListener('click', () => {
                mobileMenu?.classList.toggle('hidden');
            });

            // Close mobile menu when clicking any link inside it
            mobileMenu?.querySelectorAll('a').forEach(link => {
                link.addEventListener('click', () => {
                    mobileMenu?.classList.add('hidden');
                });
            });
        </script>

        @include('filament.components.pwa-install-banner')

        <script>
            if ('serviceWorker' in navigator) {
                window.addEventListener('load', () => {
                    navigator.serviceWorker.register('/sw.js').catch(() => {});
                });
            }
        </script>
    </body>
</html>
