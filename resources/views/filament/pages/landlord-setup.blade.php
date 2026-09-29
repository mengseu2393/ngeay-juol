@php
    use App\Enums\OnboardingStep;

    $current = $this->currentStep();
    $percent = (int) round($current->value / OnboardingStep::TOTAL * 100);
@endphp

<div class="rw-setup" x-data x-on:rw-setup-step-changed.window="window.scrollTo({ top: 0, behavior: 'smooth' })">
    {{-- ── Progress ── --}}
    <div class="rw-setup-progress">
        <div class="rw-setup-progress__meta">
            <span>{{ __('Step :current of :total', ['current' => $current->value, 'total' => OnboardingStep::TOTAL]) }}</span>
            <span class="rw-setup-progress__label">{{ $current->getLabel() }}</span>
        </div>
        <div class="rw-setup-progress__track">
            <div class="rw-setup-progress__bar" style="width: {{ $percent }}%"></div>
        </div>
        <ol class="rw-setup-steps">
            <li class="is-done">{{ __('Account') }}</li>
            @foreach (OnboardingStep::cases() as $case)
                <li @class(['is-done' => $case->value < $current->value, 'is-current' => $case === $current])>{{ $case->getLabel() }}</li>
            @endforeach
        </ol>
    </div>

    @error('subscription')
        <div class="rw-sm-error-banner rw-setup-banner" role="alert">{{ $message }}</div>
    @enderror

    {{-- ═══════════════════ Step 2 — Properties ═══════════════════ --}}
    @if ($current === OnboardingStep::Properties)
        <h1 class="rw-setup-title">{{ __('Your properties') }}</h1>
        <p class="rw-setup-lead">{{ __('Add every building or house you rent out. You can add more later.') }}</p>

        @foreach ($properties as $i => $property)
            <section class="rw-setup-card" wire:key="property-{{ $i }}-{{ $property['id'] ?? 'new' }}">
                <div class="rw-setup-card__head">
                    <h2 class="rw-setup-card__title">{{ __('Property :number', ['number' => $i + 1]) }}</h2>
                    @if (count($properties) > 1)
                        <button type="button" class="rw-setup-link rw-setup-link--danger" wire:click="removeProperty({{ $i }})">{{ __('Remove') }}</button>
                    @endif
                </div>

                <div class="rw-setup-field">
                    <label class="rw-sm-label" for="p-{{ $i }}-name">{{ __('Property name') }}</label>
                    <input id="p-{{ $i }}-name" type="text" class="rw-sm-input" wire:model="properties.{{ $i }}.name" placeholder="{{ __('e.g. Sunrise Apartment') }}">
                    @error("properties.$i.name") <p class="rw-sm-error">{{ $message }}</p> @enderror
                </div>

                <div class="rw-setup-field">
                    <label class="rw-sm-label" for="p-{{ $i }}-type">{{ __('Property type') }}</label>
                    <select id="p-{{ $i }}-type" class="rw-sm-input" wire:model="properties.{{ $i }}.property_type">
                        @foreach ($propertyTypes as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error("properties.$i.property_type") <p class="rw-sm-error">{{ $message }}</p> @enderror
                </div>

                <div class="rw-setup-field">
                    <label class="rw-sm-label" for="p-{{ $i }}-address">{{ __('Address') }} <span class="rw-setup-optional">({{ __('optional') }})</span></label>
                    <input id="p-{{ $i }}-address" type="text" class="rw-sm-input" wire:model="properties.{{ $i }}.address_line" placeholder="{{ __('Street, house number') }}">
                </div>

                @php
                    $provinces = \App\Support\LocationHelper::getProvinces();
                    $districts = \App\Support\LocationHelper::getDistricts($property['city'] ?? null);
                    $communes = \App\Support\LocationHelper::getCommunes($property['city'] ?? null, $property['district'] ?? null);
                    $villages = \App\Support\LocationHelper::getVillages($property['city'] ?? null, $property['district'] ?? null, $property['commune'] ?? null);
                @endphp

                @foreach ([
                    ['city', 'City / Province', $provinces, true],
                    ['district', 'District (Khan / Srok)', $districts, ! empty($property['city'])],
                    ['commune', 'Commune (Sangkat / Khum)', $communes, ! empty($property['district'])],
                    ['village', 'Village (Phum)', $villages, ! empty($property['commune'])],
                ] as [$field, $label, $options, $enabled])
                    <div class="rw-setup-field">
                        <label class="rw-sm-label" for="p-{{ $i }}-{{ $field }}">{{ __($label) }} <span class="rw-setup-optional">({{ __('optional') }})</span></label>
                        <select id="p-{{ $i }}-{{ $field }}" class="rw-sm-input" wire:model.live="properties.{{ $i }}.{{ $field }}" @disabled(! $enabled)>
                            <option value="">{{ __('Select') }}</option>
                            @foreach ($options as $value => $optionLabel)
                                <option value="{{ $value }}">{{ $optionLabel }}</option>
                            @endforeach
                        </select>
                        @error("properties.$i.$field") <p class="rw-sm-error">{{ $message }}</p> @enderror
                    </div>
                @endforeach

                <div class="rw-setup-field">
                    <span class="rw-sm-label">{{ __('Currency for rent and bills') }}</span>
                    <div class="rw-setup-segment" role="radiogroup">
                        @foreach (['USD' => __('US Dollar ($)'), 'KHR' => __('Khmer Riel (៛)')] as $code => $label)
                            <label class="rw-setup-segment__option">
                                <input type="radio" value="{{ $code }}" wire:model="properties.{{ $i }}.currency">
                                <span>{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error("properties.$i.currency") <p class="rw-sm-error">{{ $message }}</p> @enderror
                </div>
            </section>
        @endforeach

        <button type="button" class="rw-setup-add" wire:click="addProperty">+ {{ __('Add another property') }}</button>
    @endif

    {{-- ═══════════════════ Step 3 — Utilities ═══════════════════ --}}
    @if ($current === OnboardingStep::Utilities)
        <h1 class="rw-setup-title">{{ __('Utility prices') }}</h1>
        <p class="rw-setup-lead">{{ __('Enter what you charge tenants. Electricity and water are already added — just type the price.') }}</p>

        @foreach ($utilities as $propertyId => $rows)
            <section class="rw-setup-card" wire:key="utilities-{{ $propertyId }}">
                <div class="rw-setup-card__head">
                    <h2 class="rw-setup-card__title">{{ $this->propertyName($propertyId) }}</h2>
                </div>

                @forelse ($rows as $j => $utility)
                    <div class="rw-setup-utility" wire:key="utility-{{ $propertyId }}-{{ $j }}-{{ $utility['id'] ?? 'new' }}" x-data="{ more: {{ $utility['id'] ? 'false' : 'true' }} }">
                        <div class="rw-setup-utility__row">
                            <div class="rw-setup-utility__name">
                                <label class="rw-sm-label" for="u-{{ $propertyId }}-{{ $j }}-name">{{ __('Name') }}</label>
                                <input id="u-{{ $propertyId }}-{{ $j }}-name" type="text" class="rw-sm-input" wire:model="utilities.{{ $propertyId }}.{{ $j }}.name" placeholder="{{ __('e.g. Trash') }}">
                            </div>
                            <div class="rw-setup-utility__price">
                                <label class="rw-sm-label" for="u-{{ $propertyId }}-{{ $j }}-rate">{{ __('Price') }}</label>
                                <div class="rw-setup-money">
                                    <span class="rw-setup-money__symbol">{{ $this->currencySymbol($propertyId) }}</span>
                                    <input id="u-{{ $propertyId }}-{{ $j }}-rate" type="number" inputmode="decimal" step="any" min="0" class="rw-sm-input" wire:model="utilities.{{ $propertyId }}.{{ $j }}.rate" placeholder="0">
                                </div>
                            </div>
                        </div>
                        <p class="rw-setup-hint">
                            {{ __('per :unit', ['unit' => $utility['unit_of_measure'] ?: '—']) }} ·
                            <button type="button" class="rw-setup-link" x-on:click="more = ! more">{{ __('More options') }}</button>
                            · <button type="button" class="rw-setup-link rw-setup-link--danger" wire:click="removeUtility({{ $propertyId }}, {{ $j }})">{{ __('Remove') }}</button>
                        </p>
                        @error("utilities.$propertyId.$j.name") <p class="rw-sm-error">{{ $message }}</p> @enderror
                        @error("utilities.$propertyId.$j.rate") <p class="rw-sm-error">{{ $message }}</p> @enderror

                        <div class="rw-setup-utility__more" x-show="more" x-cloak>
                            <div class="rw-setup-field">
                                <label class="rw-sm-label" for="u-{{ $propertyId }}-{{ $j }}-billing">{{ __('How it is charged') }}</label>
                                <select id="u-{{ $propertyId }}-{{ $j }}-billing" class="rw-sm-input" wire:model="utilities.{{ $propertyId }}.{{ $j }}.billing_type">
                                    @foreach ($billingTypes as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="rw-setup-field">
                                <label class="rw-sm-label" for="u-{{ $propertyId }}-{{ $j }}-uom">{{ __('Unit of measure') }}</label>
                                <input id="u-{{ $propertyId }}-{{ $j }}-uom" type="text" class="rw-sm-input" wire:model.blur="utilities.{{ $propertyId }}.{{ $j }}.unit_of_measure" placeholder="kWh, m³, {{ __('month') }}">
                                @error("utilities.$propertyId.$j.unit_of_measure") <p class="rw-sm-error">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="rw-setup-hint">{{ __('No utilities yet.') }}</p>
                @endforelse

                <button type="button" class="rw-setup-add rw-setup-add--small" wire:click="addUtility({{ $propertyId }})">+ {{ __('Add utility (trash, internet…)') }}</button>
            </section>
        @endforeach
    @endif

    {{-- ═══════════════════ Step 4 — Rooms & prices ═══════════════════ --}}
    @if ($current === OnboardingStep::Rooms)
        <h1 class="rw-setup-title">{{ __('Rooms & prices') }}</h1>
        <p class="rw-setup-lead">{{ __('Create rooms in one go, then change the price of any room.') }}</p>

        @foreach ($rooms as $propertyId => $config)
            <section class="rw-setup-card" wire:key="rooms-{{ $propertyId }}">
                <div class="rw-setup-card__head">
                    <h2 class="rw-setup-card__title">{{ $this->propertyName($propertyId) }}</h2>
                    <span class="rw-setup-badge">{{ count($config['grid']) }} {{ __('rooms') }}</span>
                </div>

                <div class="rw-setup-generator">
                    <div class="rw-setup-field">
                        <label class="rw-sm-label" for="r-{{ $propertyId }}-floors">{{ __('Floors') }}</label>
                        <input id="r-{{ $propertyId }}-floors" type="number" inputmode="numeric" min="1" max="50" class="rw-sm-input" wire:model="rooms.{{ $propertyId }}.floors">
                    </div>
                    <div class="rw-setup-field">
                        <label class="rw-sm-label" for="r-{{ $propertyId }}-per">{{ __('Rooms per floor') }}</label>
                        <input id="r-{{ $propertyId }}-per" type="number" inputmode="numeric" min="1" max="100" class="rw-sm-input" wire:model="rooms.{{ $propertyId }}.per_floor">
                    </div>
                    <div class="rw-setup-field rw-setup-generator__price">
                        <label class="rw-sm-label" for="r-{{ $propertyId }}-price">{{ __('Price per room') }}</label>
                        <div class="rw-setup-money">
                            <span class="rw-setup-money__symbol">{{ $this->currencySymbol($propertyId) }}</span>
                            <input id="r-{{ $propertyId }}-price" type="number" inputmode="decimal" step="any" min="0" class="rw-sm-input" wire:model="rooms.{{ $propertyId }}.price">
                        </div>
                    </div>
                </div>
                @error("rooms.$propertyId.floors") <p class="rw-sm-error">{{ $message }}</p> @enderror
                @error("rooms.$propertyId.per_floor") <p class="rw-sm-error">{{ $message }}</p> @enderror
                @error("rooms.$propertyId.price") <p class="rw-sm-error">{{ $message }}</p> @enderror

                <button type="button" class="rw-setup-generate" wire:click="generateRooms({{ $propertyId }})" wire:loading.attr="disabled">
                    {{ count($config['grid']) ? __('Regenerate rooms') : __('Generate rooms') }}
                </button>
                @if (count($config['grid']))
                    <p class="rw-setup-hint">{{ __('Regenerating keeps any price you changed by hand.') }}</p>
                @endif

                @error("rooms.$propertyId.grid") <p class="rw-sm-error">{{ $message }}</p> @enderror

                @if (count($config['grid']))
                    <div class="rw-setup-rooms">
                        @foreach ($config['grid'] as $k => $room)
                            <div class="rw-setup-room" wire:key="room-{{ $propertyId }}-{{ $k }}-{{ $room['id'] ?? 'new' }}">
                                <div class="rw-setup-room__head">
                                    <input type="text" class="rw-setup-room__number" aria-label="{{ __('Room number') }}" wire:model="rooms.{{ $propertyId }}.grid.{{ $k }}.number" placeholder="{{ __('Room') }}">
                                    <button type="button" class="rw-setup-room__remove" aria-label="{{ __('Remove') }}" wire:click="removeRoom({{ $propertyId }}, {{ $k }})">×</button>
                                </div>
                                <div class="rw-setup-money">
                                    <span class="rw-setup-money__symbol">{{ $this->currencySymbol($propertyId) }}</span>
                                    <input type="number" inputmode="decimal" step="any" min="0" class="rw-sm-input" aria-label="{{ __('Price') }}" wire:model="rooms.{{ $propertyId }}.grid.{{ $k }}.price">
                                </div>
                                @error("rooms.$propertyId.grid.$k.number") <p class="rw-sm-error">{{ $message }}</p> @enderror
                                @error("rooms.$propertyId.grid.$k.price") <p class="rw-sm-error">{{ $message }}</p> @enderror
                            </div>
                        @endforeach
                    </div>
                @endif

                <button type="button" class="rw-setup-add rw-setup-add--small" wire:click="addRoom({{ $propertyId }})">+ {{ __('Add a room') }}</button>
            </section>
        @endforeach
    @endif

    {{-- ═══════════════════ Step 5 — Review ═══════════════════ --}}
    @if ($current === OnboardingStep::Review)
        <h1 class="rw-setup-title">{{ __('Check and finish') }}</h1>
        <p class="rw-setup-lead">{{ __('Everything is already saved. You can change any of it later in Settings.') }}</p>

        @foreach ($properties as $property)
            @php
                $pid = $property['id'];
                $grid = collect($rooms[$pid]['grid'] ?? []);
                $symbol = $this->currencySymbol($pid);
                $fmt = fn ($v) => $symbol.rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
            @endphp
            <section class="rw-setup-card" wire:key="review-{{ $pid }}">
                <div class="rw-setup-card__head">
                    <h2 class="rw-setup-card__title">{{ $property['name'] }}</h2>
                    <span class="rw-setup-badge">{{ $propertyTypes[$property['property_type']] ?? '' }}</span>
                </div>
                <dl class="rw-setup-summary">
                    <div><dt>{{ __('Currency') }}</dt><dd>{{ $property['currency'] }}</dd></div>
                    <div><dt>{{ __('Rooms') }}</dt><dd>{{ $grid->count() }}</dd></div>
                    @if ($grid->isNotEmpty())
                        <div>
                            <dt>{{ __('Room prices') }}</dt>
                            <dd>
                                @if ($grid->min('price') == $grid->max('price'))
                                    {{ $fmt($grid->min('price')) }}
                                @else
                                    {{ $fmt($grid->min('price')) }} – {{ $fmt($grid->max('price')) }}
                                @endif
                            </dd>
                        </div>
                    @endif
                    @foreach ($utilities[$pid] ?? [] as $utility)
                        <div><dt>{{ __($utility['name']) }}</dt><dd>{{ $fmt($utility['rate'] ?: 0) }} / {{ $utility['unit_of_measure'] }}</dd></div>
                    @endforeach
                </dl>
            </section>
        @endforeach
    @endif

    {{-- ── Sticky actions ── --}}
    <div class="rw-setup-actions">
        <div class="rw-setup-actions__row">
            @if ($current !== OnboardingStep::Properties)
                <button type="button" class="rw-setup-btn rw-setup-btn--secondary" wire:click="back" wire:loading.attr="disabled">{{ __('Back') }}</button>
            @endif

            @if ($current === OnboardingStep::Review)
                <button type="button" class="rw-setup-btn rw-setup-btn--primary" wire:click="finish" wire:loading.attr="disabled">{{ __('Finish & go to dashboard') }}</button>
            @else
                <button type="button" class="rw-setup-btn rw-setup-btn--primary" wire:click="next" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="next">{{ __('Save & continue') }}</span>
                    <span wire:loading wire:target="next">{{ __('Saving…') }}</span>
                </button>
            @endif
        </div>
        <button type="button" class="rw-setup-later" wire:click="skip">{{ __('Finish later') }}</button>
    </div>
</div>
