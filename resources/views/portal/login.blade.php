@extends('portal.layout')

@section('content')
    <div class="mx-auto mt-8 max-w-sm">
        <div class="rounded-xl bg-white p-6 shadow">
            <h1 class="text-xl font-bold text-slate-900">{{ __('Tenant login') }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ __('Sign in to view your invoices.') }}</p>

            @if ($errors->any())
                <div class="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('portal.login.attempt') }}" class="mt-5 space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-slate-700">{{ __('Username') }}</label>
                    <input name="username" value="{{ old('username') }}" autofocus required
                           class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-emerald-500 focus:ring-emerald-500"
                           placeholder="e.g. riverside-residences-101">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">{{ __('Password') }}</label>
                    <input name="password" type="password" required
                           class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 focus:border-emerald-500 focus:ring-emerald-500">
                </div>
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" name="remember" class="rounded border-slate-300 text-emerald-600">
                    {{ __('Remember me') }}
                </label>
                <button class="w-full rounded-lg bg-emerald-600 px-4 py-2.5 font-medium text-white hover:bg-emerald-700">
                    {{ __('Sign in') }}
                </button>
            </form>
        </div>
        <p class="mt-4 text-center text-xs text-slate-400">{{ __('Your landlord provides your username and password.') }}</p>
    </div>
@endsection
