<?php

namespace App\Http\Controllers;

use App\Models\CrmConnection;
use App\Models\DeskLog;
use App\Models\DeskOrderCache;
use App\Models\DeskStat;
use App\Services\DeskOrderService;
use App\Services\DeskSyncService;
use Illuminate\Http\Request;
use Throwable;

class DeskController extends Controller
{
    public function __construct(
        protected DeskOrderService $orders,
        protected DeskSyncService $sync
    ) {}

    public function index(Request $request)
    {
        $user = $request->session()->get('desk_user');
        $sessionKey = 'desk_list_filters_'.($user['id'] ?? 'guest');

        if ($request->boolean('clear_filters')) {
            $request->session()->forget($sessionKey);

            return redirect()->route('desk.index');
        }

        $searchById = $request->filled('search_id');
        $searchAddress = trim((string) $request->input('search_address', ''));
        $searchName = trim((string) $request->input('search_name', ''));
        $hasTargetedLookup = $searchById
            || $searchAddress !== ''
            || $searchName !== ''
            || $request->filled('status');

        if (! $searchById && count($request->query()) === 0) {
            $saved = $request->session()->get($sessionKey);
            if (is_array($saved) && $saved !== []) {
                unset($saved['date_from'], $saved['date_to'], $saved['today']);
                $saved = array_filter(
                    $saved,
                    fn ($value) => $value !== null && $value !== '' && $value !== []
                );
                if ($saved !== []) {
                    return redirect()->route('desk.index', $saved);
                }
            }
        }

        $showClosed = (bool) $request->get('show_closed', 0);
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $hasExplicitDateFrom = is_string($dateFrom) && trim($dateFrom) !== '';
        $hasExplicitDateTo = is_string($dateTo) && trim($dateTo) !== '';

        if ($request->boolean('today')) {
            $dateFrom = now()->format('Y-m-d');
            $dateTo = now()->format('Y-m-d');
            $hasExplicitDateFrom = true;
            $hasExplicitDateTo = true;
        }

        $includeClosed = $showClosed
            || $searchById
            || $request->filled('status')
            || $hasExplicitDateFrom
            || $hasExplicitDateTo;

        $query = $this->orders->baseQueryForSession($user, $includeClosed)->with('connection');

        if ($showClosed && ! $request->filled('status') && ! $searchById) {
            $query->where(function ($q) {
                $q->whereIn('raw_status', DeskOrderCache::CLOSED_RAW)
                    ->orWhere('status', 'closed');
            });
        }

        if ($searchById) {
            $query->where('external_id', 'like', '%'.$request->search_id.'%');
        } else {
            if ($request->filled('city_id')) {
                $query->where('city_id', (int) $request->city_id);
            }
            if ($request->filled('city')) {
                $query->where('city_name', (string) $request->input('city'));
            }
            if ($request->filled('status')) {
                $statuses = array_values(array_filter(
                    (array) $request->input('status'),
                    fn ($s) => $s !== null && $s !== ''
                ));
                if ($statuses !== []) {
                    $query->whereIn('raw_status', $statuses);
                }
            }
            if ($request->filled('type')) {
                $type = (string) $request->input('type');
                $typeMap = ['new' => 'first', 'first' => 'first', 'repeat' => 'repeat', 'warranty' => 'warranty'];
                if (isset($typeMap[$type])) {
                    $query->where('order_type', $typeMap[$type]);
                }
            }
            if ($request->filled('crm_id')) {
                $query->where('crm_id', (int) $request->crm_id);
            }
            if ($request->filled('master') || $request->filled('search_master')) {
                $master = $request->input('master', $request->input('search_master'));
                $query->where('master_name', 'like', '%'.$master.'%');
            }
            if ($searchName !== '') {
                $query->where('client_name', 'like', '%'.$searchName.'%');
            }
            if ($searchAddress !== '') {
                $parts = preg_split('/[\s,]+/u', $searchAddress, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                foreach ($parts as $part) {
                    $query->where('address', 'like', '%'.$part.'%');
                }
            }
            if ($request->filled('q')) {
                $q = $request->q;
                $query->where(function ($b) use ($q) {
                    $b->where('address', 'like', "%{$q}%")
                        ->orWhere('phone', 'like', "%{$q}%")
                        ->orWhere('client_name', 'like', "%{$q}%")
                        ->orWhere('external_id', 'like', "%{$q}%");
                });
            }

            $shouldApplyDateRange = ($hasExplicitDateFrom || $hasExplicitDateTo)
                && $searchAddress === ''
                && $searchName === '';

            if ($shouldApplyDateRange) {
                if ($hasExplicitDateFrom) {
                    $query->whereRaw('COALESCE(call_at_local, created_at_local) >= ?', [$dateFrom]);
                }
                if ($hasExplicitDateTo) {
                    $query->whereRaw('COALESCE(call_at_local, created_at_local) <= ?', [$dateTo.' 23:59:59']);
                }
            }
        }

        $sort = (string) $request->input('sort', '');
        $dir = strtolower((string) $request->input('dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        $sortable = [
            'external_id' => 'external_id',
            'call_at_local' => 'call_at_local',
            'created_at_local' => 'created_at_local',
            'paid_amount' => 'paid_amount',
            'raw_status' => 'raw_status',
        ];

        if ($sort !== '' && isset($sortable[$sort])) {
            if ($sort === 'call_at_local') {
                $query->orderByListDefault($dir);
            } else {
                $query->orderBy($sortable[$sort], $dir)->orderBy('external_id', $dir);
            }
        } else {
            $query->orderByListDefault('asc');
        }

        if (! $searchById) {
            $savedFilters = array_filter(
                [
                    'status' => $request->input('status'),
                    'type' => $request->input('type'),
                    'city_id' => $request->input('city_id'),
                    'crm_id' => $request->input('crm_id'),
                    'master' => $request->input('master'),
                    'search_name' => $request->input('search_name'),
                    'search_address' => $request->input('search_address'),
                    'show_closed' => $showClosed ? '1' : null,
                    'sort' => $request->input('sort'),
                    'dir' => $request->input('dir'),
                ],
                fn ($v) => $v !== null && $v !== '' && $v !== []
            );
            $request->session()->put($sessionKey, $savedFilters);
        }

        $orders = $query->paginate(50)->withQueryString();
        $connections = CrmConnection::query()->orderBy('id')->get();
        $crm2Configured = \App\Models\Crm2CityCredential::query()
            ->whereNotNull('login')
            ->whereNotNull('password')
            ->exists();
        $counts = $this->orders->statusCounts($user);
        $closedCount = DeskStat::getValue('closed_via_desk');
        $statusLabels = DeskOrderCache::unifiedStatusLabels();
        $rawStatusLabels = DeskOrderCache::rawStatusLabels();
        $typeLabels = DeskOrderCache::orderTypeLabels();
        $cities = $this->citiesFromCache($user);
        $displayDateFrom = $hasExplicitDateFrom ? $dateFrom : '';
        $displayDateTo = $hasExplicitDateTo ? $dateTo : '';

        return view('desk.index', compact(
            'orders', 'connections', 'crm2Configured', 'counts', 'closedCount',
            'statusLabels', 'typeLabels', 'cities', 'rawStatusLabels', 'user',
            'showClosed', 'displayDateFrom', 'displayDateTo'
        ));
    }

    public function show(Request $request, int $id)
    {
        $user = $request->session()->get('desk_user');
        $cached = $this->orders->findForSession($user, $id);
        $stale = false;
        $masters = [];
        $calculation = null;

        try {
            // Один запрос к CRM/КП: и поля заказа, и список мастеров
            $connection = $cached->connection;
            $credential = null;
            if ($connection?->type === 'crm2_http' && $cached->city_id) {
                $credential = \App\Models\Crm2CityCredential::query()->where('city_id', $cached->city_id)->first();
            }
            $adapter = $this->sync->makeAdapter($connection, (int) $user['id'], $credential);
            $live = $adapter->fetchOrder($cached->external_id);
            if (is_array($live)) {
                $masters = $live['masters'] ?? [];
                if (! empty($live['calculation']) && is_array($live['calculation'])) {
                    $calculation = $live['calculation'];
                }
                // не затираем documents пустым массивом из КП
                if (empty($live['documents'])) {
                    unset($live['documents']);
                }
                $cached = $this->sync->applyLivePayload($cached, $live);
            } else {
                $cached = $this->sync->refreshCachedOrder($cached, (int) $user['id']);
            }
        } catch (Throwable $e) {
            $stale = true;
            if ($cached->connection?->type !== 'crm2_http'
                && (str_contains($e->getMessage(), 'закрыт') || str_contains($e->getMessage(), 'не найден'))) {
                return response()->json(['error' => $e->getMessage(), 'gone' => true], 410);
            }
        }

        $cached->loadMissing('connection');

        // CRM1: локальная формула; КП — только то, что пришло с карточки
        if ($calculation === null
            && $cached->connection?->type !== 'crm2_http'
            && (in_array((string) $cached->raw_status, DeskOrderCache::CLOSED_RAW, true)
                || $cached->status === 'closed')) {
            $calculation = \App\Support\DeskMasterCalc::compute(
                (int) ($cached->paid_amount ?? 0),
                (int) ($cached->parts_amount ?? 0),
                $cached->order_type,
                (bool) $cached->is_noncore,
            );
        }

        return response()->json($this->orderShowPayload($user, $cached, $stale, $masters, $calculation));
    }

    /**
     * Открыть заказ из истории клиента (по external_id КП), при необходимости импортировать в кэш.
     */
    public function showByExternal(Request $request, string $externalId)
    {
        $user = $request->session()->get('desk_user');
        $fromId = (int) $request->query('from', 0);
        if ($fromId <= 0) {
            return response()->json(['message' => 'Укажите from=id текущего заказа'], 422);
        }

        $fromOrder = $this->orders->findForSession($user, $fromId);

        try {
            $cached = $this->orders->findOrImportByExternal($user, $externalId, $fromOrder);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // дальше тот же путь, что и show — live refresh + payload
        return $this->show($request, (int) $cached->id);
    }

    /**
     * @param  list<array{id?:string,name?:string}>  $masters
     * @return array<string, mixed>
     */
    protected function orderShowPayload(array $user, DeskOrderCache $cached, bool $stale, array $masters, mixed $calculation): array
    {
        $order = $cached->fresh(['connection']) ?? $cached;
        $history = is_array($order->client_history) ? $order->client_history : [];
        if ($history !== []) {
            $extIds = [];
            foreach ($history as $h) {
                $eid = (string) ($h['external_id'] ?? '');
                if ($eid !== '') {
                    $extIds[] = $eid;
                }
            }
            $extIds = array_values(array_unique($extIds));
            $idMap = $extIds === []
                ? collect()
                : DeskOrderCache::query()
                    ->where('crm_id', $order->crm_id)
                    ->whereIn('external_id', $extIds)
                    ->pluck('id', 'external_id');
            foreach ($history as &$h) {
                $eid = (string) ($h['external_id'] ?? '');
                $h['id'] = $idMap[$eid] ?? null;
            }
            unset($h);
            $order->setAttribute('client_history', $history);
        }

        return [
            'order' => $order,
            'stale' => $stale,
            'copy_text' => \App\Support\DeskCopyFormatter::format($order),
            'settable_statuses' => array_keys($this->rawStatusLabelsForConnection($order->connection?->type)),
            'status_labels' => $this->rawStatusLabelsForConnection($order->connection?->type),
            'type_labels' => DeskOrderCache::orderTypeLabels(),
            'masters' => $masters,
            'can_close' => ! empty($user['can_close']),
            'read_only' => false,
            'crm_type' => $order->connection?->type,
            'calculation' => $calculation,
            'doc_categories' => [
                'contract' => 'Договор',
                'receipts' => 'Чеки на комплектующие/расходы',
                'parts_photos' => 'Фото запчастей/комплектующих',
                'storage_receipt' => 'Сохранная расписка',
            ],
        ];
    }

    public function uploadDocument(Request $request, int $id)
    {
        $user = $request->session()->get('desk_user');
        $cached = $this->orders->findForSession($user, $id);
        $validated = $request->validate([
            'category' => 'required|in:contract,receipts,parts_photos,storage_receipt',
            'file' => 'required|file|max:20480|mimes:jpg,jpeg,png,gif,webp,bmp',
        ]);

        try {
            $updated = $this->orders->uploadDocument($cached, $validated['category'], $request->file('file'), $user);

            return response()->json(['ok' => true, 'order' => $updated, 'documents' => $updated->documents]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function deleteDocument(Request $request, int $id, string $documentId)
    {
        $user = $request->session()->get('desk_user');
        $cached = $this->orders->findForSession($user, $id);

        try {
            $updated = $this->orders->deleteDocument($cached, $documentId, $user);

            return response()->json(['ok' => true, 'order' => $updated, 'documents' => $updated->documents]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function showDocument(Request $request, int $id, string $documentId)
    {
        $user = $request->session()->get('desk_user');
        $cached = $this->orders->findForSession($user, $id);
        $adapter = $this->sync->makeAdapter(
            $cached->connection,
            (int) $user['id'],
            $cached->connection?->type === 'crm2_http' && $cached->city_id
                ? \App\Models\Crm2CityCredential::query()->where('city_id', $cached->city_id)->first()
                : null
        );

        $docMeta = null;
        foreach ((array) $cached->documents as $d) {
            if ((string) ($d['id'] ?? '') === (string) $documentId) {
                $docMeta = $d;
                break;
            }
        }

        $file = null;
        // КП: тянем файл через сессию адаптера (прямой URL без cookie не откроется в браузере)
        if ($cached->connection?->type === 'crm2_http' && is_object($adapter) && method_exists($adapter, 'fetchDocumentByUrl')) {
            $url = is_array($docMeta) ? ($docMeta['url'] ?? null) : null;
            if (is_string($url) && $url !== '') {
                $file = $adapter->fetchDocumentByUrl($url, (string) ($docMeta['name'] ?? 'photo.jpg'));
            }
        }

        if ($file === null) {
            $file = $adapter->downloadDocument($cached->external_id, $documentId);
        }

        if (! $file) {
            abort(404);
        }

        return response($file['body'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'inline; filename="'.($file['name'] ?? 'doc').'"',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function copyText(Request $request, int $id)
    {
        $user = $request->session()->get('desk_user');
        $cached = $this->orders->findForSession($user, $id);

        try {
            if ($cached->connection?->type === 'crm2_http' && ! filled($cached->description)) {
                $cached = $this->sync->refreshCachedOrder($cached, (int) $user['id']);
            }
        } catch (Throwable) {
            // копируем то, что есть в кэше
        }

        $text = \App\Support\DeskCopyFormatter::format($cached);

        return response()->json(['ok' => true, 'text' => $text]);
    }

    public function update(Request $request, int $id)
    {
        $user = $request->session()->get('desk_user');
        $cached = $this->orders->findForSession($user, $id);
        $validated = $request->validate([
            'raw_status' => 'nullable|string|max:64',
            'paid_amount' => 'nullable|integer|min:0',
            'parts_amount' => 'nullable|integer|min:0',
            'prepayment' => 'nullable|integer|min:0',
            'with_bso' => 'nullable|integer|in:0,1',
            'with_zip' => 'nullable|integer|in:0,1',
            'comment' => 'nullable|string|max:2000',
            'master_id' => 'nullable|string|max:64',
            'master_external_id' => 'nullable|string|max:64',
        ]);

        if (empty($validated['master_external_id']) && ! empty($validated['master_id'])) {
            $validated['master_external_id'] = $validated['master_id'];
        }

        // CRM1 не знает поля КП — не шлём лишнее
        if ($cached->connection?->type !== 'crm2_http') {
            unset($validated['prepayment'], $validated['with_bso'], $validated['with_zip']);
        }

        try {
            $updated = $this->orders->update($cached, $validated, $user);

            return response()->json(['ok' => true, 'order' => $updated, 'message' => 'Сохранено']);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function close(Request $request, int $id)
    {
        $user = $request->session()->get('desk_user');
        $cached = $this->orders->findForSession($user, $id);

        // поля с формы (на случай если refresh затрёт локальные значения)
        $fromForm = $request->validate([
            'paid_amount' => 'nullable|integer|min:0',
            'parts_amount' => 'nullable|integer|min:0',
            'prepayment' => 'nullable|integer|min:0',
            'with_bso' => 'nullable|integer|in:0,1',
            'with_zip' => 'nullable|integer|in:0,1',
            'master_external_id' => 'nullable|string|max:64',
            'master_id' => 'nullable|string|max:64',
            'comment' => 'nullable|string|max:2000',
        ]);
        if (empty($fromForm['master_external_id']) && ! empty($fromForm['master_id'])) {
            $fromForm['master_external_id'] = $fromForm['master_id'];
        }

        try {
            // для КП не делаем отдельный update перед close — пустой status на СД давал 500;
            // поля формы уходят одним запросом finish=1 внутри closeOrder
            if ($fromForm !== []) {
                $payload = $fromForm;
                if ($cached->connection?->type === 'crm2_http') {
                    if (! array_key_exists('parts_amount', $payload) || $payload['parts_amount'] === null) {
                        $payload['parts_amount'] = (int) ($cached->parts_amount ?? 0);
                    }
                    if (! array_key_exists('prepayment', $payload) || $payload['prepayment'] === null) {
                        $payload['prepayment'] = (int) ($cached->prepayment ?? 0);
                    }
                    foreach (['paid_amount', 'parts_amount', 'prepayment', 'with_bso', 'with_zip', 'master_external_id'] as $field) {
                        if (array_key_exists($field, $payload) && $payload[$field] !== null && $payload[$field] !== '') {
                            $cached->{$field} = $payload[$field];
                        }
                    }
                    $cached->save();
                } else {
                    $cached = $this->orders->update($cached, $payload, $user);
                }
            }

            $result = $this->orders->close($cached->fresh(['connection']), $user);

            return response()->json([
                'ok' => true,
                'message' => 'Заказ проведён',
                'closed_count' => DeskStat::getValue('closed_via_desk'),
                'order' => $result['order'],
                'calculation' => $result['calculation'],
            ]);
        } catch (Throwable $e) {
            \App\Models\DeskLog::write('error', $e->getMessage(), $cached->crm_id, 'close', $cached->external_id);

            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function sync(Request $request, int $crmId)
    {
        $user = $request->session()->get('desk_user');
        $connection = CrmConnection::query()->findOrFail($crmId);

        try {
            $result = $this->sync->syncConnection($connection, true, (int) $user['id']);

            return redirect()->route('desk.index')
                ->with('success', "{$connection->name}: {$result['upserted']} / −{$result['removed']}");
        } catch (Throwable $e) {
            return redirect()->route('desk.index')->with('error', $e->getMessage());
        }
    }

    public function logs(Request $request)
    {
        $user = $request->session()->get('desk_user');
        if (! in_array('developer', $user['roles'] ?? [], true)) {
            abort(403);
        }

        $logs = DeskLog::query()->with('connection')->orderByDesc('id')->paginate(50);

        return view('desk.logs', compact('logs', 'user'));
    }

    /** @return list<array{id:int|string,name:string}> */
    protected function citiesFromCache(array $user): array
    {
        $q = DeskOrderCache::query()
            ->whereNotNull('city_name')
            ->where('city_name', '!=', '')
            ->selectRaw('MAX(city_id) as id, city_name as name')
            ->groupBy('city_name')
            ->orderBy('name');

        $cityIds = $user['city_ids'] ?? null;
        if (is_array($cityIds)) {
            $q->whereIn('city_id', $cityIds);
        }

        return $q->get()->map(fn ($r) => [
            'id' => $r->name, // фильтр по отображаемому названию из КП
            'name' => $r->name,
        ])->all();
    }

    /** @return array<string, string> */
    protected function rawStatusLabels(): array
    {
        return DeskOrderCache::rawStatusLabels();
    }

    /** @return array<string, string> */
    protected function rawStatusLabelsForConnection(?string $type): array
    {
        if ($type === 'crm2_http') {
            try {
                $crm2 = CrmConnection::query()->where('type', 'crm2_http')->first();
                if ($crm2) {
                    return $this->sync->makeAdapter($crm2)->getStatuses();
                }
            } catch (Throwable) {
            }
        }

        $labels = DeskOrderCache::rawStatusLabels();
        // в активном редактировании закрывающие тоже доступны (проведение)
        return $labels;
    }
}
