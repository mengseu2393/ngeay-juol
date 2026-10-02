@php
    /** @var \App\Models\ErrorLog $log */
    $context = $log->context ?? [];
    $json = fn ($value) => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@endphp

{{-- Styled by .rw-errlog in rentwise-admin.css: Filament's precompiled Tailwind lacks most utilities needed here. --}}
<div class="rw-errlog">
    <dl class="rw-errlog-meta">
        <div>
            <dt>{{ __('When') }}</dt>
            <dd>{{ $log->created_at?->translatedFormat('d M Y H:i:s') ?? '—' }}</dd>
        </div>
        <div>
            <dt>{{ __('User') }}</dt>
            <dd>{{ $log->user ? $log->user->name.' (#'.$log->user_id.')' : __('Guest') }}</dd>
        </div>
        <div class="rw-errlog-wide">
            <dt>{{ __('Page') }}</dt>
            <dd>{{ trim(($log->method ?? '').' '.($log->url ?? '—')) }}@if ($log->route_name) <span class="rw-errlog-muted">({{ $log->route_name }})</span>@endif</dd>
        </div>
        <div>
            <dt>{{ __('IP address') }}</dt>
            <dd>{{ $log->ip ?? '—' }}</dd>
        </div>
        <div>
            <dt>{{ __('Device') }}</dt>
            <dd>{{ $log->user_agent ?? '—' }}</dd>
        </div>
    </dl>

    <section>
        <h3>{{ __('Message') }}</h3>
        <p class="rw-errlog-message">{{ $log->message }}</p>
        @if ($log->exception_class)
            <p class="rw-errlog-muted">{{ $log->exception_class }}@if ($log->file) — {{ $log->file }}:{{ $log->line }}@endif</p>
        @endif
    </section>

    @if (! empty($context['errors']))
        <section>
            <h3>{{ __('Validation errors') }}</h3>
            <ul>
                @foreach ($context['errors'] as $field => $messages)
                    @foreach ((array) $messages as $message)
                        <li><code>{{ $field }}</code>: {{ $message }}</li>
                    @endforeach
                @endforeach
            </ul>
        </section>
    @endif

    @if (! empty($context['input']))
        <section>
            <h3>{{ __('Submitted data') }}</h3>
            <pre>{{ $json($context['input']) }}</pre>
        </section>
    @endif

    @if ($log->trace)
        <section>
            <h3>{{ __('Stack trace') }}</h3>
            <pre class="rw-errlog-trace">{{ $log->trace }}</pre>
        </section>
    @endif
</div>
