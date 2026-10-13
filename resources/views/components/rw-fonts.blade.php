{{--
    Self-hosted panel fonts (Kantumruy Pro for Khmer, Plus Jakarta Sans for Latin),
    inlined so there is no stylesheet request at all.

    They used to come from fonts.bunny.net (Filament's default ->font() provider,
    a render-blocking <link>: on a slow or captive mobile network the first paint
    waited on a third-party server) and fonts.googleapis.com (async, so Khmer text
    reflowed when it arrived). The files live in public/fonts/, which the service
    worker caches cache-first; names carry a content hash, so a changed file is a
    new URL. Variable fonts: one file per subset covers weights 400–700.

    Source: Google Fonts (SIL Open Font License 1.1). Subsets kept: khmer, latin,
    latin-ext. The Khmer file is preloaded — every screen needs it.
--}}
<link rel="preload" href="{{ asset('fonts/kantumruy-pro-khmer-efd3b52e.woff2') }}" as="font" type="font/woff2" crossorigin>
<style>
    /* Kantumruy Pro — khmer */
    @font-face {
        font-family: 'Kantumruy Pro';
        font-style: normal;
        font-weight: 400 700;
        font-display: swap;
        src: url('{{ asset('fonts/kantumruy-pro-khmer-efd3b52e.woff2') }}') format('woff2');
        unicode-range: U+1780-17FF, U+19E0-19FF, U+200C-200D, U+25CC;
    }

    /* Kantumruy Pro — latin-ext */
    @font-face {
        font-family: 'Kantumruy Pro';
        font-style: normal;
        font-weight: 400 700;
        font-display: swap;
        src: url('{{ asset('fonts/kantumruy-pro-latin-ext-71166e21.woff2') }}') format('woff2');
        unicode-range: U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C4, U+2113, U+2C60-2C7F, U+A720-A7FF;
    }

    /* Kantumruy Pro — latin */
    @font-face {
        font-family: 'Kantumruy Pro';
        font-style: normal;
        font-weight: 400 700;
        font-display: swap;
        src: url('{{ asset('fonts/kantumruy-pro-latin-ce99f9a1.woff2') }}') format('woff2');
        unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
    }

    /* Plus Jakarta Sans — latin-ext */
    @font-face {
        font-family: 'Plus Jakarta Sans';
        font-style: normal;
        font-weight: 400 700;
        font-display: swap;
        src: url('{{ asset('fonts/plus-jakarta-sans-latin-ext-42f3e901.woff2') }}') format('woff2');
        unicode-range: U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C4, U+2113, U+2C60-2C7F, U+A720-A7FF;
    }

    /* Plus Jakarta Sans — latin */
    @font-face {
        font-family: 'Plus Jakarta Sans';
        font-style: normal;
        font-weight: 400 700;
        font-display: swap;
        src: url('{{ asset('fonts/plus-jakarta-sans-latin-a389d97d.woff2') }}') format('woff2');
        unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
    }
</style>
