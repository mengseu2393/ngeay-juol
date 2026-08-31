@php
    use Illuminate\Support\Str;

    /** @var \Spatie\Activitylog\Models\Activity $activity */
    $new = $activity->properties->get('attributes') ?? [];
    $old = $activity->properties->get('old') ?? [];
    $new = is_array($new) ? $new : (array) $new;
    $old = is_array($old) ? $old : (array) $old;

    // `old` is absent on create/delete, so drive the rows off `attributes` and fall
    // back to whatever `old` still has (a delete logs the final state under either).
    $keys = array_keys($new ?: $old);

    $render = function ($value): string {
        if (is_null($value) || $value === '') {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? __('Yes') : __('No');
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    };
@endphp

<div class="space-y-4 text-sm">
    <dl class="grid grid-cols-2 gap-x-4 gap-y-2 rounded-lg bg-gray-50 p-3 dark:bg-white/5">
        <div>
            <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Event') }}</dt>
            <dd class="text-gray-950 dark:text-white">{{ __(Str::headline((string) $activity->event)) }}</dd>
        </div>
        <div>
            <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Logged at') }}</dt>
            <dd class="text-gray-950 dark:text-white">{{ $activity->created_at?->translatedFormat('d M Y H:i') ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('By') }}</dt>
            <dd class="text-gray-950 dark:text-white">
                {{ $activity->causer?->name ?? __('System') }}
                @if ($activity->causer?->email)
                    <span class="text-gray-500 dark:text-gray-400">· {{ $activity->causer->email }}</span>
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Affected record') }}</dt>
            <dd class="text-gray-950 dark:text-white">
                {{ \App\Filament\Resources\ActivityLogResource::subjectLabel($activity->subject_type) }}
                @if ($activity->subject_id)
                    #{{ $activity->subject_id }}
                @endif
            </dd>
        </div>
    </dl>

    @if (empty($keys))
        <p class="text-gray-500 dark:text-gray-400">{{ __('This entry recorded no field values.') }}</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                    <tr>
                        <th class="py-2 pe-3 text-start font-medium">{{ __('Field') }}</th>
                        @if (! empty($old))
                            <th class="px-3 py-2 text-start font-medium">{{ __('Before') }}</th>
                        @endif
                        <th class="ps-3 py-2 text-start font-medium">{{ empty($old) ? __('Value') : __('After') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($keys as $key)
                        <tr>
                            <td class="py-2 pe-3 align-top font-medium text-gray-950 dark:text-white">
                                {{ Str::headline($key) }}
                            </td>
                            @if (! empty($old))
                                <td class="px-3 py-2 align-top text-gray-500 line-through decoration-gray-400/60 dark:text-gray-400">
                                    {{ $render($old[$key] ?? null) }}
                                </td>
                            @endif
                            <td class="ps-3 py-2 align-top text-gray-700 dark:text-gray-200">
                                {{ $render($new[$key] ?? ($old[$key] ?? null)) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
