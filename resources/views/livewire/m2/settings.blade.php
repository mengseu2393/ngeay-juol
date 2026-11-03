@php
    $chev = '<svg class="m2a-chev" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"/></svg>';
@endphp

<div id="m2-settings" class="m2a">
    <x-m2.header :title="__('Settings')" :subtitle="$user?->name" :back="route('m2.home')" :profile="false" :avatar="$avatarUrl" :initial="$user?->name" />

    <main class="m2-wrap m2a-main">
        {{-- Who is signed in → Profile --}}
        <div class="m2a-list" style="margin-top:8px">
            <a href="{{ route('m2.profile') }}" wire:navigate class="m2a-row" id="m2-settings-account">
                <span class="m2-avatar is-lg" style="width:48px;height:48px;font-size:17px">
                    @if ($avatarUrl)<img src="{{ $avatarUrl }}" alt="{{ __('Profile photo') }}">@else{{ mb_strtoupper(mb_substr($user?->name ?? '?', 0, 1)) }}@endif
                </span>
                <span class="m2a-t">
                    <b class="m2-trunc" style="display:block">{{ $user?->name }}</b>
                    <span class="m2-trunc" style="display:block">{{ $user?->email ?: $user?->phone_number }}</span>
                </span>
                {!! $chev !!}
            </a>
        </div>

        <h2 class="m2a-h">{{ __('Property') }}</h2>
        <div class="m2a-list">
            <a href="{{ $links['property'] }}" class="m2a-row" id="m2-settings-property">
                <x-m2.icon name="property" class="is-info" /><span class="m2a-t"><b>{{ __('Property Settings') }}</b></span>{!! $chev !!}
            </a>
            @if ($canAddProperty)
                <a href="{{ $links['add'] }}" class="m2a-row" id="m2-settings-add-property">
                    <x-m2.icon name="plus" /><span class="m2a-t"><b>{{ __('Add property') }}</b></span>{!! $chev !!}
                </a>
            @endif
            <a href="{{ route('m2.home') }}" wire:navigate class="m2a-row" id="m2-settings-rooms">
                <x-m2.icon name="room" class="is-plain" /><span class="m2a-t"><b>{{ __('Rooms') }}</b></span>{!! $chev !!}
            </a>
        </div>

        <h2 class="m2a-h">{{ __('Utilities') }}</h2>
        <div class="m2a-list">
            <a href="{{ $links['rates'] }}" class="m2a-row" id="m2-settings-utility-rates">
                <x-m2.icon name="bolt" class="is-e" /><span class="m2a-t"><b>{{ __('Utility Rates') }}</b></span>{!! $chev !!}
            </a>
        </div>

        <h2 class="m2a-h">{{ __('Account') }}</h2>
        <div class="m2a-list">
            <a href="{{ route('m2.profile') }}" wire:navigate class="m2a-row" id="m2-settings-profile">
                <x-m2.icon name="person" /><span class="m2a-t"><b>{{ __('Profile') }}</b></span>{!! $chev !!}
            </a>
            {{-- Appearance: Light / Dark / System. Same localStorage key ('theme') the layout reads before first paint
                 and the Filament panels use, so the choice follows the user everywhere on this device. --}}
            <div class="m2a-row is-static" id="m2-settings-theme"
                 x-data="{
                    t: (function () { try { return localStorage.getItem('theme') || 'system'; } catch (e) { return 'system'; } })(),
                    set(v) {
                        this.t = v;
                        try { localStorage.setItem('theme', v); } catch (e) {}
                        var dark = v === 'dark' || (v === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                        document.documentElement.classList.toggle('dark', dark);
                        var meta = document.querySelector('meta[name=theme-color]');
                        if (meta) { meta.setAttribute('content', dark ? '#0b1612' : '#0b7a55'); }
                    }
                 }">
                <x-m2.icon name="moon" class="is-info" />
                <span class="m2-grow m2-title" id="m2-settings-theme-label">{{ __('Appearance') }}</span>
                <span class="m2-row" style="gap:6px" role="group" aria-labelledby="m2-settings-theme-label">
                    @foreach (['light' => __('Light'), 'dark' => __('Dark'), 'system' => __('System')] as $mode => $label)
                        <button type="button" id="m2-settings-theme-{{ $mode }}" class="m2-pill" style="min-height:32px;font-size:12.5px"
                                x-on:click="set('{{ $mode }}')" x-bind:aria-pressed="t === '{{ $mode }}' ? 'true' : 'false'" aria-pressed="false">{{ $label }}</button>
                    @endforeach
                </span>
            </div>
            {{-- Language: plain links, a full load — the choice lives in the session/cookie. --}}
            <div class="m2a-row is-static" id="m2-settings-language">
                <x-m2.icon name="language" class="is-w" />
                <span class="m2-grow m2-title" id="m2-settings-language-label">{{ __('Language') }}</span>
                <span class="m2-row" style="gap:6px" role="group" aria-labelledby="m2-settings-language-label">
                    @foreach ($locales as $code => $name)
                        <a href="{{ route('locale.switch', $code) }}" lang="{{ $code }}" id="m2-settings-locale-{{ $code }}" class="m2-pill" style="min-height:32px;display:inline-flex;align-items:center;font-size:12.5px" aria-pressed="{{ $locale === $code ? 'true' : 'false' }}">{{ $name }}</a>
                    @endforeach
                </span>
            </div>
        </div>

        {{-- Sign out: a real POST with CSRF, never wire:navigate --}}
        <form method="POST" action="{{ route('logout') }}" style="margin-top:20px" id="m2-settings-logout-form">
            @csrf
            <button type="submit" class="m2-btn is-danger" id="m2-settings-logout">{{ __('Log out') }}</button>
        </form>

        <p class="m2-muted m2-sm" style="text-align:center;margin-top:16px">{{ __('ngeay juol') }}</p>
    </main>
</div>
