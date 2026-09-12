{{--
    Simple mode's own pagination view — the app ships no /vendor/pagination
    publish, and Livewire's stock pagination view leans on `dark:` utility
    variants that Filament's precompiled CSS never generated (it only ships
    classes Filament's own components use), so on the dark simple-mode screens
    it rendered as unstyled light boxes. Styled with the existing rw-sm-*
    tokens instead so it matches the rest of the screen.

    Deliberately mirrors Livewire's stock view in using wire:click (not
    <a href>) for the page/prev/next controls: Livewire renders paginated
    components via AJAX, so a plain anchor tag falls back to real browser
    navigation and resolves against the current page's relative path
    (breaking under Filament's /app panel prefix — /app/app/simple).
--}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="rw-sm-pagination">
        <p class="rw-sm-pagination-summary">
            {!! __('Showing') !!}
            @if ($paginator->firstItem())
                <span class="font-semibold">{{ $paginator->firstItem() }}</span>
                {!! __('to') !!}
                <span class="font-semibold">{{ $paginator->lastItem() }}</span>
            @else
                {{ $paginator->count() }}
            @endif
            {!! __('of') !!}
            <span class="font-semibold">{{ $paginator->total() }}</span>
            {!! __('results') !!}
        </p>

        <div class="rw-sm-pagination-controls">
            @if ($paginator->onFirstPage())
                <span class="rw-sm-pagination-btn rw-sm-pagination-disabled" aria-disabled="true" aria-label="{{ __('pagination.previous') }}">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                </span>
            @else
                <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" class="rw-sm-pagination-btn" aria-label="{{ __('pagination.previous') }}">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true"><path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                </button>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="rw-sm-pagination-dots" aria-disabled="true">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        <span wire:key="paginator-{{ $paginator->getPageName() }}-page{{ $page }}">
                            @if ($page == $paginator->currentPage())
                                <span class="rw-sm-pagination-btn rw-sm-pagination-active" aria-current="page">{{ $page }}</span>
                            @else
                                <button type="button" wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')" class="rw-sm-pagination-btn" aria-label="{{ __('Go to page :page', ['page' => $page]) }}">{{ $page }}</button>
                            @endif
                        </span>
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" class="rw-sm-pagination-btn" aria-label="{{ __('pagination.next') }}">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" /></svg>
                </button>
            @else
                <span class="rw-sm-pagination-btn rw-sm-pagination-disabled" aria-disabled="true" aria-label="{{ __('pagination.next') }}">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" /></svg>
                </span>
            @endif
        </div>
    </nav>
@endif
