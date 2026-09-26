@props(['paginador'])

@if ($paginador->hasPages())
    <nav {{ $attributes->class('paginacion') }}>
        @if ($paginador->onFirstPage())
            <span>{!! __('pagination.previous') !!}</span>
        @else
            <a href="{{ $paginador->previousPageUrl() }}">{!! __('pagination.previous') !!}</a>
        @endif

        <span>Página {{ $paginador->currentPage() }} de {{ $paginador->lastPage() }}</span>

        @if ($paginador->hasMorePages())
            <a href="{{ $paginador->nextPageUrl() }}">{!! __('pagination.next') !!}</a>
        @else
            <span>{!! __('pagination.next') !!}</span>
        @endif
    </nav>
@endif
