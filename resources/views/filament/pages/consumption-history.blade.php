<x-filament-panels::page>
    {{-- Filters: Unit · Utility · Year --}}
    {{ $this->form }}

    @php($history = $this->getHistory())
    @php($uom = $history['utility']?->unit_of_measure)
    @php($months = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec'])

    @if (! $history['hasSelection'])
        <div class="mt-6 rounded-xl border border-dashed border-gray-300 dark:border-gray-700 p-10 text-center text-sm text-gray-500 dark:text-gray-400">
            {{ __('Pick a unit and a utility above to see a full year of readings.') }}
        </div>
    @else
        @php($s = $history['summary'])

        {{-- Summary strip --}}
        <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
            @php($cards = [
                ['label' => __('Year total'),  'value' => $this->fmt($s['total']).' '.$uom],
                ['label' => __('Avg / month'), 'value' => $this->fmt($s['avg']).' '.$uom, 'sub' => __(':n month(s) with readings', ['n' => $s['monthsWith']])],
                ['label' => __('Peak month'),  'value' => $s['peakMonth'] ? $months[$s['peakMonth']].' · '.$this->fmt($s['peakVal']).' '.$uom : '—'],
                ['label' => __('Waived'),      'value' => $s['waivedCount'].' '.__('month(s)')],
            ])
            @foreach ($cards as $c)
                <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $c['label'] }}</div>
                    <div class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">{{ $c['value'] }}</div>
                    @isset($c['sub'])
                        <div class="text-xs text-gray-400">{{ $c['sub'] }}</div>
                    @endisset
                </div>
            @endforeach
        </div>

        {{-- 12-month table --}}
        <div class="mt-4 overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                        <th class="px-4 py-2.5 font-medium">{{ __('Month') }}</th>
                        <th class="px-4 py-2.5 text-right font-medium">{{ __('Previous') }}</th>
                        <th class="px-4 py-2.5 text-right font-medium">{{ __('Current') }}</th>
                        <th class="px-4 py-2.5 text-right font-medium">{{ __('Used') }}</th>
                        <th class="px-4 py-2.5 font-medium w-1/3">{{ __('Trend') }}</th>
                        <th class="px-4 py-2.5 text-right font-medium">{{ __('Δ vs prev') }}</th>
                        <th class="px-4 py-2.5 font-medium">{{ __('Type') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($history['rows'] as $row)
                        @php($pct = ($s['maxUsed'] > 0 && $row['has']) ? max(2, round(($row['used'] / $s['maxUsed']) * 100)) : 0)
                        <tr @class([
                            'border-b border-gray-100 dark:border-gray-800',
                            'opacity-50' => ! $row['has'],
                            'bg-amber-50/40 dark:bg-amber-500/5' => $row['has'] && $row['waived'],
                            'bg-danger-50/50 dark:bg-danger-500/10' => $row['spike'],
                        ])>
                            {{-- Month --}}
                            <td class="whitespace-nowrap px-4 py-2.5 font-medium text-gray-900 dark:text-gray-100">
                                {{ $months[$row['month']] }}
                                @if ($row['spike'])
                                    @svg('heroicon-m-exclamation-triangle', 'ml-1 inline h-4 w-4 text-danger-500')
                                @endif
                            </td>

                            @if (! $row['has'])
                                <td colspan="6" class="px-4 py-2.5 text-gray-400">{{ __('No reading') }}</td>
                            @else
                                {{-- Previous / Current / Used --}}
                                <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-gray-500 dark:text-gray-400">{{ $this->fmt($row['old']) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums text-gray-500 dark:text-gray-400">{{ $this->fmt($row['new']) }}</td>
                                <td @class([
                                    'whitespace-nowrap px-4 py-2.5 text-right font-semibold tabular-nums',
                                    'text-danger-600 dark:text-danger-400' => $row['spike'],
                                    'text-gray-950 dark:text-white' => ! $row['spike'],
                                ])>{{ $this->fmt($row['used']) }} <span class="text-xs font-normal text-gray-400">{{ $uom }}</span></td>

                                {{-- Trend bar (width ∝ used; danger on spike, muted when waived) --}}
                                <td class="px-4 py-2.5">
                                    <div class="h-2.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                                        <div @class([
                                            'h-full rounded-full',
                                            'bg-danger-500' => $row['spike'],
                                            'bg-gray-300 dark:bg-gray-600' => ! $row['spike'] && $row['waived'],
                                            'bg-primary-500' => ! $row['spike'] && ! $row['waived'],
                                        ]) style="width: {{ $pct }}%"></div>
                                    </div>
                                </td>

                                {{-- Δ vs previous month --}}
                                <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums">
                                    @if (is_null($row['delta']))
                                        <span class="text-gray-400">—</span>
                                    @else
                                        @php($up = $row['delta'] > 0)
                                        <span @class([
                                            'text-amber-600 dark:text-amber-400' => $up,
                                            'text-success-600 dark:text-success-400' => ! $up && $row['delta'] < 0,
                                            'text-gray-400' => $row['delta'] == 0,
                                        ])>
                                            {{ $up ? '▲' : ($row['delta'] < 0 ? '▼' : '') }}
                                            {{ ($up ? '+' : '').number_format($row['delta'], 0) }}%
                                        </span>
                                    @endif
                                </td>

                                {{-- Reading type + waived --}}
                                <td class="whitespace-nowrap px-4 py-2.5">
                                    @if ($row['waived'])
                                        <span class="inline-flex items-center rounded-md bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-500/20 dark:text-amber-300">{{ __('Waived') }}</span>
                                    @elseif ($row['type'])
                                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $row['type']->getLabel() }}</span>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="mt-3 text-xs text-gray-400">
            {{ __('⚠ marks a month whose usage is well above this unit & utility\'s average for the year. Bar length is relative to the peak month.') }}
        </p>
    @endif
</x-filament-panels::page>
