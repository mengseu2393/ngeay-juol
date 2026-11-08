@php
    use App\Enums\OnboardingStep;
    use App\Enums\PropertyType;
    use App\Support\LocationHelper;

    $current = $this->currentStep();
    $total = OnboardingStep::TOTAL;
    $percent = (int) round($current->value / $total * 100);
    $chev = '<svg class="m2a-chev" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"/></svg>';
@endphp

{{-- The setup wizard in /m2's look: progress card on top, one step per screen, Back / Next pinned at the bottom. --}}
<div class="m2a m2-setup" id="m2-setup" x-data x-on:rw-setup-step-changed.window="window.scrollTo({ top: 0, behavior: 'smooth' })">
    <header class="m2a-top m2-wrap">
        <div class="m2a-welcome m2-setup-progress">
            <div class="m2-setup-meta">
                <span class="m2a-home-tag">{{ __('Step :current of :total', ['current' => $current->value, 'total' => $total]) }}</span>
                <b>{{ $current->getLabel() }}</b>
            </div>
            <div class="m2-setup-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}"><i style="width: {{ $percent }}%"></i></div>
            {{-- Re-made per step (wire:key) so x-init slides the current step's chip into view. --}}
            <ol class="m2-setup-steps" wire:key="m2-setup-steps-{{ $current->value }}" x-data x-init="const c = $el.querySelector('.is-current'); if (c) { $el.scrollLeft = c.offsetLeft - 16; }">
                <li class="is-done">{{ __('Account') }}</li>
                @foreach (OnboardingStep::cases() as $case)
                    <li @class(['is-done' => $case->value < $current->value, 'is-current' => $case === $current])>{{ $case->getLabel() }}</li>
                @endforeach
            </ol>
        </div>
    </header>

    <main class="m2-wrap m2a-main m2-setup-main">
        @error('subscription')
            <div class="m2-notice is-bad" role="alert" id="m2-setup-error">{{ $message }}</div>
        @enderror

        {{-- ═══ Properties ═══ --}}
        @if ($current === OnboardingStep::Properties)
            <div class="m2-setup-intro">
                <h1>{{ __('Your properties') }}</h1>
                <p>{{ __('Add every building or house you rent out. You can add more later.') }}</p>
            </div>

            @foreach ($properties as $i => $property)
                @php
                    $districts = LocationHelper::getDistricts($property['city'] ?? null);
                    $communes = LocationHelper::getCommunes($property['city'] ?? null, $property['district'] ?? null);
                    $villages = LocationHelper::getVillages($property['city'] ?? null, $property['district'] ?? null, $property['commune'] ?? null);
                    $hasAddress = filled($property['address_line'] ?? null) || filled($property['city'] ?? null);
                @endphp
                <section class="m2a-stack" wire:key="m2-setup-property-{{ $i }}-{{ $property['id'] ?? 'new' }}">
                    <div class="m2-setup-sechead">
                        <h2 class="m2a-h">{{ __('Property :number', ['number' => $i + 1]) }}</h2>
                        @if (count($properties) > 1)
                            <button type="button" class="m2-setup-link is-bad" wire:click="removeProperty({{ $i }})">{{ __('Remove') }}</button>
                        @endif
                    </div>

                    <div class="m2a-card">
                        <div>
                            <label for="m2-setup-p{{ $i }}-name" class="m2-label">{{ __('Property name') }}</label>
                            <input type="text" id="m2-setup-p{{ $i }}-name" wire:model="properties.{{ $i }}.name" class="m2-input" autocomplete="off" autocapitalize="words" placeholder="{{ __('e.g. Sunrise Apartment') }}">
                            @error("properties.$i.name") <p class="m2-err">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <span class="m2-label" id="m2-setup-p{{ $i }}-type-label">{{ __('Property type') }}</span>
                            <div class="m2-pills" role="radiogroup" aria-labelledby="m2-setup-p{{ $i }}-type-label">
                                @foreach (PropertyType::pickerOptions($property['property_type'] ?? null) as $value => $typeName)
                                    <label class="m2-pill"><input type="radio" class="m2-sr" wire:model="properties.{{ $i }}.property_type" value="{{ $value }}">{{ $typeName }}</label>
                                @endforeach
                            </div>
                            @error("properties.$i.property_type") <p class="m2-err">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <span class="m2-label" id="m2-setup-p{{ $i }}-currency-label">{{ __('Currency for rent and bills') }}</span>
                            <div class="m2-pills" role="radiogroup" aria-labelledby="m2-setup-p{{ $i }}-currency-label">
                                @foreach (['USD' => __('US Dollar ($)'), 'KHR' => __('Khmer Riel (៛)')] as $code => $currencyName)
                                    <label class="m2-pill"><input type="radio" class="m2-sr" wire:model="properties.{{ $i }}.currency" value="{{ $code }}">{{ $currencyName }}</label>
                                @endforeach
                            </div>
                            @error("properties.$i.currency") <p class="m2-err">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    {{-- Address: optional, folded away until asked for --}}
                    <div x-data="{ open: @js($hasAddress || $errors->hasAny(["properties.$i.address_line", "properties.$i.city"])) }">
                        <div class="m2a-list">
                            <button type="button" class="m2a-row" x-on:click="open = ! open" x-bind:aria-expanded="open ? 'true' : 'false'">
                                <span class="m2a-t"><b>{{ __('Add the address') }}</b><span>{{ __('optional') }}</span></span>
                                <span class="m2a-fold" x-bind:class="open && 'is-open'">{!! $chev !!}</span>
                            </button>
                        </div>
                        <div class="m2a-card" style="margin-top:12px" x-show="open" x-cloak>
                            <div>
                                <label for="m2-setup-p{{ $i }}-address" class="m2-label">{{ __('Street, house number') }}</label>
                                <input type="text" id="m2-setup-p{{ $i }}-address" wire:model="properties.{{ $i }}.address_line" class="m2-input" autocomplete="street-address">
                                @error("properties.$i.address_line") <p class="m2-err">{{ $message }}</p> @enderror
                            </div>
                            @foreach ([
                                ['city', __('City / Province'), $provinces, true],
                                ['district', __('District (Khan / Srok)'), $districts, filled($property['city'] ?? null)],
                                ['commune', __('Commune (Sangkat / Khum)'), $communes, filled($property['district'] ?? null)],
                                ['village', __('Village (Phum)'), $villages, filled($property['commune'] ?? null)],
                            ] as [$field, $fieldLabel, $options, $enabled])
                                <div wire:key="m2-setup-p{{ $i }}-{{ $field }}">
                                    <label for="m2-setup-p{{ $i }}-{{ $field }}" class="m2-label">{{ $fieldLabel }}</label>
                                    <select id="m2-setup-p{{ $i }}-{{ $field }}" wire:model.live="properties.{{ $i }}.{{ $field }}" class="m2-input" @disabled(! $enabled)>
                                        <option value="">{{ __('Select') }}</option>
                                        @foreach ($options as $value => $optionLabel)
                                            <option value="{{ $value }}">{{ $optionLabel }}</option>
                                        @endforeach
                                    </select>
                                    @error("properties.$i.$field") <p class="m2-err">{{ $message }}</p> @enderror
                                </div>
                            @endforeach
                        </div>
                    </div>
                </section>
            @endforeach

            <button type="button" class="m2-rg-add" wire:click="addProperty" id="m2-setup-add-property">+ {{ __('Add another property') }}</button>
        @endif

        {{-- ═══ Utilities ═══ --}}
        @if ($current === OnboardingStep::Utilities)
            <div class="m2-setup-intro">
                <h1>{{ __('Utility prices') }}</h1>
                <p>{{ __('Enter what you charge tenants. Electricity and water are already added — just type the price.') }}</p>
            </div>

            @foreach ($utilities as $propertyId => $rows)
                <section class="m2a-stack" wire:key="m2-setup-utilities-{{ $propertyId }}">
                    <h2 class="m2a-h">{{ $this->propertyName($propertyId) }}</h2>
                    @forelse ($rows as $j => $utility)
                        <div class="m2a-card" wire:key="m2-setup-u-{{ $propertyId }}-{{ $j }}-{{ $utility['id'] ?? 'new' }}" x-data="{ more: @js(blank($utility['id'] ?? null)) }">
                            <div class="m2-setup-utility">
                                <div>
                                    <label for="m2-setup-u{{ $propertyId }}-{{ $j }}-name" class="m2-label">{{ __('Name') }}</label>
                                    @if (array_key_exists($utility['name'] ?? '', $utilityNames))
                                        {{-- Standard utility: the stored key stays English (billing looks it up), the label is translated. --}}
                                        <select id="m2-setup-u{{ $propertyId }}-{{ $j }}-name" wire:model="utilities.{{ $propertyId }}.{{ $j }}.name" class="m2-input">
                                            @foreach ($utilityNames as $nameKey => $nameLabel)
                                                <option value="{{ $nameKey }}">{{ $nameLabel }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <input type="text" id="m2-setup-u{{ $propertyId }}-{{ $j }}-name" wire:model="utilities.{{ $propertyId }}.{{ $j }}.name" class="m2-input" placeholder="{{ __('e.g. Trash') }}" autocomplete="off">
                                    @endif
                                </div>
                                <div>
                                    <label for="m2-setup-u{{ $propertyId }}-{{ $j }}-rate" class="m2-label">{{ __('Price') }} ({{ $this->currencySymbol($propertyId) }})</label>
                                    <input type="text" id="m2-setup-u{{ $propertyId }}-{{ $j }}-rate" wire:model="utilities.{{ $propertyId }}.{{ $j }}.rate" class="m2-input" inputmode="decimal" placeholder="0" autocomplete="off">
                                </div>
                            </div>
                            @error("utilities.$propertyId.$j.name") <p class="m2-err">{{ $message }}</p> @enderror
                            @error("utilities.$propertyId.$j.rate") <p class="m2-err">{{ $message }}</p> @enderror
                            <p class="m2-muted m2-sm">
                                {{ __('per :unit', ['unit' => $utility['unit_of_measure'] ?: '—']) }} ·
                                <button type="button" class="m2-setup-link" x-on:click="more = ! more">{{ __('More options') }}</button> ·
                                <button type="button" class="m2-setup-link is-bad" wire:click="removeUtility({{ $propertyId }}, {{ $j }})">{{ __('Remove') }}</button>
                            </p>
                            <div class="m2a-two" x-show="more" x-cloak>
                                <div>
                                    <label for="m2-setup-u{{ $propertyId }}-{{ $j }}-billing" class="m2-label">{{ __('How it is charged') }}</label>
                                    <select id="m2-setup-u{{ $propertyId }}-{{ $j }}-billing" wire:model="utilities.{{ $propertyId }}.{{ $j }}.billing_type" class="m2-input">
                                        @foreach ($billingTypes as $value => $billingLabel)
                                            <option value="{{ $value }}">{{ $billingLabel }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label for="m2-setup-u{{ $propertyId }}-{{ $j }}-uom" class="m2-label">{{ __('Unit of measure') }}</label>
                                    <input type="text" id="m2-setup-u{{ $propertyId }}-{{ $j }}-uom" wire:model.blur="utilities.{{ $propertyId }}.{{ $j }}.unit_of_measure" class="m2-input" placeholder="kWh, m³, {{ __('month') }}" autocomplete="off">
                                    @error("utilities.$propertyId.$j.unit_of_measure") <p class="m2-err">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="m2a-empty">{{ __('No utilities yet.') }}</div>
                    @endforelse
                    <button type="button" class="m2-rg-add" wire:click="addUtility({{ $propertyId }})">+ {{ __('Add utility (trash, internet…)') }}</button>
                </section>
            @endforeach
        @endif

        {{-- ═══ Rooms & prices ═══ --}}
        @if ($current === OnboardingStep::Rooms)
            <div class="m2-setup-intro">
                <h1>{{ __('Rooms & prices') }}</h1>
                <p>{{ __('Create rooms in one go, then change the price of any room.') }}</p>
            </div>

            @foreach ($rooms as $propertyId => $config)
                <section class="m2a-stack" wire:key="m2-setup-rooms-{{ $propertyId }}">
                    <h2 class="m2a-h">{{ $this->propertyName($propertyId) }}</h2>
                    @include('livewire.m2.partials.room-grid', [
                        'key' => 'm2-setup-r'.$propertyId,
                        'floors' => "rooms.$propertyId.floors",
                        'perFloor' => "rooms.$propertyId.per_floor",
                        'price' => "rooms.$propertyId.price",
                        'gridModel' => "rooms.$propertyId.grid",
                        'grid' => $config['grid'] ?? [],
                        'symbol' => $this->currencySymbol($propertyId),
                        'pid' => $propertyId,
                    ])
                </section>
            @endforeach
        @endif

        {{-- ═══ Review ═══ --}}
        @if ($current === OnboardingStep::Review)
            <div class="m2-setup-intro">
                <h1>{{ __('Check and finish') }}</h1>
                <p>{{ __('Everything is already saved. You can change any of it later in Settings.') }}</p>
            </div>

            @foreach ($properties as $property)
                @php
                    $pid = $property['id'];
                    $grid = collect($rooms[$pid]['grid'] ?? []);
                    $symbol = $this->currencySymbol($pid);
                    $fmt = fn ($v) => $symbol.rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
                @endphp
                <section class="m2a-stack" wire:key="m2-setup-review-{{ $pid }}">
                    <h2 class="m2a-h">{{ $property['name'] }}</h2>
                    <div class="m2a-list">
                        <div class="m2a-kv"><span>{{ __('Property type') }}</span><b>{{ PropertyType::tryFrom((int) $property['property_type'])?->getLabel() }}</b></div>
                        <div class="m2a-kv"><span>{{ __('Currency') }}</span><b>{{ $property['currency'] }}</b></div>
                        <div class="m2a-kv"><span>{{ __('Rooms') }}</span><b>{{ $grid->count() }}</b></div>
                        @if ($grid->isNotEmpty())
                            <div class="m2a-kv">
                                <span>{{ __('Room prices') }}</span>
                                <b class="m2-num">{{ $grid->min('price') == $grid->max('price') ? $fmt($grid->min('price')) : $fmt($grid->min('price')).' – '.$fmt($grid->max('price')) }}</b>
                            </div>
                        @endif
                        @foreach ($utilities[$pid] ?? [] as $utility)
                            <div class="m2a-kv"><span>{{ $utilityNames[$utility['name']] ?? $utility['name'] }}</span><b class="m2-num">{{ $fmt($utility['rate'] ?: 0) }} / {{ $utility['unit_of_measure'] }}</b></div>
                        @endforeach
                    </div>
                </section>
            @endforeach
        @endif
    </main>

    {{-- Back / Next, pinned to the bottom of the screen --}}
    <div class="m2-setup-actions">
        <div class="m2-wrap">
            <div class="m2-setup-actions-row">
                @if ($current !== OnboardingStep::Properties)
                    <button type="button" class="m2-btn is-ghost" wire:click="back" wire:loading.attr="disabled" id="m2-setup-back">{{ __('Back') }}</button>
                @endif
                @if ($current === OnboardingStep::Review)
                    <button type="button" class="m2-btn" wire:click="finish" wire:loading.attr="disabled" id="m2-setup-finish">{{ __('Finish') }}</button>
                @else
                    <button type="button" class="m2-btn" wire:click="next" wire:loading.attr="disabled" id="m2-setup-next">
                        <span wire:loading.remove wire:target="next">{{ __('Save & continue') }}</span>
                        <span wire:loading wire:target="next">{{ __('Saving…') }}</span>
                    </button>
                @endif
            </div>
            <button type="button" class="m2-setup-later" wire:click="skip" id="m2-setup-later">{{ __('Finish later') }}</button>
        </div>
    </div>
</div>
