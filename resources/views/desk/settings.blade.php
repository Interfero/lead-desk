@extends('layouts.app')

@section('title', 'Настройки')

@section('content')
<div class="orders-top">
    <div>
        <h1 style="margin:0;font-size:1.25rem;font-weight:600;">Настройки</h1>
        <p class="muted" style="margin:4px 0 0;font-size:13px;">
            Синхронизация источников и доступы kp-lead-centre по филиалам.
            Заказы подтягиваются по кнопке и по крону.
        </p>
    </div>
    <a class="btn btn-sm" href="{{ route('desk.index') }}">К заказам</a>
</div>

<div class="card settings-card" style="margin-bottom:12px;">
    <h3>Синхронизация источников</h3>
    <p class="muted" style="margin:0 0 12px;font-size:13px;">
        Полная синхронизация подключений CRM. Для kp можно также синхронизировать отдельный филиал ниже.
    </p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
        @forelse($connections as $crm)
            @php
                $canSync = $crm->status !== 'inactive' || $crm->type === 'crm2_http';
                $crm2Ready = $crm->type !== 'crm2_http' || ($crm2Configured ?? false);
                $dotClass = $crm->status === 'active' ? 'dot-ok' : ($crm->status === 'error' ? 'dot-err' : 'dot-warn');
                $syncHint = $crm->status === 'active'
                    ? 'Последняя синхронизация: '.($crm->last_sync_at?->timezone('Europe/Moscow')->format('d.m.Y H:i') ?? '—').' МСК'
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
                        <span class="muted" style="font-size:12px;">Сначала сохраните логин и пароль филиала</span>
                    @endif
                </div>
            </form>
        @empty
            <p class="muted" style="margin:0;">Нет подключений CRM.</p>
        @endforelse
    </div>
</div>

@if($cities === [])
    <div class="card settings-card">
        <p class="muted" style="margin:0;">Нет доступных филиалов для вашей роли.</p>
    </div>
@else
    <h2 style="margin:0 0 10px;font-size:1.05rem;font-weight:600;">Доступы kp-lead-centre</h2>
    <p class="muted" style="margin:0 0 12px;font-size:13px;">
        Логин и пароль задаются на филиал. Директор и менеджеры филиала видят одни и те же данные.
    </p>
    <div class="settings-grid">
        @foreach($cities as $city)
            @php $cred = $creds[$city['id']] ?? null; @endphp
            <div class="card settings-card">
                <h3>
                    {{ $city['name'] }}
                    @if($cred?->hasCredentials())
                        <span class="dot {{ $cred->status === 'active' ? 'dot-ok' : ($cred->status === 'error' ? 'dot-err' : 'dot-warn') }}" title="{{ $cred->last_error ?: ($cred->last_sync_at ? $cred->last_sync_at->timezone('Europe/Moscow')->format('d.m.Y H:i').' МСК' : '') }}"></span>
                    @endif
                </h3>
                @if($cred?->last_error)
                    <p class="muted" style="margin:0 0 8px;color:var(--err);font-size:13px;">{{ $cred->last_error }}</p>
                @elseif($cred?->last_sync_at)
                    <p class="muted" style="margin:0 0 8px;font-size:13px;">Последняя синхронизация: {{ $cred->last_sync_at->timezone('Europe/Moscow')->format('d.m.Y H:i') }} МСК</p>
                @endif

                <form method="POST" action="{{ route('desk.settings.save') }}">
                    @csrf
                    <input type="hidden" name="city_id" value="{{ $city['id'] }}">
                    <input type="hidden" name="city_name" value="{{ $city['name'] }}">
                    <div class="settings-row">
                        <div>
                            <label>Логин kp</label>
                            <input class="form-control" type="text" name="login" value="{{ old('login', $cred->login ?? '') }}" autocomplete="username">
                        </div>
                        <div>
                            <label>Пароль {{ $cred?->password ? '(оставьте пустым, чтобы не менять)' : '' }}</label>
                            <input class="form-control" type="password" name="password" value="" autocomplete="new-password" placeholder="{{ $cred?->password ? '••••••••' : '' }}">
                        </div>
                    </div>
                    @if($cred?->password)
                        <label style="display:inline-flex;align-items:center;gap:6px;margin-top:8px;font-size:13px;">
                            <input type="checkbox" name="clear_password" value="1"> Очистить пароль
                        </label>
                    @endif
                    <div class="settings-actions">
                        <button class="btn btn-primary btn-sm" type="submit">Сохранить</button>
                    </div>
                </form>

                @if($cred?->hasCredentials())
                    <div class="settings-actions">
                        <form method="POST" action="{{ route('desk.settings.test') }}">
                            @csrf
                            <input type="hidden" name="city_id" value="{{ $city['id'] }}">
                            <button class="btn btn-sm" type="submit">Проверить вход</button>
                        </form>
                        <form method="POST" action="{{ route('desk.settings.sync', $city['id']) }}">
                            @csrf
                            <button class="btn btn-sm" type="submit">Синхронизировать филиал</button>
                        </form>
                        <form method="POST" action="{{ route('desk.settings.sync-masters', $city['id']) }}">
                            @csrf
                            <button class="btn btn-sm" type="submit" title="Сейчас: импорт мастеров КП в CRM. Обычно не нужно — автосинк каждые 10 минут">Синхронизировать мастеров</button>
                        </form>
                    </div>
                    @php $historyRun = $historyRuns[$city['id']] ?? null; @endphp
                    @if($historyRun)
                        <p class="muted" style="margin:8px 0 0;font-size:12px;">
                            История KP: {{ $historyRun->stateLabel() }} · страниц {{ $historyRun->pages_processed }}, заявок {{ $historyRun->orders_upserted }}
                            @if($historyRun->state === \App\Models\DeskHistorySyncRun::STATE_FAILED && $historyRun->error)
                                · {{ $historyRun->error }}
                            @endif
                        </p>
                    @endif
                    <div class="settings-actions">
                        @foreach([7 => '7 дн.', 30 => '30 дн.', 90 => '90 дн.', 365 => '1 год', 730 => '2 года'] as $days => $label)
                            <form method="POST" action="{{ route('desk.settings.history-sync', ['cityId' => $city['id'], 'days' => $days]) }}">
                                @csrf
                                <button class="btn btn-sm" type="submit">История: {{ $label }}</button>
                            </form>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@endif
@endsection
