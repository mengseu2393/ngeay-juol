<x-filament-panels::page>
    <form wire:submit="generate" class="space-y-6">
        {{ $this->form }}

        <div class="flex justify-end">
            <x-filament::button type="submit" size="lg" icon="heroicon-m-qr-code">
                {{ __('Generate QR Code') }}
            </x-filament::button>
        </div>
    </form>

    {{-- QR Code Display --}}
    @if ($qrUrl)
        <div class="mt-8">
            <x-filament::section>
                <x-slot name="heading">
                    {{ __('Login QR Code') }}
                </x-slot>
                <x-slot name="description">
                    {{ __('Scan this QR code to open the login page with credentials pre-filled.') }}
                </x-slot>

                <div class="flex flex-col items-center gap-6 py-6">
                    {{-- QR Code Canvas with Alpine --}}
                    <div class="bg-white p-6 rounded-2xl shadow-lg border border-gray-100 dark:border-gray-700 inline-block"
                         x-data="{
                             rendered: false,
                             init() {
                                 this.loadAndRender();
                             },
                             loadAndRender() {
                                 if (window.QRious) {
                                     this.render();
                                     return;
                                 }
                                 const s = document.createElement('script');
                                 s.src = 'https://cdn.jsdelivr.net/npm/qrious@4.0.2/dist/qrious.min.js';
                                 s.onload = () => this.render();
                                 document.head.appendChild(s);
                             },
                             render() {
                                 this.$nextTick(() => {
                                     const c = this.$refs.canvas;
                                     if (!c) return;
                                     new QRious({
                                         element: c,
                                         value: @js($qrUrl),
                                         size: 280,
                                         backgroundAlpha: 1,
                                         foreground: '#0f172a',
                                         background: '#ffffff',
                                         level: 'H',
                                         padding: 16,
                                     });
                                     this.rendered = true;
                                 });
                             }
                         }">
                        <canvas x-ref="canvas" width="280" height="280"></canvas>
                    </div>

                    {{-- Landlord Info (plain Blade, no Alpine needed) --}}
                    <div class="text-center space-y-1">
                        <p class="text-lg font-bold text-gray-900 dark:text-gray-100">
                            {{ $landlordName }}
                        </p>
                        <p class="text-sm text-gray-500 dark:text-gray-400 font-mono">
                            {{ $landlordLogin }}
                        </p>
                    </div>

                    {{-- Action Buttons (self-contained Alpine for each) --}}
                    <div class="flex flex-wrap gap-3 justify-center">
                        <button type="button"
                                x-data
                                x-on:click="
                                    const c = document.querySelector('canvas');
                                    if (!c) return;
                                    const a = document.createElement('a');
                                    a.download = {{ Js::from('qr-login-' . \Illuminate\Support\Str::slug($landlordName) . '.png') }};
                                    a.href = c.toDataURL('image/png');
                                    a.click();
                                "
                                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-emerald-600 text-white font-semibold text-sm shadow-md hover:bg-emerald-700 hover:shadow-lg active:scale-95 transition-all">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                            </svg>
                            {{ __('Download PNG') }}
                        </button>

                        <button type="button"
                                x-data
                                x-on:click="
                                    const c = document.querySelector('canvas');
                                    if (!c) return;
                                    const w = window.open('', '_blank');
                                    w.document.write(
                                        '<html><head><title>QR Login</title><style>body{display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;margin:0;font-family:system-ui,sans-serif}img{max-width:300px}h2{margin:20px 0 4px;font-size:1.25rem;color:#1e293b}p{margin:0;color:#64748b;font-size:.875rem}.w{margin-top:24px;font-size:.75rem;color:#92400e;max-width:320px;text-align:center}</style></head><body>'
                                        + '<img src=&quot;' + c.toDataURL('image/png') + '&quot;>'
                                        + '<h2>{{ $landlordName }}</h2><p>{{ $landlordLogin }}</p>'
                                        + '<p class=w>This QR code contains login credentials. Keep it confidential.</p>'
                                        + '<scr'+'ipt>window.onload=function(){window.print()}<\/scr'+'ipt>'
                                        + '</body></html>'
                                    );
                                    w.document.close();
                                "
                                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-gray-600 text-white font-semibold text-sm shadow-md hover:bg-gray-700 hover:shadow-lg active:scale-95 transition-all">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                            </svg>
                            {{ __('Print') }}
                        </button>

                        <button type="button"
                                x-data
                                x-on:click="navigator.clipboard.writeText({{ Js::from($qrUrl) }}).then(() => alert('URL copied!'))"
                                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-semibold text-sm shadow-md hover:bg-slate-200 dark:hover:bg-slate-700 hover:shadow-lg active:scale-95 transition-all">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                            </svg>
                            {{ __('Copy URL') }}
                        </button>
                    </div>

                    {{-- Warning --}}
                    <div class="max-w-md mx-auto mt-2 p-3 rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800/50 text-amber-700 dark:text-amber-400 text-xs text-center leading-relaxed">
                        <svg class="w-4 h-4 inline-block mr-1 -mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                        {{ __('This QR code contains login credentials. Share it only with the intended landlord and keep it confidential.') }}
                    </div>
                </div>
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>
