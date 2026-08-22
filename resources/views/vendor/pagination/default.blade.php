{{--
    Pagination, in the app's own theme.

    Laravel's stock Tailwind view is built for a light page: white backgrounds,
    grey-300 borders and dark text, which on this app read as a bright slab at
    the foot of every list. It also emits inline SVG chevrons sized for a
    desktop pointer rather than a thumb.

    Written as plain elements with the app's tokens so it matches the rest of
    the media centre, and sized to a 44px touch target.
--}}
@if ($paginator->hasPages())
    <nav class="pagination" aria-label="Pagination">
        {{-- Previous --}}
        @if ($paginator->onFirstPage())
            <span class="pagination__link is-disabled" aria-disabled="true" aria-label="@lang('pagination.previous')">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M15 19l-7-7 7-7" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" wire:navigate
               class="pagination__link" aria-label="@lang('pagination.previous')">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M15 19l-7-7 7-7" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </a>
        @endif

        {{-- Numbers. Hidden on a phone, where the page count is noise and the
             two arrows plus the current page are all that fit. --}}
        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="pagination__gap" aria-hidden="true">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="pagination__link is-active" aria-current="page">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}" wire:navigate class="pagination__link pagination__link--number"
                           aria-label="@lang('Go to page :page', ['page' => $page])">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        {{-- Next --}}
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" wire:navigate
               class="pagination__link" aria-label="@lang('pagination.next')">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M9 5l7 7-7 7" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </a>
        @else
            <span class="pagination__link is-disabled" aria-disabled="true" aria-label="@lang('pagination.next')">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M9 5l7 7-7 7" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </span>
        @endif
    </nav>
@endif
