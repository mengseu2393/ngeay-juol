{{--
    One row of a /m grouped settings list (iOS Settings style): tinted icon
    tile, label, optional detail text, chevron. ≥44px tall, press state, no
    hover-only UI. Used by livewire.mobile.settings.

    @param string $href
    @param string $id
    @param string $label
    @param string $icon     heroicons-outline path data (24×24)
    @param string $tint     Tailwind bg-* class for the icon tile
    @param string|null $detail
--}}
<a
    href="{{ $href }}"
    wire:navigate
    id="{{ $id }}"
    class="flex min-h-12 items-center gap-3 px-4 py-2.5 transition-colors active:bg-slate-100 dark:active:bg-slate-800"
>
    <span class="flex size-8 shrink-0 items-center justify-center rounded-lg text-white {{ $tint }}" aria-hidden="true">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="size-5"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}" /></svg>
    </span>
    <span class="min-w-0 flex-1 truncate font-medium">{{ $label }}</span>
    @if (! empty($detail))
        <span class="shrink-0 truncate text-sm text-slate-500 dark:text-slate-400">{{ $detail }}</span>
    @endif
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-5 shrink-0 text-slate-300 dark:text-slate-600" aria-hidden="true"><path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>
</a>
