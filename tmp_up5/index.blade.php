@extends('layouts.app')

@section('title', 'Заказы')

@section('content')
@php
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
    $filterStatuses = $rawStatusLabels;
    if (!$showClosed) {
        $filterStatuses = array_diff_key($filterStatuses, array_flip(\App\Models\DeskOrderCache::CLOSED_RAW));
    } else {
        $filterStatuses = array_intersect_key($filterStatuses, array_flip(\App\Models\DeskOrderCache::CLOSED_RAW));
    }
@endphp

<div class="desk-tabs-bar" id="desk-tabs-bar">
    <button type="button" class="desk-tab active" data-desk-tab="list" id="desk-tab-list">Список заказов</button>
</div>

<div id="desk-pane-list">
<div class="orders-top">
    <div>
        <h1 style="margin:0;font-size:1.25rem;font-weight:600;">Заказы</h1>
        <p class="muted" style="margin:4px 0 0;font-size:13px;">
            Закрыто через окно: <strong id="desk-closed-count">{{ $closedCount }}</strong>
        </p>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        @foreach($connections as $crm)
            @php
                $canSync = $crm->status !== 'inactive' || $crm->type === 'crm2_http';
                $crm2Ready = $crm->type !== 'crm2_http' || ($crm2Configured ?? false);
                $dotClass = $crm->status === 'active' ? 'dot-ok' : ($crm->status === 'error' ? 'dot-err' : 'dot-warn');
                $syncHint = $crm->status === 'active'
                    ? 'Последняя синхронизация ОК'.($crm->last_sync_at?->format('d.m.Y H:i') ?? '—').' (статус из БД, без пинга)'
                    : ($crm->status === 'error'
                        ? 'Ошибка последней синхронизации: '.($crm->last_error ?: 'неизвестно')
                        : 'Синхронизация ещё не выполнялась');
            @endphp
            <form method="POST" action="{{ route('desk.sync', $crm->id) }}" class="crm-sync-card">
                @csrf
                <span class="dot {{ $dotClass }}" title="{{ $syncHint }}"></span>
                <div class="crm-sync-card__body">
                    <span class="crm-sync-card__name">{{ $crm->name }}</span>
                    @if($canSync && $crm2Ready)
                        <button class="btn btn-sm" type="submit">Синхронизировать</button>
                    @elseif($crm->type === 'crm2_http')
                        <a class="btn btn-sm" href="{{ route('desk.settings') }}">Настроить</a>
                    @endif
                </div>
            </form>
        @endforeach
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
        ? 'Все города'
        : ($selectedCitiesCount ? ($selectedCitiesCount.' гор.') : 'Города');
    $userRoles = $user['roles'] ?? [];
    // Выбор городов — для ГД / рег.дира / разработчика и если доступно больше одного города
    $showCityPicker = count($cities) > 1 && collect($userRoles)->intersect([
        'general_director', 'regional_director', 'developer', 'call_center', 'senior_dispatcher',
    ])->isNotEmpty();
@endphp

@if($showCityPicker)
<div class="desk-cities-picker card">
    <div class="desk-cities-picker__row">
        <div class="desk-cities-picker__label">
            <strong>Города для просмотра</strong>
            <div class="muted" style="font-size:12px;margin-top:2px;">{{ $citiesFilterLabel }}</div>
        </div>
        <div class="multiselect desk-cities-ms" data-name="cities" id="desk-cities-ms">
            <div class="multiselect-selected" onclick="toggleMultiselect(this)">
                <span class="multiselect-text" id="desk-cities-ms-text">{{ $citiesMsText }}</span>
                <span>▾</span>
            </div>
            <div class="multiselect-dropdown desk-cities-ms__dropdown">
                <input type="search" class="table-filter desk-cities-ms__search" id="desk-cities-search"
                    form="deskFiltersForm"
                    placeholder="Найти город…" autocomplete="off"
                    onclick="event.stopPropagation()" onkeydown="event.stopPropagation()">
                <div class="multiselect-actions">
                    <button type="button" onclick="selectAllCities(event, this)">все</button>
                    <button type="button" onclick="deselectAllCities(event, this)">снять</button>
                    <button type="button" class="multiselect-apply-btn" onclick="applyCityPicker(event)">применить</button>
                </div>
                <div class="desk-cities-ms__list" id="desk-cities-ms-list">
                    @foreach($cities as $c)
                        @php $cid = (int) $c['id']; @endphp
                        <label data-city-label="{{ mb_strtolower($c['label'].' '.$c['name']) }}">
                            <input type="checkbox" form="deskFiltersForm" name="cities[]" value="{{ $cid }}"
                                @checked(in_array($cid, $selectedCityIds, true))
                                onchange="updateCitiesMs()">
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
        Только просмотр списка. Синхронизация и права не меняются — заявки не удаляются.
    </p>
