@props(['title', 'subtitle' => null, 'back' => null, 'avatar' => null, 'initial' => null, 'actions' => null, 'profile' => true])
@php
    // The sketch's round picture. By default it is the signed-in account's own photo and opens Settings;
    // with :profile="false" it is just a picture (a tenant's, or the account's on Settings itself).
    $user = auth()->user();
    $photo = $profile ? \App\Support\M2Shell::mediaUrl($user?->getFirstMediaUrl('avatar')) : $avatar;
    $letter = mb_strtoupper(mb_substr($profile ? ($user?->name ?? '?') : ($initial ?? '?'), 0, 1));
@endphp
<header class="m2-top">
    <div class="m2-wrap">
        @if ($back)
            <a href="{{ $back }}" wire:navigate class="m2-back" aria-label="{{ __('Back') }}">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" /></svg>
            </a>
        @endif
        @if ($profile)
            <a href="{{ route('m2.settings') }}" wire:navigate class="m2-avatar" aria-label="{{ __('Settings') }}" id="m2-open-settings">
                @if ($photo)<img src="{{ $photo }}" alt="">@else{{ $letter }}@endif
            </a>
        @else
            <span class="m2-avatar" aria-hidden="true">
                @if ($photo)<img src="{{ $photo }}" alt="">@else{{ $letter }}@endif
            </span>
        @endif
        <div class="m2-namebox">
            <b>{{ $title }}</b>
            @if ($subtitle)<small>{{ $subtitle }}</small>@endif
        </div>
        {{ $actions }}
    </div>
</header>
