{{--
    Placeholder for Simple Mode's lazy-loaded screens (see simple-dashboard.blade.php and
    AppServiceProvider — wired through config('livewire.lazy_placeholder')).

    Livewire requires a single root element. Styling is `rw-sm-skeleton*` in
    rentwise-admin.css: Filament's precompiled Tailwind can't be trusted for arbitrary utilities.
--}}
<div class="rw-sm-skeleton" aria-hidden="true">
    <div class="rw-sm-skeleton__bar rw-sm-skeleton__bar--search"></div>
    @foreach (range(1, 4) as $row)
        <div class="rw-sm-skeleton__card">
            <div class="rw-sm-skeleton__bar rw-sm-skeleton__bar--title"></div>
            <div class="rw-sm-skeleton__bar rw-sm-skeleton__bar--line"></div>
            <div class="rw-sm-skeleton__bar rw-sm-skeleton__bar--short"></div>
        </div>
    @endforeach
</div>
