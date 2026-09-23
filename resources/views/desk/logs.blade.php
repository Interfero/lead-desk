@extends('layouts.app')
@section('title', 'Логи')
@section('content')
<h1 style="margin-top:0;">Логи</h1>
<div class="card" style="overflow:auto;">
<table>
<thead><tr><th>Время</th><th>CRM</th><th>Уровень</th><th>Действие</th><th>Заказ</th><th>Сообщение</th></tr></thead>
<tbody>
@forelse($logs as $log)
<tr style="cursor:default;">
<td>{{ $log->created_at?->format('d.m.Y H:i:s') }}</td>
<td>{{ $log->connection?->name ?? '—' }}</td>
<td>{{ $log->level }}</td>
<td>{{ $log->action ?? '—' }}</td>
<td>{{ $log->external_id ?? '—' }}</td>
<td>{{ $log->message }}</td>
</tr>
@empty
<tr><td colspan="6" class="muted" style="text-align:center;padding:30px;">Пусто</td></tr>
@endforelse
</tbody>
</table>
</div>
{{ $logs->links() }}
<p><a class="btn btn-sm" href="{{ route('desk.index') }}">Назад</a></p>
@endsection
