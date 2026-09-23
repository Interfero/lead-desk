@if ($paginator->hasPages())
    <nav class="desk-pagination" role="navigation" aria-label="Пагинация">
        @if ($paginator->onFirstPage())
            <span class="desk-page desk-page--disabled" aria-disabled="true">‹</span>
        @else
            <a class="desk-page" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Назад">‹</a>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="desk-page desk-page--disabled">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="desk-page desk-page--active" aria-current="page">{{ $page }}</span>
                    @else
                        <a class="desk-page" href="{{ $url }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a class="desk-page" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Вперёд">›</a>
        @else
            <span class="desk-page desk-page--disabled" aria-disabled="true">›</span>
        @endif
    </nav>
@endif
