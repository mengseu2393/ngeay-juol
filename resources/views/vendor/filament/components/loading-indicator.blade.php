{{--
    RentWise loading indicator — overrides Filament's default spinner SVG so every inline
    spinner in the panels (buttons, links, badges, tables, the property switcher) is the same
    mark as the full-page loader. Colour and size still come from the caller: `currentColor`
    and the h-*/w-* classes Filament passes in.

    `rw-spin` replaces Tailwind's `animate-spin` on purpose — this shape turns half a turn per
    second on an ease curve, not a full linear revolution. Geometry lives in
    public/css/rentwise-loader.css; change it there and here together.
--}}
<svg
    viewBox="0 0 50 50"
    xmlns="http://www.w3.org/2000/svg"
    fill="currentColor"
    {{ $attributes->class(['rw-spin']) }}
>
    <circle cx="25" cy="6" r="6"></circle>
    <circle cx="6" cy="25" r="6"></circle>
    <circle cx="44" cy="25" r="6"></circle>
    <circle cx="25" cy="44" r="6"></circle>
</svg>
