{{--
    "Install the app" card on the /m home screen (Alpine.data('rwMobileInstall')
    in resources/js/mobile.js). Android/Chrome: a real install button
    (beforeinstallprompt). iPhone: there is no install API, so it explains
    Share → Add to Home Screen. Never shown inside the installed app, and gone
    for good once dismissed on this device.
--}}
<div x-data="rwMobileInstall" x-show="visible" x-cloak class="rw-m-card mt-4 flex items-center gap-3" id="rw-m-install">
    <img src="/icons/icon-192.png" alt="" class="size-11 shrink-0 rounded-xl">
    <div class="min-w-0 flex-1">
        <p class="font-semibold">{{ __('Install the app') }}</p>
        <p class="text-sm text-slate-500 dark:text-slate-400" x-show="! ios">{{ __('Opens from your home screen, full screen, like any app.') }}</p>
        <p class="text-sm text-slate-500 dark:text-slate-400" x-show="ios">{{ __('Tap Share, then “Add to Home Screen”.') }}</p>
    </div>
    <button type="button" x-show="! ios" x-on:click="install()" class="rw-m-btn rw-m-btn-primary min-h-10 px-3 text-sm" id="rw-m-install-btn">{{ __('Install') }}</button>
    <button type="button" x-on:click="dismiss()" class="-mr-1 flex size-10 shrink-0 items-center justify-center rounded-full text-slate-400 active:bg-slate-100 dark:active:bg-slate-800" aria-label="{{ __('Close') }}" id="rw-m-install-dismiss">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-5" aria-hidden="true"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" /></svg>
    </button>
</div>
