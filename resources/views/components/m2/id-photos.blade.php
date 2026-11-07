@props(['photos' => [], 'saved' => [], 'target' => 'idCardPhotos', 'append' => false, 'max' => 2, 'idp' => 'm2-photos'])
{{--
    ID card photos, on Add tenant and Edit tenant: the ones on file (removable), the new ones, and a button
    to add more. Photos are shrunk in the browser first (window.m2ResizeImage in the layout): a phone photo is
    3–12MB and the server may refuse it before Laravel sees it. No `capture`: it would open the camera
    directly and hide the photo library.
--}}
<div {{ $attributes->merge(['class' => 'm2a-card']) }} x-data="{
    uploading: false,
    failed: false,
    async pick(event) {
        const input = event.target;
        const files = Array.from(input.files || []).slice(0, {{ max(0, $max - count($photos)) }});
        input.value = '';
        if (! files.length) { return; }
        this.uploading = true;
        this.failed = false;
        const shrunk = await Promise.all(files.map(async (file, index) => {
            const small = await window.m2ResizeImage(file, 1600, 0.85);
            return small === file ? file : new File([small], 'id-card-' + Date.now() + '-' + index + '.jpg', { type: 'image/jpeg' });
        }));
        const done = () => { this.uploading = false; };
        const failed = () => { this.uploading = false; this.failed = true; };
        this.$wire.uploadMultiple(@js($target), shrunk, done, failed, () => {}, done, @js($append));
    },
}">
    <p class="m2-muted m2-sm" style="margin:0">{{ __('Front/back of national ID, passport, etc.') }}</p>

    <div class="m2-photo-grid">
        @foreach ($saved as $media)
            <div class="m2-photo-tile" wire:key="{{ $idp }}-saved-{{ $media->id }}">
                <img src="{{ \App\Support\M2Shell::mediaUrl($media->getUrl()) }}" alt="{{ __('ID card photo') }}" loading="lazy">
                <button type="button" class="m2-photo-x" wire:click="removeSavedPhoto({{ $media->id }})" wire:confirm="{{ __('Remove this photo?') }}" aria-label="{{ __('Remove photo') }}" id="{{ $idp }}-saved-remove-{{ $media->id }}">×</button>
            </div>
        @endforeach

        @foreach ($photos as $index => $photo)
            <div class="m2-photo-tile is-new" wire:key="{{ $idp }}-new-{{ $index }}">
                @if ($photo->isPreviewable())
                    <img src="{{ $photo->temporaryUrl() }}" alt="{{ __('ID card photo') }}">
                @else
                    <span class="m2-photo-name">{{ $photo->getClientOriginalName() }}</span>
                @endif
                <button type="button" class="m2-photo-x" wire:click="removeIdCardPhoto({{ $index }})" aria-label="{{ __('Remove photo') }}" id="{{ $idp }}-new-remove-{{ $index }}">×</button>
            </div>
        @endforeach

        @if (count($photos) < $max)
            <label for="{{ $idp }}-input" class="m2-photo-add" x-bind:class="{ 'is-busy': uploading }" id="{{ $idp }}-add">
                <span x-show="! uploading">{{ __('Add photo') }}</span>
                <span x-show="uploading" x-cloak>{{ __('Uploading…') }}</span>
                <input type="file" id="{{ $idp }}-input" accept="image/*" multiple x-on:change="pick($event)" style="position:absolute;width:1px;height:1px;opacity:0">
            </label>
        @endif
    </div>

    <p class="m2-err" x-show="failed" x-cloak>{{ __('The photo could not be uploaded. Try another one.') }}</p>
    @error('newIdCardPhotos.*')<p class="m2-err">{{ $message }}</p>@enderror
    @error('idCardPhotos')<p class="m2-err">{{ $message }}</p>@enderror
    @error('idCardPhotos.*')<p class="m2-err">{{ $message }}</p>@enderror
</div>
