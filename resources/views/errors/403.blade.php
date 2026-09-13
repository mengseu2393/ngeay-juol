<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Forbidden') }}</title>
    @if(app()->getLocale() === 'km')
        <style>
            @font-face {
                font-family: 'Kantumruy Pro';
                src: local('Kantumruy Pro');
            }
        </style>
    @endif
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            font-family: 'Kantumruy Pro', ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: #f8fafc;
            color: #0f172a;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #0f172a; color: #e2e8f0; }
            .card { background: #1e293b !important; border-color: #334155 !important; }
            .btn-secondary { background: #334155 !important; color: #e2e8f0 !important; border-color: #475569 !important; }
        }
        .card {
            width: 100%;
            max-width: 26rem;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 1rem;
            padding: 2rem 1.75rem;
            text-align: center;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
        }
        .icon {
            width: 3.5rem;
            height: 3.5rem;
            margin: 0 auto 1rem;
            border-radius: 9999px;
            background: rgba(239, 68, 68, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .icon svg { width: 1.75rem; height: 1.75rem; color: #dc2626; }
        h1 {
            font-size: 1.25rem;
            font-weight: 700;
            margin: 0 0 0.5rem;
        }
        p.message {
            margin: 0 0 1.5rem;
            font-size: 0.9375rem;
            color: #64748b;
            line-height: 1.5;
        }
        .actions {
            display: flex;
            flex-direction: column;
            gap: 0.625rem;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.375rem;
            padding: 0.625rem 1rem;
            border-radius: 0.625rem;
            font-size: 0.9375rem;
            font-weight: 600;
            text-decoration: none;
            border: 1px solid transparent;
            cursor: pointer;
        }
        .btn-primary {
            background: #059669;
            color: #ffffff;
        }
        .btn-secondary {
            background: #f1f5f9;
            color: #0f172a;
            border-color: #e2e8f0;
        }
        .btn svg { width: 1.125rem; height: 1.125rem; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
            </svg>
        </div>
        <h1>{{ __('You don\'t have permission to view this page.') }}</h1>
        <p class="message">{{ __('Contact your administrator if you think this is a mistake.') }}</p>
        <div class="actions">
            <a href="#" onclick="if (window.history.length > 1) { history.back(); } else { window.location.href = '{{ url('/') }}'; } return false;" class="btn btn-secondary">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/>
                </svg>
                {{ __('Go back') }}
            </a>
            <a href="{{ url('/') }}" class="btn btn-primary">{{ __('Back to home') }}</a>
        </div>
    </div>
</body>
</html>