</div>
@elseif(count($cities) === 1)
<div class="muted" style="font-size:13px;margin:0 0 10px;">
    Город: <strong style="color:var(--text)">{{ $cities[0]['label'] }}</strong>
</div>
@elseif($citiesFilterLabel !== '')
<div class="muted" style="font-size:12px;margin:0 0 10px;">{{ $citiesFilterLabel }}</div>
@endif

<div class="orders-meta">
    @foreach($statusLabels as $code => $label)
        <span class="pill">{{ $label }}: <strong>{{ $counts[$code] ?? 0 }}</strong></span>
    @endforeach
</div>

<form method="GET" action="{{ route('desk.index') }}" id="deskFiltersForm" class="card">
    @if($showClosed)<input type="hidden" name="show_closed" value="1">@endif
    @if(request('sort'))<input type="hidden" name="sort" value="{{ request('sort') }}">@endif
    @if(request('dir'))<input type="hidden" name="dir" value="{{ request('dir') }}">@endif

    <div class="orders-list-top-bar">
        <div class="muted" style="font-size:13px;">
            @if($orders->total() > 0)
                Показаны записи
                <strong style="color:var(--text)">{{ $orders->firstItem() }}</strong>–
                <strong style="color:var(--text)">{{ $orders->lastItem() }}</strong>
                из <strong style="color:var(--text)">{{ $orders->total() }}</strong>
            @else
                Найдено: <strong style="color:var(--text)">0</strong>
            @endif
        </div>
        <div class="desk-date-filters">
            <input type="date" name="date_from" class="table-filter" value="{{ $displayDateFrom }}" title="С даты">
            <input type="date" name="date_to" class="table-filter" value="{{ $displayDateTo }}" title="По дату">
            <button class="btn btn-sm" type="submit" name="today" value="1">Сегодня</button>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            @if($showClosed)
                <a class="btn btn-sm" href="?{{ http_build_query(request()->except('show_closed')) }}">Активные</a>
            @else
                <a class="btn btn-sm" href="?{{ http_build_query(array_merge(request()->all(), ['show_closed' => '1'])) }}">Закрытые</a>
            @endif
            <button class="btn btn-primary btn-sm" type="submit">Найти</button>
            <a class="btn btn-sm" href="{{ route('desk.index', ['clear_filters' => 1]) }}" title="Сбросить фильтры">Сброс</a>
        </div>
    </div>

    <div class="orders-table-scroll">
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
                <tr>
                    <td>
                        <input class="table-filter" type="text" name="search_id" value="{{ request('search_id') }}" placeholder="ID">
                    </td>
                    <td></td>
                    <td></td>
                    <td>
                        <select name="type" class="form-input table-filter">
                            <option value="">Тип</option>
                            <option value="new" @selected($selectedType === 'new' || $selectedType === 'first')>Впервые</option>
                            <option value="repeat" @selected($selectedType === 'repeat')>Повтор</option>
                            <option value="warranty" @selected($selectedType === 'warranty')>Гарантия</option>
                        </select>
                    </td>
                    <td>
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
                    </td>
                    <td></td>
                    <td>
                        <select name="crm_id" class="form-input table-filter" onchange="this.form.submit()">
                            <option value="">Источник</option>
                            @foreach($connections as $crm)
                                <option value="{{ $crm->id }}" @selected((string)request('crm_id')===(string)$crm->id)>{{ $crm->name }}</option>
                            @endforeach
                        </select>
                    </td>
                    <td>
                        <input class="table-filter" type="text" name="search_name" value="{{ request('search_name') }}" placeholder="Имя">
                    </td>
                    <td>
                        <input class="table-filter" type="text" name="search_address" value="{{ request('search_address') }}" placeholder="Адрес">
                    </td>
                    <td>
                        <input class="table-filter" type="text" name="master" value="{{ request('master') }}" placeholder="Мастер">
                    </td>
                    <td></td>
                    <td>
                        <button class="btn btn-primary btn-sm" type="submit" style="width:100%;">Поиск</button>
                    </td>
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
                    $blink = '';
                    // Как в КП: красное время только для «Ожидает» (скоро / уже просрочено)
                    if (in_array($row->raw_status, ['pending'], true) && $row->call_at_local) {
                        $seconds = $row->call_at_local->getTimestamp() - now()->getTimestamp();
                        if ($seconds < 0) {
                            $blink = 'time-late';
                        } elseif ($seconds <= 3600) {
                            $blink = 'blink-red';
                        }
                    }
                    $sum = $row->paid_amount ?? $row->total_amount;
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
                    <td class="wrap-cell" title="{{ $row->address }}">{{ $row->address ?: '—' }}</td>
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

