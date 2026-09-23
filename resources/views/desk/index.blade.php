@extends('layouts.app')

@section('title', 'Заказы')

@section('content')
@php
    $deskListQuery = request()->except(['show_closed', 'page']);
    $deskActiveUrl = route('desk.index', array_merge($deskListQuery, ['show_closed' => '0']));
    $deskClosedUrl = route('desk.index', array_merge($deskListQuery, ['show_closed' => '1']));
    $sortLink = function (string $field, string $label) {
        $dir = request('sort') === $field && request('dir') === 'asc' ? 'desc' : 'asc';
        $qs = array_merge(request()->except(['page']), ['sort' => $field, 'dir' => $dir]);
        return '<a href="?'.e(http_build_query($qs)).'">'.e($label).'</a>';
    };
    $selectedStatuses = array_values(array_filter((array) request('status', []), fn ($s) => $s !== null && $s !== ''));
    $selectedType = (string) request('type', '');
    $showClosed = !empty($showClosed);
    $displayDateFrom = $displayDateFrom ?? '';
    $displayDateTo = $displayDateTo ?? '';
    $canSeeFraud = !empty($canSeeFraud);
    $filterStatuses = $rawStatusLabels;
    if (!$showClosed) {
        $filterStatuses = array_diff_key($filterStatuses, array_flip(\App\Models\DeskOrderCache::CLOSED_RAW));
    } else {
        $filterStatuses = array_intersect_key($filterStatuses, array_flip(\App\Models\DeskOrderCache::CLOSED_RAW));
    }
@endphp

<div class="desk-tabs-bar" id="desk-tabs-bar">
    <button type="button" class="desk-tab {{ request('tab') === 'fraud' ? '' : 'active' }}" data-desk-tab="list" id="desk-tab-list">Список заказов</button>
    @if($canSeeFraud)
        <button type="button" class="desk-tab {{ request('tab') === 'fraud' ? 'active' : '' }}" data-desk-tab="fraud" id="desk-tab-fraud">
            Фрод @if(($fraudCount ?? 0) > 0)<span class="desk-tab-badge">{{ $fraudCount }}</span>@endif
        </button>
    @endif
</div>

<div id="desk-pane-list" style="{{ request('tab') === 'fraud' ? 'display:none;' : '' }}">
<div class="orders-top">
    <div>
        <h1 style="margin:0;font-size:1.25rem;font-weight:600;">Заказы</h1>
        <p class="muted" style="margin:4px 0 0;font-size:13px;">
            Закрыто через окно: <strong id="desk-closed-count">{{ $closedCount }}</strong>
        </p>
    </div>
</div>

@php
    $cities = $cities ?? [];
    $selectedCityIds = $selectedCityIds ?? array_map(fn ($c) => (int) $c['id'], $cities);
    $citiesFilterAll = $citiesFilterAll ?? true;
    $citiesFilterLabel = $citiesFilterLabel ?? '';
    $selectedCitiesCount = count($selectedCityIds);
    $citiesTotal = count($cities);
    $citiesMsText = $citiesFilterAll || ($citiesTotal > 0 && $selectedCitiesCount === $citiesTotal)
        ? 'Все города ('.$citiesTotal.')'
        : ($selectedCitiesCount ? ('Выбрано: '.$selectedCitiesCount.' из '.$citiesTotal) : 'Города не выбраны');
    // Выбор городов: если доступно больше одного — всегда показываем (ГД/рег и др.)
    $showCityPicker = count($cities) > 1;
@endphp

@if($showCityPicker)
<div class="desk-cities-picker card" data-desk-cities-picker="1">
    <div class="desk-cities-picker__row">
        <div class="desk-cities-picker__label">
            <strong>Города для просмотра</strong>
            <div class="muted" style="font-size:12px;margin-top:2px;" id="desk-cities-caption">{{ $citiesFilterLabel }}</div>
        </div>
        <div class="desk-cities-combo" id="desk-cities-ms">
            <button type="button" class="desk-cities-combo__btn" id="desk-cities-toggle" aria-expanded="false">
                <span id="desk-cities-ms-text">{{ $citiesMsText }}</span>
                <span aria-hidden="true">▾</span>
            </button>
            <div class="desk-cities-combo__panel" id="desk-cities-panel" hidden>
                <input type="search" class="form-input desk-cities-ms__search" id="desk-cities-search"
                    placeholder="Введите название города…" autocomplete="off">
                <div class="desk-cities-combo__actions">
                    <button type="button" class="btn btn-sm" id="desk-cities-all">Все</button>
                    <button type="button" class="btn btn-sm" id="desk-cities-none">Снять</button>
                    <button type="submit" form="deskFiltersForm" class="btn btn-primary btn-sm">Применить</button>
                </div>
                <div class="desk-cities-ms__list" id="desk-cities-ms-list">
                    @foreach($cities as $c)
                        @php $cid = (int) $c['id']; @endphp
                        <label class="desk-cities-combo__item" data-city-label="{{ mb_strtolower($c['label'].' '.$c['name']) }}">
                            <input type="checkbox" form="deskFiltersForm" name="cities[]" value="{{ $cid }}"
                                @checked(in_array($cid, $selectedCityIds, true))>
                            <span>{{ $c['label'] }}</span>
                            @if(!empty($c['is_satellite']))
                                <em class="desk-city-chip__sat">спутник</em>
                            @endif
                        </label>
                    @endforeach
                </div>
                <div class="desk-cities-ms__empty muted" id="desk-cities-ms-empty" hidden>Ничего не найдено</div>
            </div>
        </div>
        <input type="hidden" form="deskFiltersForm" name="filter_cities" value="1">
        <button type="submit" form="deskFiltersForm" class="btn btn-primary btn-sm">Применить</button>
    </div>
    <p class="muted" style="margin:8px 0 0;font-size:12px;">
        Выбор только для списка на экране. Синхронизация и права не меняются — заявки не удаляются.
    </p>
</div>
@elseif(count($cities) === 1)
<div class="muted" style="font-size:13px;margin:0 0 10px;">
    Город: <strong style="color:var(--text)">{{ $cities[0]['label'] }}</strong>
