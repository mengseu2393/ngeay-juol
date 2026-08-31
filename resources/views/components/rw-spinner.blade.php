{{--
    The RentWise spinner as a standalone element, for the pages outside the Filament panels.
    Inside a panel use <x-filament::loading-indicator>, which draws the same shape.

    Size it with `style="--rw-spinner-size: 2rem"` (or width/height utilities); the dots follow
    the element's `color`, defaulting to the RentWise teal. Geometry is derived in
    public/css/rentwise-loader.css.
--}}
<svg
    viewBox="0 0 50 50"
    xmlns="http://www.w3.org/2000/svg"
    fill="currentColor"
    aria-hidden="true"
    {{ $attributes->class(['rw-spinner']) }}
>
    <circle cx="25" cy="6" r="6"></circle>
    <circle cx="6" cy="25" r="6"></circle>
    <circle cx="44" cy="25" r="6"></circle>
    <circle cx="25" cy="44" r="6"></circle>
</svg>