<div class="desk-order-shell" id="desk-order-shell">
    <div class="desk-order-h">
        <div>
            <h2 id="desk-order-title" style="margin:0;font-size:18px;">Заказ</h2>
            <div id="desk-order-hint" class="muted" style="font-size:13px;margin-top:4px;"></div>
        </div>
        <div class="desk-order-h-actions" style="display:flex;gap:8px;flex-wrap:wrap;">
            <button type="button" class="btn btn-sm" id="desk-btn-copy" style="display:none">Копировать</button>
            <button type="button" class="btn btn-sm" id="desk-btn-save" style="display:none">Сохранить</button>
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
    const shell=document.getElementById('desk-order-shell');
    const body=document.getElementById('desk-order-body');
    const title=document.getElementById('desk-order-title');
    const hint=document.getElementById('desk-order-hint');
    const tabsBar=document.getElementById('desk-tabs-bar');
    const csrf=document.querySelector('meta[name="csrf-token"]').content;
    let currentId=null, canClose=false, lastCopyText='', currentCrmType='', lastPayload=null, lastCalc=null;
    const openTabs=new Map(); // id -> {external_id, title}

    function showList(){
        currentId=null; lastPayload=null; lastCalc=null;
        shell.classList.remove('open');
        listPane.style.display='';
        tabsBar.querySelectorAll('.desk-tab').forEach(t=>t.classList.toggle('active', t.dataset.deskTab==='list'));
        history.replaceState(null,'', location.pathname+location.search.replace(/([?&])order=\d+&?/,'$1').replace(/[?&]$/,''));
    }
    function showOrderPane(){
        listPane.style.display='none';
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
        if(String(currentId)===String(id)) showList();
    }
    document.getElementById('desk-tab-list').onclick=()=>showList();
    document.getElementById('desk-btn-back-list').onclick=()=>showList();
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
            ? 'Провести заказ в kp-lead-centre как «Готов»? Суммы, БСО и документы уйдут в КП.'
            : 'Провести заказ в CRM как «Готов»? Сумма попадёт в кассу.';
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
        const isNoncore=!!lastPayload?.order?.is_noncore;
        const type=lastPayload?.order?.order_type||'first';
        let pct=25;
        if(type==='warranty' && net<=7500) pct=50;
        else if(isNoncore && net<=7500) pct=40;
        else if(net<=2500) pct=25;
        else if(net<=4500) pct=30;
        else if(net<=7500) pct=35;
        else if(net<=10500) pct=40;
        else if(net<=17000) pct=45;
        else pct=50;
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
    async function copyOrderById(id, ev){
        if(ev){ ev.preventDefault(); ev.stopPropagation(); }
        try{
            const res=await fetch(`{{ url('/desk/orders') }}/${id}/copy-text`, {headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
            const data=await res.json();
            if(!res.ok||!data.text) throw new Error(data.message||'empty');
            await copyText(data.text);
            toast('Скопировано', true);
        }catch(e){ toast('Не удалось скопировать', false); }
    }
    document.querySelectorAll('[data-copy-id]').forEach(btn=>{
        btn.addEventListener('click', e=>copyOrderById(btn.dataset.copyId, e));
    });
    document.getElementById('desk-btn-copy').onclick=async()=>{
        try{
            let text=lastCopyText;
            if(!text && currentId){
                const res=await fetch(`{{ url('/desk/orders') }}/${currentId}/copy-text`, {headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
                const data=await res.json();
                text=data.text||'';
            }
            await copyText(text);
            toast('Скопировано', true);
        }catch(e){ toast('Не удалось скопировать', false); }
    };
    function render(p){
        lastPayload=p;
        const o=p.order; const labels=p.status_labels||{}; const types=p.type_labels||{};
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
                ? 'Как в КП: суммы → БСО → документы → Провести'
                : (p.stale?'Данные могут быть устаревшими':'Форма закрытия как в CRM'));
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
            const ts=Date.parse(o.call_at_local);
            if(!Number.isNaN(ts)){
                const sec=(ts-Date.now())/1000;
                if(sec<0) callAtCls='time-late';
                else if(sec<=3600) callAtCls='blink-red';
            }
        }
        const docsCount=(o.documents||[]).length;
        const hist=Array.isArray(o.client_history)?o.client_history:[];
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
        const coreLabel=o.is_noncore?'Непрофиль':'Профиль';
        const callAtDisp=(()=>{
            if(!o.call_at_local) return '';
            const d=new Date(o.call_at_local);
            if(Number.isNaN(d.getTime())) return String(o.call_at_local);
            const pad=n=>String(n).padStart(2,'0');
            return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
        })();
        const calcSide=lastCalc?`
            <div class="order-calc-box">
                <div class="order-calc-row"><span class="order-calc-label">Проведено:</span><span class="order-calc-value">${Number(lastCalc.paid??lastCalc.net_amount??0).toLocaleString('ru-RU')} р.</span></div>
                ${lastCalc.master_percent!=null?`<div class="order-calc-row"><span class="order-calc-label">Доля мастера:</span><span class="order-calc-value">${esc(lastCalc.master_percent)}%</span></div>`:''}
                ${lastCalc.master_salary!=null?`<div class="order-calc-row"><span class="order-calc-label">ЗП мастера:</span><span class="order-calc-value">${Number(lastCalc.master_salary).toLocaleString('ru-RU')} р.</span></div>`:''}
                ${lastCalc.amount_to_pay!=null?`<div class="order-calc-row"><span class="order-calc-label">К сдаче:</span><span class="order-calc-value">${Number(lastCalc.amount_to_pay).toLocaleString('ru-RU')} р.</span></div>`:''}
            </div>`:'';

        body.innerHTML=`
        <div class="order-show-page">
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
                                <label class="form-label">Комментарий к сохранению</label>
                                <textarea id="desk-f-comment" class="form-input" rows="5" ${readOnly?'readonly':''} placeholder="Добавится к истории"></textarea>
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
                        <p class="order-sidebar-muted" style="margin:0.25rem 0 0.75rem;">
                            ${esc(o.connection?.name||'')} · <strong>${esc(statusLabel)}</strong>
                            ${createdAt?` · ${esc(createdAt)}`:''}
                        </p>
                        ${calcSide}
                        <h3 class="order-sidebar-heading">Информация о клиенте</h3>
                        <div style="margin-bottom:0.75rem;">
                            <div class="order-sidebar-person-name">${esc(o.client_name||'—')}${age}</div>
                            <div class="order-sidebar-muted">${o.phone?`📞 ${esc(o.phone)}`:'📞 —'}</div>
                        </div>
                        <div class="order-sidebar-section">
                            <div class="order-sidebar-section-title">Адрес</div>
                            <div class="order-sidebar-city">${esc(o.city_name||'—')}</div>
                            <div class="order-sidebar-muted" style="margin-top:0.35rem;white-space:pre-wrap;">${esc(o.address||'—')}</div>
                        </div>
                        <div class="order-sidebar-section">
                            <div class="order-sidebar-muted"><strong>Тип:</strong> ${esc(typeLabel)}</div>
                            <div class="order-sidebar-muted" style="margin-top:0.25rem;"><strong>Профильность:</strong> ${esc(coreLabel)}</div>
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
                                ${(!readOnly && canClose)?`<button type="button" class="btn btn-primary btn-order-compact" data-desk-action="close">Сохр. и провести</button>`:''}
                                <button type="button" class="btn btn-secondary btn-order-compact" data-desk-action="back">Закрыть</button>
                            </div>
                            ${(!readOnly && canClose)?`
                            <button type="button" class="btn btn-success btn-order-compact" style="width:100%;margin-top:0.5rem;" data-desk-action="close">
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
        body.querySelectorAll('[data-desk-action]').forEach(btn=>{
            btn.addEventListener('click',()=>{
                const a=btn.getAttribute('data-desk-action');
                if(a==='save') document.getElementById('desk-btn-save')?.click();
                else if(a==='copy') document.getElementById('desk-btn-copy')?.click();
                else if(a==='close') document.getElementById('desk-btn-close-order')?.click();
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
    async function load(id){
        currentId=id; lastCalc=null;
        showOrderPane();
        body.textContent='Загрузка…';
        const url=new URL(location.href);
        url.searchParams.set('order', id);
        history.replaceState(null,'', url.pathname+'?'+url.searchParams.toString());
        const res = await fetch(`{{ url('/desk/orders') }}/${id}`, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data=await res.json();
        if(res.status===410||data.gone){ toast(data.error||'Недоступен',false); closeTab(id); return; }
        if(!res.ok){ body.innerHTML='<p style="color:#dc2626">'+(data.message||'Ошибка')+'</p>'; return; }
        if(data.calculation) lastCalc=data.calculation;
        render(data);
        showOrderPane();
    }
    document.querySelectorAll('.desk-row').forEach(r=>r.addEventListener('click',()=>load(r.dataset.id)));
    document.getElementById('desk-btn-save').onclick=async()=>{
        if(!currentId) return;
        try{
            const data=await saveFormFields();
            toast(data.message||'OK',true);
            if(data.order && lastPayload){ lastPayload.order=data.order; }
            await load(currentId);
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
    if(t) t.textContent = n===0 ? 'Город' : (n===boxes.length ? 'Все города' : (n+' гор.'));
}
function selectAllCities(e,btn){
    e.preventDefault(); e.stopPropagation();
    btn.closest('.multiselect').querySelectorAll('.desk-cities-ms__list input[type=checkbox]').forEach(c=>{ c.checked=true; });
    updateCitiesMs();
}
function deselectAllCities(e,btn){
    e.preventDefault(); e.stopPropagation();
    btn.closest('.multiselect').querySelectorAll('.desk-cities-ms__list input[type=checkbox]').forEach(c=>{ c.checked=false; });
    updateCitiesMs();
}
function applyCityPicker(e){
    e.preventDefault(); e.stopPropagation();
    document.getElementById('deskFiltersForm')?.submit();
}
(function(){
    const search=document.getElementById('desk-cities-search');
    const list=document.getElementById('desk-cities-ms-list');
    const empty=document.getElementById('desk-cities-ms-empty');
    if(!search||!list) return;
    search.addEventListener('input',()=>{
        const q=search.value.trim().toLowerCase();
        let visible=0;
        list.querySelectorAll('label').forEach(label=>{
            const hay=label.getAttribute('data-city-label')||'';
            const show=!q || hay.includes(q);
            label.hidden=!show;
            if(show) visible++;
        });
        if(empty) empty.hidden = visible>0;
    });
    updateCitiesMs();
})();
</script>
@endpush
