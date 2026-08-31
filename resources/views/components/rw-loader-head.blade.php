{{--
    Global loading indicator — stylesheet + boot script.

    Belongs in <head>: the CSS has to be applied and the script has to have run before the body
    paints, or the first frame of every page is the bare document. Included by hand in the
    standalone layouts (portal, auth, welcome, invoice view); the two Filament panels inject it
    through their HEAD_END render hook.
--}}
<link rel="stylesheet" href="{{ asset('css/rentwise-loader.css') }}?v={{ @filemtime(public_path('css/rentwise-loader.css')) }}">
<script src="{{ asset('js/rentwise-loader.js') }}?v={{ @filemtime(public_path('js/rentwise-loader.js')) }}"></script>
