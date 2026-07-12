<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('View details') }} · {{ $invoice->invoice_number }}</title>
    <link rel="stylesheet" href="{{ asset('css/rentwise-admin.css') }}?v={{ filemtime(public_path('css/rentwise-admin.css')) }}">
</head>
<body class="rw-invoice-page min-h-screen bg-gray-100 p-3 sm:p-6" style="color-scheme: light;">
    <main class="mx-auto w-full max-w-4xl">
        <div class="mb-3 flex items-center justify-between gap-3">
            <a href="{{ route('filament.landlord.pages.simple', ['screen' => 'invoices']) }}" class="rw-sm-btn-secondary">
                &larr; {{ __('Back to invoices') }}
            </a>
            <span class="text-xs font-semibold text-gray-500 dark:text-gray-400">{{ $invoice->invoice_number }}</span>
        </div>

        @include('components.invoice-slip-modal', ['invoice' => $invoice])
    </main>
</body>
</html>