</div>
@elseif($citiesFilterLabel !== '')
<div class="muted" style="font-size:12px;margin:0 0 10px;">{{ $citiesFilterLabel }}</div>
@endif
<form method="GET" action="{{ route('desk.index') }}" id="deskFiltersForm" class="card">
    @if($showClosed)<input type="hidden" name="show_closed" value="1">@endif
    @if(request('sort'))<input type="hidden" name="sort" value="{{ request('sort') }}">@endif
    @if(request('dir'))<input type="hidden" name="dir" value="{{ request('dir') }}">@endif

    <div class="orders-list-top-bar">
        <div class="desk-date-filters">
            <input type="date" name="date_from" class="table-filter" value="{{ $displayDateFrom }}" title="С даты">
            <input type="date" name="date_to" class="table-filter" value="{{ $displayDateTo }}" title="По дату">
            <button class="btn btn-sm" type="submit" name="today" value="1">Сегодня</button>
        </div>
        <div class="desk-top-actions">
            @if($showClosed)
                <a class="btn btn-sm" href="{{ $deskActiveUrl }}">Активные</a>
            @else
                <a class="btn btn-sm" href="{{ $deskClosedUrl }}">Закрытые</a>
            @endif
            <button class="btn btn-primary btn-sm" type="submit">Найти</button>
            <a class="btn btn-sm" href="{{ route('desk.index', ['clear_filters' => 1]) }}" title="Сбросить фильтры">Сброс</a>
        </div>
    </div>

    {{-- Мобильные фильтры (поля desktop-таблицы на телефоне отключаются скриптом) --}}
    <div class="desk-mobile-filters" data-desk-filter-scope="mobile">
        <input class="table-filter" type="text" name="search_id" value="{{ request('search_id') }}" placeholder="ID" disabled>
        <select name="type" class="form-input table-filter" disabled>
            <option value="">Тип</option>
            <option value="new" @selected($selectedType === 'new' || $selectedType === 'first')>Впервые</option>
            <option value="repeat" @selected($selectedType === 'repeat')>Повтор</option>
            <option value="warranty" @selected($selectedType === 'warranty')>Гарантия</option>
        </select>
        <select name="crm_id" class="form-input table-filter" disabled>
            <option value="">Источник</option>
            @foreach($connections as $crm)
                <option value="{{ $crm->id }}" @selected((string)request('crm_id')===(string)$crm->id)>{{ $crm->name }}</option>
            @endforeach
        </select>
        <input class="table-filter" type="text" name="search_name" value="{{ request('search_name') }}" placeholder="Имя" disabled>
        <input class="table-filter" type="text" name="search_address" value="{{ request('search_address') }}" placeholder="Адрес" disabled>
        <input class="table-filter" type="text" name="master" value="{{ request('master') }}" placeholder="Мастер" disabled>
        <div class="desk-mobile-filters__status">
            @foreach($filterStatuses as $code => $label)
                <label class="desk-mobile-status-chip">
                    <input type="checkbox" name="status[]" value="{{ $code }}" @checked(in_array($code, $selectedStatuses, true)) disabled>
                    <span>{{ $label }}</span>
                </label>
            @endforeach
        </div>
        <button class="btn btn-primary" type="submit">Найти</button>
    </div>

    <div class="orders-table-scroll desk-desktop-table">
        <table class="orders-sticky-table">
            <thead>
                <tr>
                    <th style="width:4.5rem;">{!! $sortLink('external_id', 'ID') !!}</th>
                    <th style="width:2rem;" title="Непрофиль">Н</th>
                    <th>{!! $sortLink('call_at_local', 'Время заявки') !!}</th>
                    <th>Тип</th>
                    <th>Статус</th>
                    <th>Город</th>
                    <th>Источник</th>
                    <th>Имя клиента</th>
                    <th>Адрес</th>
                    <th>Мастер</th>
                    <th style="width:90px;">{!! $sortLink('created_at_local', 'Создано') !!}</th>
                    <th>{!! $sortLink('paid_amount', 'Сумма') !!}</th>
                </tr>
                <tr class="desk-filter-row" data-desk-filter-scope="desktop">
                    <th>
                        <input class="table-filter" type="text" name="search_id" value="{{ request('search_id') }}" placeholder="ID">
                    </th>
                    <th></th>
                    <th></th>
                    <th>
                        <select name="type" class="form-input table-filter">
                            <option value="">Тип</option>
                            <option value="new" @selected($selectedType === 'new' || $selectedType === 'first')>Впервые</option>
                            <option value="repeat" @selected($selectedType === 'repeat')>Повтор</option>
                            <option value="warranty" @selected($selectedType === 'warranty')>Гарантия</option>
                        </select>
                    </th>
                    <th>
                        <div class="multiselect multiselect-compact" data-name="status">
                            <div class="multiselect-selected" onclick="toggleMultiselect(this)">
                                <span class="multiselect-text">{{ $selectedStatuses ? (count($selectedStatuses).' выбр.') : 'Статус' }}</span>
                                <span class="ml-auto">▼</span>
                            </div>
                            <div class="multiselect-dropdown">
                                <div class="multiselect-actions">
                                    <button type="button" onclick="selectAllMs(event, this)">все</button>
                                    <button type="button" onclick="deselectAllMs(event, this)">снять</button>
                                    <button type="button" class="multiselect-apply-btn" onclick="applyMs(event, this)">поиск</button>
                                </div>
                                @foreach($filterStatuses as $code => $label)
                                    <label>
                                        <input type="checkbox" name="status[]" value="{{ $code }}"
                                            @checked(in_array($code, $selectedStatuses, true))
                                            onchange="updateMs(this)">
                                        {{ $label }}
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </th>
                    <th></th>
                    <th>
                        <select name="crm_id" class="form-input table-filter" onchange="this.form.submit()">
                            <option value="">Источник</option>
                            @foreach($connections as $crm)
                                <option value="{{ $crm->id }}" @selected((string)request('crm_id')===(string)$crm->id)>{{ $crm->name }}</option>
                            @endforeach
                        </select>
                    </th>
                    <th>
                        <input class="table-filter" type="text" name="search_name" value="{{ request('search_name') }}" placeholder="Имя">
                    </th>
                    <th>
                        <input class="table-filter" type="text" name="search_address" value="{{ request('search_address') }}" placeholder="Адрес">
                    </th>
                    <th>
                        <input class="table-filter" type="text" name="master" value="{{ request('master') }}" placeholder="Мастер">
                    </th>
                    <th colspan="2">
                        <button class="btn btn-primary btn-sm" type="submit">Поиск</button>
                    </th>
                </tr>
            </thead>
            <tbody>
            @forelse($orders as $row)
                @php
                    $typeKey = match ($row->order_type) {
                        'first', 'new' => 'new',
                        'repeat' => 'repeat',
                        'warranty' => 'warranty',
                        default => (string) ($row->order_type ?? ''),
                    };
                    $typeLabel = match ($typeKey) {
                        'new' => 'Впервые',
                        'repeat' => 'Повтор',
                        'warranty' => 'Гарантия',
                        default => $typeLabels[$row->order_type] ?? '—',
                    };
                    $statusCode = $row->raw_status ?: $row->status;
                    $statusLabel = $rawStatusLabels[$row->raw_status]
                        ?? $statusLabels[$row->status]
                        ?? $row->raw_status
                        ?? $row->status;
                    if ($row->needs_feedback) {
                        $statusLabel = rtrim((string) $statusLabel).' (Отз)';
                    }
                    $hl = match ($row->row_highlight) {
                        'red', 'urgent' => 'row-hl-red',
                        'green', 'new' => 'row-hl-green',
                        'yellow', 'warn' => 'row-hl-yellow',
                        default => '',
                    };
                    $blink = $row->callTimeUrgencyClass();
                    $sum = $row->paid_amount ?? $row->total_amount;
                    $addr = \App\Support\DeskAddressOffice::streetAddressForDisplay($row->address, $row->address_office);
                @endphp
                <tr class="order-row desk-row {{ $hl }}" data-id="{{ $row->id }}">
                    <td class="orders-id-cell">
                        <span class="orders-id-cell__actions">
                            <button type="button" class="orders-id-cell__copy" title="Копировать" data-copy-id="{{ $row->id }}">⧉</button>
                        </span>
                        {{ $row->external_id }}
                    </td>
                    <td class="orders-noncore-cell" title="{{ $row->is_noncore ? 'Непрофиль' : '' }}">{{ $row->is_noncore ? 'Н' : '' }}</td>
                    <td class="orders-datetime-cell {{ $blink }}">
                        @if($row->call_at_local)
                            {{ $row->call_at_local->format('d.m.y') }}&nbsp;{{ $row->call_at_local->format('H:i') }}
                        @else
                            —
                        @endif
                    </td>
                    <td class="order-type-cell order-type-{{ $typeKey }}">{{ $typeLabel }}</td>
                    <td class="status-cell status-{{ $statusCode }}">{{ $statusLabel }}</td>
                    <td class="wrap-cell desk-city-cell" title="{{ $row->city_name }}">{{ $row->city_name ?: '—' }}</td>
                    <td title="{{ $row->connection?->name }}">{{ $row->connection?->name ?? '—' }}</td>
                    <td>{{ $row->client_name ?: '—' }}</td>
                    <td class="wrap-cell" title="{{ $addr }}">{{ $addr ?: '—' }}</td>
                    <td>{{ $row->master_name ?: '—' }}</td>
                    <td class="orders-datetime-cell">
                        {{ $row->created_at_local?->format('d.m.y H:i') ?? '—' }}
                    </td>
                    <td>{{ $sum !== null ? number_format((int) $sum, 0, ',', ' ') : '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="12" style="text-align:center;padding:40px;" class="muted">
                        Заказы не найдены. Нажмите «Синхронизировать».
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="desk-mobile-list" aria-label="Список заказов">
        @forelse($orders as $row)
            @php
                $typeKey = match ($row->order_type) {
                    'first', 'new' => 'new',
                    'repeat' => 'repeat',
                    'warranty' => 'warranty',
                    default => (string) ($row->order_type ?? ''),
                };
                $typeLabel = match ($typeKey) {
                    'new' => 'Впервые',
                    'repeat' => 'Повтор',
                    'warranty' => 'Гарантия',
                    default => $typeLabels[$row->order_type] ?? '—',
                };
                $statusCode = $row->raw_status ?: $row->status;
                $statusLabel = $rawStatusLabels[$row->raw_status]
                    ?? $statusLabels[$row->status]
                    ?? $row->raw_status
                    ?? $row->status;
                if ($row->needs_feedback) {
                    $statusLabel = rtrim((string) $statusLabel).' (Отз)';
                }
                $hl = match ($row->row_highlight) {
                    'red', 'urgent' => 'row-hl-red',
                    'green', 'new' => 'row-hl-green',
                    'yellow', 'warn' => 'row-hl-yellow',
                    default => '',
                };
                $blink = $row->callTimeUrgencyClass();
                $sum = $row->paid_amount ?? $row->total_amount;
                $addr = \App\Support\DeskAddressOffice::streetAddressForDisplay($row->address, $row->address_office);
            @endphp
            <article class="desk-m-card desk-row {{ $hl }}" data-id="{{ $row->id }}">
                <div class="desk-m-card__top">
                    <div class="desk-m-card__id">#{{ $row->external_id }}@if($row->is_noncore) <span class="desk-m-card__noncore">Н</span>@endif</div>
                    <div class="desk-m-card__sum">{{ $sum !== null ? number_format((int) $sum, 0, ',', ' ').' ₽' : '—' }}</div>
                </div>
                <div class="desk-m-card__badges">
                    <span class="order-type-cell order-type-{{ $typeKey }}">{{ $typeLabel }}</span>
                    <span class="status-cell status-{{ $statusCode }}">{{ $statusLabel }}</span>
                </div>
                <div class="desk-m-card__time {{ $blink }}">
                    @if($row->call_at_local)
                        {{ $row->call_at_local->format('d.m.y H:i') }}
                    @else
                        —
                    @endif
                </div>
                <div class="desk-m-card__line"><span>Клиент</span><b>{{ $row->client_name ?: '—' }}</b></div>
                <div class="desk-m-card__line"><span>Город</span><b>{{ $row->city_name ?: '—' }}</b></div>
                <div class="desk-m-card__line"><span>Адрес</span><b>{{ $addr ?: '—' }}</b></div>
                <div class="desk-m-card__line"><span>Мастер</span><b>{{ $row->master_name ?: '—' }}</b></div>
                <div class="desk-m-card__line"><span>Источник</span><b>{{ $row->connection?->name ?? '—' }}</b></div>
            </article>
        @empty
            <div class="desk-m-empty muted">Заказы не найдены. Нажмите «Синхронизировать».</div>
        @endforelse
    </div>

    @if($orders->total() > 0)
        <div class="pagination-wrap">
            <p class="muted" style="margin:0;font-size:13px;">
                Показано
                @if($orders->firstItem())
                    <strong style="color:var(--text)">{{ $orders->firstItem() }}</strong>–
                    <strong style="color:var(--text)">{{ $orders->lastItem() }}</strong>
                @endif
                из <strong style="color:var(--text)">{{ $orders->total() }}</strong>
            </p>
            <div>{{ $orders->links('pagination.desk') }}</div>
        </div>
    @endif
</form>
</div>{{-- /desk-pane-list --}}

@if($canSeeFraud)
<div id="desk-pane-fraud" class="desk-pane-fraud" style="{{ request('tab') === 'fraud' ? '' : 'display:none;' }}">
    <div class="card" style="padding:1rem;margin-bottom:12px;">
        <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;">
            <div>
                <h2 style="margin:0;font-size:1.1rem;">Фрод — проверка дублей в другой CRM</h2>
                <p class="muted" style="margin:4px 0 0;font-size:13px;">
                    Показаны проверенные заказы: <strong>не ок</strong> сверху.
                    Совпадение телефона/адреса с незакрытым заказом в другой CRM → не ок.
                    На статусы и проведение не влияет.
                </p>
            </div>
            <form method="POST" action="{{ route('desk.fraud.recheck') }}" id="desk-fraud-recheck-form">
                @csrf
                <button type="submit" class="btn btn-primary btn-sm" id="desk-fraud-recheck-btn">Перепроверить</button>
            </form>
        </div>
    </div>
    <div class="card" style="padding:0;overflow:auto;">
        <table class="orders-sticky-table" style="min-width:900px;">
            <thead>
                <tr>
                    <th style="width:4.5rem;">Фрод</th>
                    <th>ID</th>
                    <th>CRM</th>
                    <th>Статус</th>
                    <th>Клиент</th>
                    @if(!empty($canSeeClientPhone))
                    <th>Телефон</th>
                    @endif
                    <th>Адрес</th>
                    <th>Причина</th>
                </tr>
            </thead>
            <tbody>
            @forelse(($fraudOrders ?? []) as $frow)
                @php
                    $fStatus = $rawStatusLabels[$frow->raw_status]
                        ?? $statusLabels[$frow->status]
                        ?? $frow->raw_status
                        ?? $frow->status;
                    $fFraud = $frow->fraud_status;
                    $fFraudLabel = \App\Services\DeskFraudService::label($fFraud);
                    $fRowClass = $fFraud === \App\Services\DeskFraudService::NOT_OK ? 'fraud-row-hl' : '';
                @endphp
                <tr class="order-row desk-row {{ $fRowClass }}" data-id="{{ $frow->id }}">
                    <td class="fraud-cell fraud-{{ $fFraud ?: 'none' }}">{{ $fFraudLabel }}</td>
                    <td class="orders-id-cell">{{ $frow->external_id }}</td>
                    <td>{{ $frow->connection?->name ?? '—' }}</td>
                    <td class="status-cell status-{{ $frow->raw_status ?: $frow->status }}">{{ $fStatus }}</td>
                    <td>{{ $frow->client_name ?: '—' }}</td>
                    @if(!empty($canSeeClientPhone))
                    <td>{{ $frow->phone ?: '—' }}</td>
                    @endif
                    <td class="wrap-cell" title="{{ \App\Support\DeskAddressOffice::streetAddressForDisplay($frow->address, $frow->address_office) }}">{{ \App\Support\DeskAddressOffice::streetAddressForDisplay($frow->address, $frow->address_office) ?: '—' }}</td>
                    <td class="wrap-cell" style="white-space:normal;max-width:22rem;font-size:12px;{{ $fFraud === 'not_ok' ? 'color:#7f1d1d;' : 'color:var(--muted);' }}">
                        {{ $frow->fraud_note ?: ($fFraud === 'ok' ? '—' : '') }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ !empty($canSeeClientPhone) ? 8 : 7 }}" style="text-align:center;padding:40px;" class="muted">
                        Проверенных заказов пока нет. Нажмите «Перепроверить».
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endif

<div class="desk-order-shell" id="desk-order-shell">
    <div class="desk-order-h">
        <div>
            <h2 id="desk-order-title" style="margin:0;font-size:18px;">Заказ</h2>
            <div id="desk-order-hint" class="muted" style="font-size:13px;margin-top:4px;"></div>
        </div>
        <div class="desk-order-h-actions" style="display:flex;gap:8px;flex-wrap:wrap;">
            <button type="button" class="btn btn-sm" id="desk-btn-copy" style="display:none">Копировать</button>
            <button type="button" class="btn btn-sm" id="desk-btn-save" style="display:none">Сохранить</button>
            <button type="button" class="btn btn-sm" id="desk-btn-save-close" style="display:none">Сохр. и закрыть</button>
            <button type="button" class="btn btn-primary btn-sm" id="desk-btn-close-order" style="display:none">Провести</button>
            <button type="button" class="btn btn-sm" id="desk-btn-back-list">← К списку</button>
        </div>
    </div>
    <div class="desk-order-b" id="desk-order-body">Загрузка…</div>
</div>
<div id="desk-photo-lightbox" class="desk-photo-lightbox" hidden>
    <div class="desk-photo-lightbox-backdrop" data-lb-close></div>
    <button type="button" class="desk-photo-lightbox-close" data-lb-close aria-label="Закрыть">×</button>
    <button type="button" class="desk-photo-lightbox-nav desk-photo-lightbox-prev" data-lb-prev aria-label="Назад">‹</button>
    <button type="button" class="desk-photo-lightbox-nav desk-photo-lightbox-next" data-lb-next aria-label="Вперёд">›</button>
    <div class="desk-photo-lightbox-stage"><img alt=""></div>
    <div class="desk-photo-lightbox-caption"></div>
</div>
@endsection

@push('scripts')
<script>
(function(){
    const listPane=document.getElementById('desk-pane-list');
    const fraudPane=document.getElementById('desk-pane-fraud');
    const shell=document.getElementById('desk-order-shell');
    const body=document.getElementById('desk-order-body');
    const title=document.getElementById('desk-order-title');
    const hint=document.getElementById('desk-order-hint');
    const tabsBar=document.getElementById('desk-tabs-bar');
    const csrf=document.querySelector('meta[name="csrf-token"]').content;
    let currentId=null, canClose=false, lastCopyText='', currentCrmType='', lastPayload=null, lastCalc=null;
    let cardDirty=false, loadGen=0, copyAwaitLive=false, copyUpgradeGen=0;
    const openTabs=new Map(); // id -> {external_id, title}
    let lastListTab='{{ request('tab') === 'fraud' ? 'fraud' : 'list' }}';

    /** Парсит локальное время из API без сдвига UTC→TZ браузера */
    function wallParts(s){
        if(!s) return null;
        const m=String(s).match(/(\d{4})-(\d{2})-(\d{2})[T\s](\d{2}):(\d{2})(?::(\d{2}))?/);
        if(!m) return null;
        return {y:+m[1], mo:+m[2], d:+m[3], h:+m[4], mi:+m[5], se:+(m[6]||0)};
    }
    function wallClockDateTimeLocal(s){
        const p=wallParts(s);
        if(!p) return '';
        const pad=n=>String(n).padStart(2,'0');
        return `${p.y}-${pad(p.mo)}-${pad(p.d)}T${pad(p.h)}:${pad(p.mi)}`;
    }
    function wallClockMs(s){
        const p=wallParts(s);
        if(!p) return NaN;
        return new Date(p.y, p.mo-1, p.d, p.h, p.mi, p.se).getTime();
    }
    function wallClockLabel(s){
        const p=wallParts(s);
        if(!p) return '';
        const pad=n=>String(n).padStart(2,'0');
        return `${pad(p.d)}.${pad(p.mo)}.${p.y}, ${pad(p.h)}:${pad(p.mi)}`;
    }

    function setMainTab(name){
        lastListTab=name;
        if(listPane) listPane.style.display=name==='list'?'':'none';
        if(fraudPane) fraudPane.style.display=name==='fraud'?'':'none';
        tabsBar.querySelectorAll('.desk-tab').forEach(t=>{
            const tab=t.dataset.deskTab;
            const isMain=tab==='list'||tab==='fraud';
            if(isMain) t.classList.toggle('active', tab===name);
            else t.classList.remove('active');
        });
    }
    function showList(){
        currentId=null; lastPayload=null; lastCalc=null;
        shell.classList.remove('open');
        setMainTab('list');
        history.replaceState(null,'', location.pathname+location.search.replace(/([?&])order=\d+&?/,'$1').replace(/[?&]$/,''));
    }
    function showFraud(){
        currentId=null; lastPayload=null; lastCalc=null;
        shell.classList.remove('open');
        setMainTab('fraud');
        const url=new URL(location.href);
        url.searchParams.set('tab','fraud');
        url.searchParams.delete('order');
        history.replaceState(null,'', url.pathname+url.search);
    }
    function showOrderPane(){
        if(listPane) listPane.style.display='none';
        if(fraudPane) fraudPane.style.display='none';
        shell.classList.add('open');
        tabsBar.querySelectorAll('.desk-tab').forEach(t=>{
            t.classList.toggle('active', t.dataset.deskTab===String(currentId));
        });
    }
    function ensureTab(id, label){
        if(tabsBar.querySelector(`[data-desk-tab="${id}"]`)) return;
        const btn=document.createElement('button');
        btn.type='button';
        btn.className='desk-tab';
        btn.dataset.deskTab=String(id);
        btn.innerHTML=`<span>${esc(label)}</span><span class="desk-tab__x" title="Закрыть вкладку">×</span>`;
        btn.addEventListener('click',(e)=>{
            if(e.target.closest('.desk-tab__x')){ closeTab(id); return; }
            load(id);
        });
        tabsBar.appendChild(btn);
        openTabs.set(String(id), {title:label});
    }
    function closeTab(id){
        tabsBar.querySelector(`[data-desk-tab="${id}"]`)?.remove();
        openTabs.delete(String(id));
        if(String(currentId)===String(id)){
            if(lastListTab==='fraud') showFraud();
            else showList();
        }
    }
    document.getElementById('desk-tab-list').onclick=()=>showList();
    document.getElementById('desk-tab-fraud')?.addEventListener('click',()=>showFraud());
    document.getElementById('desk-btn-back-list').onclick=()=>{
        if(lastListTab==='fraud') showFraud();
        else showList();
    };
    if(lastListTab==='fraud'){
        if(listPane) listPane.style.display='none';
        if(fraudPane) fraudPane.style.display='';
    }
    document.addEventListener('click', async (e)=>{
        const btn=e.target.closest?.('.desk-calc-copy');
        if(!btn) return;
        let text = (lastCalc?.source==='kp' && lastCalc?.text) ? lastCalc.text : '';
        if(!text && lastCalc?.source==='kp'){
            const ext=lastCalc.external_id || lastPayload?.order?.external_id || '';
            const group=[lastCalc.calc_group,lastCalc.calc_group_code].filter(Boolean).join(' ');
            text=[
                ext ? 'Заявка №'+ext : null,
                'Проведенная сумма по заявке: '+Number(lastCalc.paid).toLocaleString('ru-RU')+' р.',
                'Сумма к сдаче: '+Number(lastCalc.amount_to_pay).toLocaleString('ru-RU')+' р.',
                group ? 'Группа расчета: '+group : null,
            ].filter(Boolean).join('\n');
        }
        if(!text){ toast('Нечего копировать',false); return; }
        try{ await navigator.clipboard.writeText(text); toast('Расчётка скопирована',true); }
        catch(err){ toast('Не удалось скопировать',false); }
    });

    function crmCloseConfirm(){
        return currentCrmType==='crm2_http'
            ? 'Провести заказ в kp-lead-centre как «Готов»? Суммы, БСО и (от 3000 ₽) документы уйдут в КП.'
            : 'Провести заказ в CRM как «Готов»? Сумма попадёт в кассу. От 3000 ₽ нужен документ.';
    }
    function updNet(){
        const paid=Number(document.getElementById('desk-f-paid')?.value||0);
        const parts=Number(document.getElementById('desk-f-parts')?.value||0);
        const net=(paid-parts).toLocaleString('ru-RU');
        const el=document.getElementById('desk-f-net');
        if(el) el.textContent=net;
        const sub=document.getElementById('subtotal');
        if(sub) sub.textContent=net;
        previewCalc();
    }
    function orderCoreLabel(o){
        const core=o?.order_core;
        if(core==='non_core') return 'Непрофиль';
        if(core==='other') return 'Прочее';
        if(o?.is_noncore) return 'Непрофиль';
        return 'Профиль';
    }
    function masterPercent(net,o){
        const type=o?.order_type||'first';
        const core=o?.order_core||(o?.is_noncore?'non_core':'core');
        if(type==='warranty' && net<=7500) return 50;
        if(o?.is_long_trip) return 50;
        if(o?.is_satellite) return 50;
        if(core==='other') return o?.is_partner_order?40:50;
        if(core==='non_core' && net<=7500) return 40;
        if(net<=2500) return 25;
        if(net<=4500) return 30;
        if(net<=7500) return 35;
        if(net<=10500) return 40;
        if(net<=17000) return 45;
        return 50;
    }
    function previewCalc(){
        const box=document.getElementById('desk-calc-live');
        const sec=document.getElementById('desk-master-section');
        if(!box) return;
        if(currentCrmType==='crm2_http'){
            if(lastCalc && lastCalc.source==='kp'){
                if(sec) sec.style.display='';
                box.innerHTML=calcHtml(lastCalc, true);
                return;
            }
            if(sec && !lastCalc) sec.style.display='none';
            return;
        }
        if(sec) sec.style.display='';
        const paid=Number(document.getElementById('desk-f-paid')?.value||0);
        const parts=Number(document.getElementById('desk-f-parts')?.value||0);
        const net=Math.max(0,paid-parts);
        const o=lastPayload?.order||{};
        const pct=masterPercent(net,o);
        const salary=Math.floor(net*pct/100);
        box.innerHTML=calcHtml({paid,parts,net_amount:net,master_percent:pct,master_salary:salary,amount_to_pay:net-salary}, false);
    }
    function calcHtml(c, done){
        if(!c) return '<p class="muted">Заполните суммы — здесь появится расчётка</p>';
        if(c.source==='kp'){
            const ext=c.external_id || lastPayload?.order?.external_id || '';
            const group=[c.calc_group,c.calc_group_code].filter(Boolean).join(' ');
            return `<div class="desk-calc-box desk-calc-kp">
                <h3>Расчётка из КП</h3>
                ${ext?`<div class="desk-calc-row"><span>Заявка</span><span>№${esc(ext)}</span></div>`:''}
                <div class="desk-calc-row"><span>Проведенная сумма по заявке</span><span>${Number(c.paid).toLocaleString('ru-RU')} ₽</span></div>
                <div class="desk-calc-row"><span>Сумма к сдаче</span><span style="color:#dc2626;font-weight:600">${Number(c.amount_to_pay).toLocaleString('ru-RU')} ₽</span></div>
                ${group?`<div class="desk-calc-row"><span>Группа расчета</span><span>${esc(group)}</span></div>`:''}
                <button type="button" class="btn btn-sm btn-outline desk-calc-copy">Копировать</button>
            </div>`;
        }
        return `<div class="desk-calc-box">
            <h3>${done?'Заказ проведён — расчётка':'Предпросмотр расчётки'}</h3>
            <div class="desk-calc-row"><span>Оплачено</span><span>${Number(c.paid).toLocaleString('ru-RU')} ₽</span></div>
            <div class="desk-calc-row"><span>Комплектующие</span><span>${Number(c.parts).toLocaleString('ru-RU')} ₽</span></div>
            <div class="desk-calc-row"><span>Чистыми</span><span>${Number(c.net_amount).toLocaleString('ru-RU')} ₽</span></div>
            <div class="desk-calc-row"><span>Доля мастера</span><span>${c.master_percent}%</span></div>
            <div class="desk-calc-row"><span>ЗП мастера</span><span>${Number(c.master_salary).toLocaleString('ru-RU')} ₽</span></div>
            <div class="desk-calc-row"><span>К сдаче</span><span>${Number(c.amount_to_pay).toLocaleString('ru-RU')} ₽</span></div>
        </div>`;
    }
    function formPayload(){
        const partsEl=document.getElementById('desk-f-parts');
        const paidEl=document.getElementById('desk-f-paid');
        let parts = partsEl?.value===''?null:Number(partsEl.value);
        let paid = paidEl?.value===''?null:Number(paidEl.value);
        // для КП пустые комплектующие = 0 (нет ЗПЧ)
        if(currentCrmType==='crm2_http' && parts===null) parts=0;
        const payload={
            raw_status: document.getElementById('desk-f-status')?.value||null,
            paid_amount: paid,
            parts_amount: parts,
            comment: document.getElementById('desk-f-comment')?.value||null,
            master_external_id: document.getElementById('desk-f-master')?.value||null,
            master_id: document.getElementById('desk-f-master')?.value||null,
        };
        if(currentCrmType==='crm2_http'){
            const prep=document.getElementById('desk-f-prepayment');
            const bso=document.getElementById('desk-f-bso');
            const zip=document.getElementById('desk-f-zip');
            // предоплату шлём только если поле заполнено (пустое в КП допустимо)
            if(prep && prep.value!=='') payload.prepayment = Number(prep.value);
            if(bso && bso.value!=='') payload.with_bso = Number(bso.value);
            if(zip && zip.value!=='') payload.with_zip = Number(zip.value);
        }
        return payload;
    }
    async function saveFormFields(){
        const res=await fetch('/desk/orders/'+currentId,{method:'PUT',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify(formPayload())});
        const data=await res.json();
        if(!data.ok) throw new Error(data.message||'Ошибка сохранения');
        return data;
    }
    function esc(s){ return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
    function formatPhoneDisplay(p){
        const d=String(p||'').replace(/\D+/g,'');
        if(d.length===10 && d.startsWith('9')) return '+7'+d;
        if(d.length===11 && d.startsWith('8')) return '+7'+d.slice(1);
        if(d.length===11 && d.startsWith('7')) return '+'+d;
        return String(p||'');
    }
    function docViewUrl(orderId, d){
        return `/desk/orders/${orderId}/documents/${encodeURIComponent(d.id)}`;
    }
    function isDocImage(d){
        const mime=(d.mime||d.file_mime||'').toLowerCase();
        if(mime.startsWith('image/')) return true;
        const name=(d.name||'').toLowerCase();
        return /\.(jpe?g|png|gif|webp|bmp)$/i.test(name) || !name.includes('.');
    }
    function docsHtml(docs, cats, orderId, readOnly){
        const by={}; (docs||[]).forEach(d=>{ const c=d.category||'contract'; (by[c]=by[c]||[]).push(d); });
        return Object.entries(cats||{}).map(([code,label])=>{
            const items=by[code]||[];
            const list = items.length
                ? items.map(d=>{
                    const href=docViewUrl(orderId, d);
                    const name=esc(d.name||d.id);
                    const del = readOnly ? '' : `<button type="button" class="desk-file-del" data-del-doc="${esc(d.id)}" title="Удалить">✕</button>`;
                    if(isDocImage(d)){
                        return `<div class="desk-file" data-doc-id="${esc(d.id)}">
                            <div class="desk-photo-wrap">
                                <a href="${esc(href)}" class="desk-photo-thumb" data-full="${esc(href)}" data-caption="${name}" title="${name}">
                                    <img src="${esc(href)}" alt="${name}" loading="lazy">
                                </a>
                                ${del}
                            </div>
                            <div class="desk-file-meta"><span class="desk-file-name" title="${name}">${name}</span></div>
                        </div>`;
                    }
                    return `<div class="desk-file" data-doc-id="${esc(d.id)}">
                        <a href="${esc(href)}" target="_blank" rel="noopener" class="desk-photo-thumb" style="display:flex;align-items:center;justify-content:center;font-size:2rem;text-decoration:none;">📄</a>
                        <div class="desk-file-meta"><a class="desk-file-name" href="${esc(href)}" target="_blank" rel="noopener">${name}</a></div>
                        ${del}
                    </div>`;
                }).join('')
                : `<div class="desk-files-empty">Нет файлов</div>`;
            const note = code==='parts_photos' ? `<div class="order-doc-note-warn">Запчасть должна быть на фоне чека</div>` : '';
            const emoji = ({contract:'📄',receipts:'🧾',parts_photos:'📸',storage_receipt:'🧾'})[code] || '📄';
            const drop = readOnly
                ? `<div class="order-doc-disabled-placeholder">Только просмотр</div>`
                : `<div class="dropzone" data-upload="${esc(code)}">
                        <div class="dropzone-inner">
                            <div class="dropzone-emoji">${emoji}</div>
                            <div>Перетащите фото сюда</div>
                            <div class="dropzone-inner-note">или нажмите для выбора</div>
                        </div>
                   </div>
                   <input type="file" accept=".jpg,.jpeg,.png,.gif,image/*" multiple hidden data-file="${esc(code)}">`;
            return `<div class="order-doc-panel desk-doc-panel" data-cat="${esc(code)}">
                <h3 class="order-documents-heading" style="font-size:1rem;">${esc(label)}</h3>
                ${drop}
                <div class="desk-files order-files-list" data-files="${esc(code)}">${list}</div>${note}
            </div>`;
        }).join('');
    }
    function bindDocUi(root){
        const scope=root||body;
        scope.querySelectorAll('[data-upload]').forEach(dz=>{
            const cat=dz.getAttribute('data-upload');
            const input=scope.querySelector(`[data-file="${cat}"]`);
            dz.addEventListener('click',()=>input?.click());
            dz.addEventListener('dragover',e=>{e.preventDefault(); dz.classList.add('is-drag');});
            dz.addEventListener('dragleave',()=>dz.classList.remove('is-drag'));
            dz.addEventListener('drop',e=>{e.preventDefault(); dz.classList.remove('is-drag'); if(e.dataTransfer.files?.length) uploadFiles(cat, e.dataTransfer.files);});
            input?.addEventListener('change',()=>{ if(input.files?.length) uploadFiles(cat, input.files); input.value=''; });
        });
        scope.querySelectorAll('[data-del-doc]').forEach(btn=>{
            btn.addEventListener('click', async (e)=>{
                e.preventDefault(); e.stopPropagation();
                if(!confirm('Удалить документ?')) return;
                try{
                    const res=await fetch(`/desk/orders/${currentId}/documents/${encodeURIComponent(btn.getAttribute('data-del-doc'))}`,{method:'DELETE',headers:{'Accept':'application/json','X-CSRF-TOKEN':csrf}});
                    const data=await res.json();
                    if(!data.ok) throw new Error(data.message||'Ошибка');
                    if(lastPayload?.order){
                        lastPayload.order.documents=data.documents||data.order?.documents||[];
                        refreshDocsOnly(lastPayload);
                    }
                    toast('Удалено',true);
                }catch(err){ toast(err.message||'Ошибка',false); }
            });
        });
        scope.querySelectorAll('.desk-photo-thumb[data-full]').forEach(a=>{
            a.addEventListener('click',(e)=>{
                e.preventDefault();
                const gallery=a.closest('.desk-files');
                const items=Array.from(gallery.querySelectorAll('.desk-photo-thumb[data-full]')).map(el=>({
                    url: el.getAttribute('data-full'),
                    caption: el.getAttribute('data-caption')||''
                }));
                const idx=items.findIndex(it=>it.url===a.getAttribute('data-full'));
                openLightbox(items, idx<0?0:idx);
            });
        });
    }
    function refreshDocsOnly(p){
        const wrap=body.querySelector('.desk-docs-grid');
        if(!wrap||!p) return;
        const readOnly=!!p.read_only || p.order.raw_status==='completed' || p.order.status==='closed';
        wrap.innerHTML=docsHtml(p.order.documents, p.doc_categories, p.order.id, readOnly);
        bindDocUi(wrap);
    }

    const lbEl=document.getElementById('desk-photo-lightbox');
    const lbImg=lbEl?.querySelector('img');
    const lbCap=lbEl?.querySelector('.desk-photo-lightbox-caption');
    let lbItems=[], lbIdx=0;
    function openLightbox(items, index){
        if(!lbEl||!items.length) return;
        lbItems=items; lbIdx=Math.max(0, Math.min(index, items.length-1));
        renderLightbox();
        lbEl.hidden=false; lbEl.classList.add('open');
        document.body.classList.add('desk-lightbox-open');
    }
    function closeLightbox(){
        if(!lbEl) return;
        lbEl.classList.remove('open'); lbEl.hidden=true;
        document.body.classList.remove('desk-lightbox-open');
        if(lbImg) lbImg.src='';
    }
    function renderLightbox(){
        if(!lbImg||!lbItems.length) return;
        const it=lbItems[lbIdx];
        lbImg.src=it.url; lbImg.alt=it.caption||'';
        if(lbCap) lbCap.textContent = lbItems.length>1 ? `${it.caption||''} (${lbIdx+1}/${lbItems.length})` : (it.caption||'');
        const prev=lbEl.querySelector('[data-lb-prev]'); const next=lbEl.querySelector('[data-lb-next]');
        if(prev) prev.style.display=lbItems.length>1?'':'none';
        if(next) next.style.display=lbItems.length>1?'':'none';
    }
    lbEl?.addEventListener('click',e=>{
        if(e.target.closest('[data-lb-close]')) closeLightbox();
        if(e.target.closest('[data-lb-prev]')){ lbIdx=(lbIdx-1+lbItems.length)%lbItems.length; renderLightbox(); }
        if(e.target.closest('[data-lb-next]')){ lbIdx=(lbIdx+1)%lbItems.length; renderLightbox(); }
    });
    document.addEventListener('keydown',e=>{
        if(!lbEl?.classList.contains('open')) return;
        if(e.key==='Escape') closeLightbox();
        if(e.key==='ArrowLeft'){ lbIdx=(lbIdx-1+lbItems.length)%lbItems.length; renderLightbox(); }
        if(e.key==='ArrowRight'){ lbIdx=(lbIdx+1)%lbItems.length; renderLightbox(); }
    });
    async function uploadFiles(category, fileList){
        const dz=body.querySelector(`[data-upload="${category}"]`);
        const prev=dz?dz.textContent:'';
        const files=Array.from(fileList);
        const total=files.length;
        let done=0, failed=0;
        const setProgress=()=>{ if(dz) dz.textContent=`Загрузка ${done+failed}/${total}…`; };
        setProgress();

        const uploadOne=async (raw)=>{
            const file=await compressImage(raw);
            const fd=new FormData();
            fd.append('category', category);
            fd.append('file', file, file.name||raw.name);
            const res=await fetch(`/desk/orders/${currentId}/documents`,{method:'POST',headers:{'Accept':'application/json','X-CSRF-TOKEN':csrf,'X-Requested-With':'XMLHttpRequest'},body:fd});
            const data=await res.json();
            if(!data.ok) throw new Error(data.message||'Ошибка загрузки');
            if(lastPayload?.order){
                lastPayload.order.documents=data.documents||data.order?.documents||lastPayload.order.documents;
            }
            done++; setProgress();
            toast('Загружено: '+(raw.name||'фото'), true);
        };

        const queue=files.slice();
        const workers=Array.from({length: Math.min(3, queue.length)}, async ()=>{
            while(queue.length){
                const raw=queue.shift();
                try{ await uploadOne(raw); }
                catch(e){ failed++; setProgress(); toast(e.message||('Не удалось: '+raw.name), false); }
            }
        });
        await Promise.all(workers);
        if(lastPayload) refreshDocsOnly(lastPayload);
        if(dz) dz.textContent=prev||'Перетащите фото сюда или нажмите для выбора';
    }
    function compressImage(file){
        return new Promise((resolve)=>{
            if(!file.type?.startsWith('image/')){ resolve(file); return; }
            // сжимаем почти всё крупнее 350KB — быстрее уходит в КП
            if(file.size<350000 && /jpe?g/i.test(file.type||file.name||'')){ resolve(file); return; }
            const img=new Image();
            const url=URL.createObjectURL(file);
            img.onload=()=>{
                const max=1280; let w=img.width, h=img.height;
                if(w>max||h>max){ const s=Math.min(max/w,max/h); w=Math.round(w*s); h=Math.round(h*s); }
                const c=document.createElement('canvas'); c.width=w; c.height=h;
                c.getContext('2d').drawImage(img,0,0,w,h);
                URL.revokeObjectURL(url);
                c.toBlob((blob)=>{
                    if(!blob){ resolve(file); return; }
                    resolve(new File([blob], (file.name||'photo').replace(/\.\w+$/,'.jpg'), {type:'image/jpeg'}));
                }, 'image/jpeg', 0.72);
            };
            img.onerror=()=>{ URL.revokeObjectURL(url); resolve(file); };
            img.src=url;
        });
    }
    function toast(m,ok){const el=document.createElement('div');el.className='flash '+(ok?'flash-ok':'flash-err');el.style.cssText='position:fixed;right:16px;bottom:16px;z-index:60;';el.textContent=m;document.body.appendChild(el);setTimeout(()=>el.remove(),3500);}
    async function copyText(text){
        if(!text) throw new Error('empty');
        if(navigator.clipboard?.writeText){ await navigator.clipboard.writeText(text); return; }
        const ta=document.createElement('textarea'); ta.value=text; document.body.appendChild(ta); ta.select();
        document.execCommand('copy'); ta.remove();
    }
    async function upgradeCopiedText(id, prev, gen){
        await new Promise(r=>setTimeout(r, 2800));
        if(gen!==copyUpgradeGen) return;
        try{
            const res=await fetch(`{{ url('/desk/orders') }}/${id}/copy-text`, {headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
            const data=await res.json();
            if(gen!==copyUpgradeGen || !data.text) return;
            if(String(data.text).length>(String(prev||'').length)){
                await copyText(data.text);
                toast('Описание добавлено в копию', true);
            }
        }catch(e){ /* оставляем первую копию */ }
    }
    async function copyOrderById(id, ev){
        if(ev){ ev.preventDefault(); ev.stopPropagation(); }
        try{
            const res=await fetch(`{{ url('/desk/orders') }}/${id}/copy-text`, {headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
            const data=await res.json();
            if(!res.ok||!data.text) throw new Error(data.message||'empty');
            await copyText(data.text);
            if(data.pending){
                copyUpgradeGen+=1;
                toast('Скопировано. Описание допишем…', true);
                upgradeCopiedText(id, data.text, copyUpgradeGen);
            }else{
                toast('Скопировано', true);
            }
        }catch(e){ toast('Не удалось скопировать', false); }
    }
    document.querySelectorAll('[data-copy-id]').forEach(btn=>{
        btn.addEventListener('click', e=>copyOrderById(btn.dataset.copyId, e));
    });
    document.getElementById('desk-btn-copy').onclick=async()=>{
        try{
            let text=lastCopyText;
            let pending=!!(lastPayload && lastPayload.live_pending && !(lastPayload.order?.comments||lastPayload.order?.description));
            if(!text && currentId){
                const res=await fetch(`{{ url('/desk/orders') }}/${currentId}/copy-text`, {headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
                const data=await res.json();
                text=data.text||'';
                if(data.pending) pending=true;
            }
            await copyText(text);
            if(pending){
                copyAwaitLive=true;
                copyUpgradeGen=loadGen;
                toast('Скопировано. Описание допишем…', true);
            }else{
                copyAwaitLive=false;
                toast('Скопировано', true);
            }
        }catch(e){ toast('Не удалось скопировать', false); }
    };
    function render(p){
        lastPayload=p;
        const o=p.order; const labels=p.status_labels||{}; const types=p.type_labels||{};
        const canSeeClientPhone=!!p.can_see_client_phone;
        const readOnly=!!p.read_only || o.raw_status==='completed' || o.status==='closed';
        canClose=!!p.can_close && !readOnly;
        currentCrmType=p.crm_type||o.connection?.type||'';
        lastCopyText=p.copy_text||'';
        document.getElementById('desk-btn-close-order').style.display='none';
        document.getElementById('desk-btn-save').style.display='none';
        document.getElementById('desk-btn-copy').style.display='none';
        const tabLabel='#'+o.external_id;
        title.textContent='Заказ #'+o.external_id+' · '+(o.connection?.name||'');
        hint.textContent=readOnly
            ? (lastCalc ? 'Заказ проведён — расчётка внизу' : 'Только просмотр')
            : (currentCrmType==='crm2_http'
                ? 'Как в КП: суммы → БСО → (от 3000 ₽ документы) → Провести'
                : (p.stale?'Данные могут быть устаревшими':'Форма закрытия как в CRM'));
        if(p.live_pending && !readOnly){
            hint.textContent=(hint.textContent||'')+' · обновляем…';
        }
        ensureTab(o.id, tabLabel);
        const tabBtn=tabsBar.querySelector(`[data-desk-tab="${o.id}"] span:first-child`);
        if(tabBtn) tabBtn.textContent=tabLabel;

        const masterId=o.master_external_id||o.master_id||'';
        const masterOpts=['<option value="">Выберите мастера</option>']
            .concat((p.masters||[]).map(m=>`<option value="${esc(m.id)}" ${String(m.id)===String(masterId)?'selected':''}>${esc(m.name)}</option>`))
            .join('');
        const statusOpts=(p.settable_statuses||[]).map(c=>`<option value="${c}" ${c===o.raw_status?'selected':''}>${esc(labels[c]||c)}</option>`).join('');
        const isKp=currentCrmType==='crm2_http';
        const paid=o.paid_amount??''; const parts=(o.parts_amount??(isKp?0:''));
        const prep=o.prepayment??0;
        const bso=o.with_bso; const zip=o.with_zip;
        const statusLabel=(labels[o.raw_status]||o.raw_status||'—')+(o.needs_feedback?' (Отз)':'');
        const typeLabel=types[o.order_type]||o.order_type||'—';
        const callAt=o.call_at_local||o.created_at_local||'';
        let callAtCls='';
        if(o.raw_status==='pending' && o.call_at_local){
            const ts=wallClockMs(o.call_at_local);
            if(!Number.isNaN(ts)){
                const sec=(ts-Date.now())/1000;
                const maxLate=48*3600;
                if(sec<0 && sec>=-maxLate) callAtCls='time-late';
                else if(sec>=0 && sec<=3600) callAtCls='blink-red';
            }
        }
        const docsCount=(o.documents||[]).length;
        const hist=Array.isArray(o.client_history)?o.client_history:[];
        const fraudStatus=o.fraud_status||'';
        const fraudNote=o.fraud_note||'';
        const showFraudAlert=!!p.can_see_fraud && fraudStatus==='not_ok';
        const fraudBanner=showFraudAlert?`
            <div class="desk-fraud-alert" role="alert">
                <strong>Фрод: не ок</strong>
                <span>${esc(fraudNote||'Незакрытый заказ с тем же телефоном/адресом в другой CRM.')}</span>
            </div>`:'';
        const ao=p.address_office||{};
        const officeBlock=(()=>{
            if(p.crm_type!=='crm2_http' && !ao.available) return '';
            if(ao.can_reveal){
                return `<div class="desk-office-wrap" style="margin-top:0.5rem;">
                    <button type="button" class="btn btn-sm desk-office-btn" data-desk-office="${esc(String(o.id))}">Показать квартиру</button>
                    <div class="desk-office-text order-sidebar-address" hidden style="margin-top:0.35rem;white-space:pre-wrap;"></div>
                </div>`;
            }
            if(ao.reveal_at){
                const label=wallClockLabel(ao.reveal_at);
                return `<div class="desk-office-locked">Квартира будет доступна с ${esc(label)} (за ${esc(String(ao.minutes_before||15))} мин. до заявки)</div>`;
            }
            if(p.crm_type==='crm2_http'){
                return `<div class="desk-office-locked">Квартира скрыта — нет времени заявки</div>`;
            }
            return '';
        })();
        const histRows=hist.length?hist.map(h=>{
            const cur=h.is_current?' desk-hist-row--current':'';
            const ext=String(h.external_id||'');
            const idAttr=h.id?` data-id="${esc(String(h.id))}"`:'';
            const idCell=h.is_current
                ? esc(ext)
                : `<a href="#" class="desk-hist-open" data-ext="${esc(ext)}"${idAttr}>${esc(ext)}</a>`;
            return `<tr class="${cur}">
                <td>${idCell}</td>
                <td>${esc(h.order_type||'')}</td>
                <td>${esc(h.callback||'')}</td>
                <td>${esc(h.accepted_at||'')}</td>
                <td>${esc(h.closed_at||'')}</td>
                <td>${esc(h.status||'')}</td>
                <td>${esc(h.master||'')}</td>
                <td>${esc(h.amount||'')}</td>
                <td>${esc(h.creator||'')}</td>
                <td>${esc(h.rk||'')}</td>
            </tr>`;
        }).join(''):`<tr><td colspan="10" class="muted" style="text-align:center;padding:16px">Заказов пока нет</td></tr>`;

        const createdAt=o.created_at_local||'';
        const age=o.client_age?`, ${esc(o.client_age)}`:'';
        const coreLabel=orderCoreLabel(o);
        const callAtDisp=wallClockDateTimeLocal(o.call_at_local);
        const calcSide=lastCalc?`
            <div class="order-calc-box">
                <div class="order-calc-row"><span class="order-calc-label">Проведено:</span><span class="order-calc-value">${Number(lastCalc.paid??lastCalc.net_amount??0).toLocaleString('ru-RU')} р.</span></div>
                ${lastCalc.master_percent!=null?`<div class="order-calc-row"><span class="order-calc-label">Доля мастера:</span><span class="order-calc-value">${esc(lastCalc.master_percent)}%</span></div>`:''}
                ${lastCalc.master_salary!=null?`<div class="order-calc-row"><span class="order-calc-label">ЗП мастера:</span><span class="order-calc-value">${Number(lastCalc.master_salary).toLocaleString('ru-RU')} р.</span></div>`:''}
                ${lastCalc.amount_to_pay!=null?`<div class="order-calc-row"><span class="order-calc-label">К сдаче:</span><span class="order-calc-value">${Number(lastCalc.amount_to_pay).toLocaleString('ru-RU')} р.</span></div>`:''}
            </div>`:'';

        body.innerHTML=`
        <div class="order-show-page">
            ${fraudBanner}
            <div class="order-show-layout">
                <div>
                    <div class="card" style="padding:1rem;">
                        <h3 class="mb-4" style="margin:0 0 1rem;font-size:1rem;font-weight:600;">Редактирование заказа</h3>
                        <div class="order-show-grid-row-1">
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label">Тип заказа</label>
                                <input type="text" class="form-input" value="${esc(typeLabel)}" readonly>
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label">Профильность</label>
                                <input type="text" class="form-input" value="${esc(coreLabel)}" readonly>
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label">Дата и время заявки</label>
                                <input type="datetime-local" class="form-input desk-call-at ${callAtCls}" value="${esc(callAtDisp)}" readonly>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">История / комментарии</label>
                                <textarea class="form-input" rows="5" readonly>${esc(o.comments||o.description||'')}</textarea>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Комментарий филиала</label>
                                <textarea id="desk-f-comment" class="form-input" rows="5" ${readOnly?'readonly':''} placeholder="Добавится в «Комментарий филиала» в КП"></textarea>
                            </div>
                        </div>
                        <div class="order-show-grid-row-3">
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label">Мастер</label>
                                <select id="desk-f-master" class="form-input" ${readOnly?'disabled':''}>${masterOpts}</select>
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label">Оплачено *</label>
                                <input id="desk-f-paid" type="number" min="0" class="form-input" value="${esc(paid)}" ${readOnly?'disabled':''}>
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label">Комплектующие *</label>
                                <input id="desk-f-parts" type="number" min="0" class="form-input" value="${esc(parts)}" ${readOnly?'disabled':''}>
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label">Подытог (р.)</label>
                                <span id="subtotal" class="order-show-subtotal">0</span>
                            </div>
                        </div>
                        ${isKp?`
                        <div class="order-show-grid-row-1" style="margin-top:1rem;">
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label">Предоплата</label>
                                <input id="desk-f-prepayment" type="number" min="0" class="form-input" value="${esc(prep)}" ${readOnly?'disabled':''}>
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label">БСО</label>
                                <select id="desk-f-bso" class="form-input" ${readOnly?'disabled':''}>
                                    <option value="">Выберите</option>
                                    <option value="1" ${bso===1||bso==='1'?'selected':''}>Есть</option>
                                    <option value="0" ${bso===0||bso==='0'?'selected':''}>Нет</option>
                                </select>
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label">ЗПЧ</label>
                                <select id="desk-f-zip" class="form-input" ${readOnly?'disabled':''}>
                                    <option value="">Выберите</option>
                                    <option value="1" ${zip===1||zip==='1'?'selected':''}>Есть</option>
                                    <option value="0" ${zip===0||zip==='0'?'selected':''}>Нет</option>
                                </select>
                            </div>
                        </div>`:''}
                    </div>

                    <div class="card order-client-history-card">
                        <div class="order-client-history-header">
                            <h3>История заказов клиента</h3>
                        </div>
                        <div class="order-client-history-body">
                            ${hist.length?`<p class="order-client-history-summary">Показаны записи <strong>1–${hist.length}</strong> из <strong>${hist.length}</strong>.</p>`:''}
                            <div class="table-responsive order-client-history-desktop">
                                <table class="table order-client-history-table desk-hist-table">
                                    <thead>
                                        <tr>
                                            <th class="order-client-history-col-id">ID</th>
                                            <th class="order-client-history-col-type">Тип</th>
                                            <th class="order-client-history-col-callback">Прозвон</th>
                                            <th class="order-client-history-col-date">Дата принятия заявки</th>
                                            <th class="order-client-history-col-date">Дата исполнения</th>
                                            <th>Статус</th><th>Мастер</th><th>Сумма заявки, руб.</th>
                                            <th>Кем создана</th><th class="order-client-history-col-rk">РК</th>
                                        </tr>
                                    </thead>
                                    <tbody>${histRows}</tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="order-show-sidebar">
                    <div class="card" style="padding:1rem;">
                        <h3 class="order-sidebar-heading">Заказ №${esc(o.external_id)}</h3>
                        ${calcSide}
                        <h3 class="order-sidebar-heading">Информация о клиенте</h3>
                        <div style="margin-bottom:0.75rem;">
                            <div class="order-sidebar-person-name">${esc(o.client_name||'—')}${age}</div>
                            ${canSeeClientPhone?(o.phone?`<div class="order-sidebar-muted">📞 ${esc(formatPhoneDisplay(o.phone))}</div>`:`<div class="order-sidebar-muted">📞 —</div>`):''}
                        </div>
                        <div class="order-sidebar-section">
                            <div class="order-sidebar-section-title">Адрес</div>
                            <div class="order-sidebar-city">${esc(o.city_name||'—')}</div>
                            <div class="order-sidebar-address">${esc(o.address||'—')}</div>
                            ${officeBlock}
                        </div>
                        <div class="order-sidebar-section">
                            <div class="order-sidebar-muted"><strong>Тип:</strong> ${esc(typeLabel)}</div>
                            <div class="order-sidebar-muted" style="margin-top:0.25rem;"><strong>Профильность:</strong> ${esc(coreLabel)}</div>
                            <div class="order-sidebar-muted" style="margin-top:0.25rem;"><strong>РК:</strong> ${
                                o.rk_url
                                    ? `<a href="${esc(o.rk_url)}" target="_blank" rel="noopener" style="color:var(--accent)">${esc(o.rk||o.marketing_source||'ссылка')}</a>`
                                    : esc(o.rk||o.marketing_source||'—')
                            }</div>
                            <div class="order-sidebar-muted" style="margin-top:0.25rem;"><strong>CRM:</strong> ${esc(o.connection?.name||'—')}</div>
                        </div>
                        <div class="order-sidebar-section">
                            <label class="form-label" style="margin-bottom:0.5rem;">Статус заказа *</label>
                            <select id="desk-f-status" class="form-input" ${readOnly?'disabled':''}>${statusOpts}</select>
                        </div>
                        <div class="order-sidebar-section">
                            <div class="order-show-buttons">
                                ${readOnly?'':`<button type="button" class="btn btn-primary btn-order-compact" data-desk-action="save">Сохранить</button>`}
                                <button type="button" class="btn btn-secondary btn-order-compact" data-desk-action="copy">Копировать</button>
                                ${readOnly?'':`<button type="button" class="btn btn-primary btn-order-compact" data-desk-action="save-close">Сохр. и закрыть</button>`}
                                <button type="button" class="btn btn-secondary btn-order-compact" data-desk-action="back">Закрыть</button>
                            </div>
                            ${(!readOnly && canClose)?`
                            <button type="button" class="btn btn-success btn-order-compact" style="width:100%;margin-top:0.5rem;" data-desk-action="conduct">
                                Провести заказ
                            </button>`:''}
                        </div>
                    </div>
                </div>
            </div>

            <section class="order-show-documents" aria-label="Документы заказа">
                <h2 class="order-documents-heading">Документы заказа <span class="muted" style="font-size:12px;font-weight:500;">${docsCount} файл(ов)</span></h2>
                <div class="order-show-docs-grid desk-docs-grid">${docsHtml(o.documents, p.doc_categories, o.id, readOnly)}</div>
            </section>

            <section class="desk-order-master card" id="desk-master-section" style="${lastCalc?'padding:1rem;margin-top:1rem;':'display:none'}">
                <h3 class="order-documents-heading">Расчётка мастера</h3>
                <div id="desk-calc-live">${lastCalc?calcHtml(lastCalc,true):''}</div>
            </section>
        </div>`;

        bindDocUi(body);
        body.querySelectorAll('[data-desk-office]').forEach(btn=>{
            btn.addEventListener('click', async ()=>{
                const oid=btn.getAttribute('data-desk-office');
                if(!oid) return;
                btn.disabled=true;
                const prev=btn.textContent;
                btn.textContent='Загрузка…';
                try{
                    const r=await fetch(`/desk/orders/${oid}/address-office`,{headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
                    const j=await r.json().catch(()=>({}));
                    if(!r.ok||!j.ok){
                        toast(j.message||'Не удалось показать квартиру',false);
                        btn.disabled=false;
                        btn.textContent=prev;
                        return;
                    }
                    const box=btn.parentElement?.querySelector('.desk-office-text');
                    if(box){
                        box.hidden=false;
                        box.textContent=j.text||'';
                    }
                    btn.remove();
                }catch(e){
                    toast('Ошибка загрузки квартиры',false);
                    btn.disabled=false;
                    btn.textContent=prev;
                }
            });
        });
        body.querySelectorAll('[data-desk-action]').forEach(btn=>{
            btn.addEventListener('click',()=>{
                const a=btn.getAttribute('data-desk-action');
                if(a==='save') document.getElementById('desk-btn-save')?.click();
                else if(a==='save-close') document.getElementById('desk-btn-save-close')?.click();
                else if(a==='copy') document.getElementById('desk-btn-copy')?.click();
                else if(a==='conduct') document.getElementById('desk-btn-close-order')?.click();
                else if(a==='back') document.getElementById('desk-btn-back-list')?.click();
            });
        });
        body.querySelectorAll('.desk-hist-open').forEach(a=>{
            a.addEventListener('click', async (e)=>{
                e.preventDefault();
                const deskId=a.getAttribute('data-id');
                if(deskId){ load(deskId); return; }
                const ext=a.getAttribute('data-ext');
                if(!ext||!currentId) return;
                toast('Открываем #'+ext+'…', true);
                try{
                    const fromId=currentId;
                    const res=await fetch(`/desk/orders/by-external/${encodeURIComponent(ext)}?from=${fromId}`,{
                        headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}
                    });
                    const data=await res.json();
                    if(!res.ok){ toast(data.message||'Не удалось открыть', false); return; }
                    if(!data.order?.id){ toast('Заказ не найден', false); return; }
                    currentId=data.order.id;
                    lastCalc=data.calculation||null;
                    const url=new URL(location.href);
                    url.searchParams.set('order', currentId);
                    history.replaceState(null,'', url.pathname+'?'+url.searchParams.toString());
                    ensureTab(data.order.id, '#'+data.order.external_id);
                    showOrderPane();
                    render(data);
                }catch(err){ toast('Ошибка загрузки', false); }
            });
        });
        if(!readOnly){
            document.getElementById('desk-f-paid')?.addEventListener('input', updNet);
            document.getElementById('desk-f-parts')?.addEventListener('input', updNet);
        }
        updNet();
        if(lastCalc){
            const sec=document.getElementById('desk-master-section');
            if(sec){ sec.style.display=''; }
        }
    }
    function markCardDirty(){ cardDirty=true; }
    function bindCardDirty(){
        body.querySelectorAll('input,select,textarea').forEach(el=>{
            if(el.readOnly||el.disabled) return;
            el.addEventListener('input', markCardDirty);
            el.addEventListener('change', markCardDirty);
        });
    }
    function fillMasterSelect(masters){
        const sel=document.getElementById('desk-f-master');
        if(!sel || !Array.isArray(masters)) return;
        const cur=sel.value;
        const opts=['<option value="">Выберите мастера</option>']
            .concat(masters.map(m=>`<option value="${esc(m.id)}" ${String(m.id)===String(cur)?'selected':''}>${esc(m.name)}</option>`));
        sel.innerHTML=opts.join('');
        if(cur) sel.value=cur;
    }
    async function load(id, opts){
        const wantLive=!(opts && opts.live===false);
        const gen=++loadGen;
        currentId=id; lastCalc=null; cardDirty=false; copyAwaitLive=false;
        showOrderPane();
        body.textContent='Загрузка…';
        const url=new URL(location.href);
        url.searchParams.set('order', id);
        history.replaceState(null,'', url.pathname+'?'+url.searchParams.toString());
        const headers={ 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        const res = await fetch(`{{ url('/desk/orders') }}/${id}`, {headers});
        const data=await res.json();
        if(gen!==loadGen) return;
        if(res.status===410||data.gone){ toast(data.error||'Недоступен',false); closeTab(id); return; }
        if(!res.ok){ body.innerHTML='<p style="color:#dc2626">'+(data.message||'Ошибка')+'</p>'; return; }
        if(data.calculation) lastCalc=data.calculation;
        render(data);
        bindCardDirty();
        showOrderPane();
        if(!wantLive || !data.live_pending) return;
        try{
            const liveRes=await fetch(`{{ url('/desk/orders') }}/${id}?live=1`, {headers});
            const liveData=await liveRes.json();
            if(gen!==loadGen || String(currentId)!==String(id)) return;
            if(liveRes.status===410||liveData.gone){ toast(liveData.error||'Недоступен',false); closeTab(id); return; }
            if(!liveRes.ok) return;
            if(copyAwaitLive && gen===copyUpgradeGen && liveData.copy_text){
                try{
                    await copyText(liveData.copy_text);
                    lastCopyText=liveData.copy_text;
                    toast('Описание добавлено в копию', true);
                }catch(e){}
                copyAwaitLive=false;
            }
            if(cardDirty){
                if(liveData.masters) fillMasterSelect(liveData.masters);
                if(lastPayload){
                    lastPayload.masters=liveData.masters||lastPayload.masters;
                    lastPayload.live_pending=false;
                    if(liveData.copy_text) lastPayload.copy_text=liveData.copy_text;
                }
                return;
            }
            if(liveData.calculation) lastCalc=liveData.calculation;
            render(liveData);
            bindCardDirty();
            showOrderPane();
        }catch(e){ /* кэш уже на экране */ }
    }
    document.querySelectorAll('.desk-row').forEach(r=>r.addEventListener('click',()=>load(r.dataset.id)));
    document.getElementById('desk-btn-save').onclick=async()=>{
        if(!currentId) return;
        try{
            const data=await saveFormFields();
            toast(data.message||'OK',true);
            if(data.order && lastPayload){ lastPayload.order=data.order; }
            await load(currentId, {live:false});
        }catch(e){ toast(e.message||'Ошибка',false); }
    };
    document.getElementById('desk-btn-save-close').onclick=async()=>{
        if(!currentId) return;
        try{
            const data=await saveFormFields();
            toast(data.message||'Сохранено',true);
            if(lastListTab==='fraud') showFraud();
            else showList();
        }catch(e){ toast(e.message||'Ошибка',false); }
    };
    document.getElementById('desk-btn-close-order').onclick=async()=>{
        if(!currentId||!canClose||!confirm(crmCloseConfirm())) return;
        // для КП не делаем отдельный PUT — поля уходят в finish=1 внутри /close
        if(currentCrmType!=='crm2_http'){
            try{
                await saveFormFields();
            }catch(e){ toast(e.message||'Сначала сохраните данные',false); return; }
        }
        const res=await fetch('/desk/orders/'+currentId+'/close',{
            method:'POST',
            headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf},
            body: JSON.stringify(formPayload())
        });
        const data=await res.json();
        if(!data.ok){ toast(data.message||'Ошибка',false); return; }
        toast(data.message||'Проведён',true);
        if(data.closed_count!=null) document.getElementById('desk-closed-count').textContent=data.closed_count;
        document.querySelector('.desk-row[data-id="'+currentId+'"]')?.remove();
        lastCalc=data.calculation||null;
        if(data.order && lastPayload){
            lastPayload.order=data.order;
            lastPayload.read_only=true;
            lastPayload.calculation=lastCalc;
            render(lastPayload);
        } else if(lastCalc){
            const sec=document.getElementById('desk-master-section');
            const live=document.getElementById('desk-calc-live');
            if(sec) sec.style.display='';
            if(live) live.innerHTML=calcHtml(lastCalc,true);
            document.getElementById('desk-btn-close-order').style.display='none';
            document.getElementById('desk-btn-save').style.display='none';
        }
    };

    // deep-link ?order=ID
    const bootOrder=new URLSearchParams(location.search).get('order');
    if(bootOrder) load(bootOrder);
})();
</script>
<script>
function toggleMultiselect(el){
    const root=el.closest('.multiselect');
    document.querySelectorAll('.multiselect.open').forEach(m=>{ if(m!==root) m.classList.remove('open'); });
    root.classList.toggle('open');
}
function updateMs(input){
    const root=input.closest('.multiselect');
    const n=root.querySelectorAll('input[type=checkbox]:checked').length;
    root.querySelector('.multiselect-text').textContent=n? (n+' выбр.') : 'Статус';
}
function selectAllMs(e,btn){ e.preventDefault(); e.stopPropagation();
    btn.closest('.multiselect').querySelectorAll('input[type=checkbox]').forEach(c=>{c.checked=true;});
    updateMs(btn.closest('.multiselect').querySelector('input'));
}
function deselectAllMs(e,btn){ e.preventDefault(); e.stopPropagation();
    btn.closest('.multiselect').querySelectorAll('input[type=checkbox]').forEach(c=>{c.checked=false;});
    updateMs(btn.closest('.multiselect').querySelector('input')||btn);
    const t=btn.closest('.multiselect').querySelector('.multiselect-text'); if(t) t.textContent='Статус';
}
function applyMs(e,btn){ e.preventDefault(); e.stopPropagation(); btn.closest('form').submit(); }
document.addEventListener('click',e=>{ if(!e.target.closest('.multiselect')) document.querySelectorAll('.multiselect.open').forEach(m=>m.classList.remove('open')); });

function updateCitiesMs(){
    const root=document.getElementById('desk-cities-ms');
    if(!root) return;
    const boxes=[...root.querySelectorAll('.desk-cities-ms__list input[type=checkbox]')];
    const n=boxes.filter(c=>c.checked).length;
    const t=document.getElementById('desk-cities-ms-text');
    if(t) t.textContent = n===0 ? 'Города не выбраны' : (n===boxes.length ? ('Все города ('+boxes.length+')') : ('Выбрано: '+n+' из '+boxes.length));
}
function cityLabelsVisible(){
    return [...document.querySelectorAll('#desk-cities-ms-list label')].filter(l=>!l.hidden);
}
function selectAllCities(){
    cityLabelsVisible().forEach(l=>{ const c=l.querySelector('input[type=checkbox]'); if(c) c.checked=true; });
    updateCitiesMs();
}
function deselectAllCities(){
    cityLabelsVisible().forEach(l=>{ const c=l.querySelector('input[type=checkbox]'); if(c) c.checked=false; });
    updateCitiesMs();
}
(function(){
    const wrap=document.getElementById('desk-cities-ms');
    const btn=document.getElementById('desk-cities-toggle');
    const panel=document.getElementById('desk-cities-panel');
    const search=document.getElementById('desk-cities-search');
    const list=document.getElementById('desk-cities-ms-list');
    const empty=document.getElementById('desk-cities-ms-empty');
    if(!wrap||!btn||!panel||!list) return;

    function normCity(s){
        return String(s||'').toLocaleLowerCase('ru').replace(/ё/g,'е').replace(/\s+/g,' ').trim();
    }
    function filterCities(){
        const q=normCity(search?.value||'');
        let visible=0;
        list.querySelectorAll('label').forEach(label=>{
            const hay=normCity((label.getAttribute('data-city-label')||'')+' '+(label.textContent||''));
            const show=!q || hay.includes(q);
            label.hidden=!show;
            if(show) visible++;
        });
        if(empty) empty.hidden = visible>0;
    }
    function openPanel(open){
        panel.hidden=!open;
        btn.setAttribute('aria-expanded', open?'true':'false');
        if(open) setTimeout(()=>search?.focus(), 0);
    }
    btn.addEventListener('click', (e)=>{ e.preventDefault(); openPanel(panel.hidden); });
    document.addEventListener('click', (e)=>{
        if(!wrap.contains(e.target)) openPanel(false);
    });
    document.getElementById('desk-cities-all')?.addEventListener('click', selectAllCities);
    document.getElementById('desk-cities-none')?.addEventListener('click', deselectAllCities);
    list.querySelectorAll('input[type=checkbox]').forEach(c=>c.addEventListener('change', updateCitiesMs));
    search?.addEventListener('input', filterCities);
    search?.addEventListener('search', filterCities);
    search?.addEventListener('keydown', (e)=>{ if(e.key==='Enter') e.preventDefault(); });
    updateCitiesMs();
})();
</script>
@endpush
