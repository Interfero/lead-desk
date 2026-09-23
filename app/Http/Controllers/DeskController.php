<?php

namespace App\Http\Controllers;

use App\Models\CrmConnection;
use App\Models\DeskLog;
use App\Models\DeskOrderCache;
use App\Models\DeskStat;
use App\Services\DeskAddressOfficeRevealService;
use App\Services\DeskAutoSyncService;
use App\Services\DeskFraudService;
use App\Services\DeskOrderService;
use App\Services\DeskSyncService;
use App\Support\DeskAccess;
use App\Support\DeskAddressOffice;
use App\Support\DeskCardMasters;
use App\Support\KpHttpError;
use Illuminate\Http\Request;
use Throwable;

class DeskController extends Controller
{
    public function __construct(
        protected DeskOrderService $orders,
        protected DeskSyncService $sync,
        protected DeskFraudService $fraud,
        protected DeskAutoSyncService $autoSync
    ) {}

    public function index(Request $request)
    {
        $user = $request->session()->get('desk_user');
        if (config('desk.auto_sync_on_visit', true)
            && $request->isMethod('GET')
            && ! $request->ajax()
            && ! $request->expectsJson()
            && is_array($user)
            && ! empty($user['id'])
        ) {
            dispatch(fn () => app(DeskAutoSyncService::class)->syncCrm1IfStale())->afterResponse();
        }
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
                unset($saved['date_from'], $saved['date_to'], $saved['today'], $saved['show_closed']);
                $saved = array_filter(
                    $saved,
                    fn ($value) => $value !== null && $value !== '' && $value !== []
                );
                if ($saved !== []) {
                    return redirect()->route('desk.index', $saved);
                }
            }
        }

        $showClosed = $request->boolean('show_closed');
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

        $listColumns = [
            'id', 'crm_id', 'external_id', 'city_id', 'city_name',
            'status', 'raw_status', 'client_name', 'address', 'address_office',
            'master_name', 'order_type', 'paid_amount', 'total_amount',
            'call_at_local', 'created_at_local', 'timezone',
            'is_noncore', 'needs_feedback', 'row_highlight',
        ];
        $query = $this->orders->baseQueryForSession($user, $includeClosed)
            ->select($listColumns)
            ->with(['connection:id,name']);

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
                $query->where(function ($q) use ($request) {
                    $name = (string) $request->input('city');
                    $q->where('city_name', $name)
                        ->orWhere('city_name', 'like', $name.' /%');
                });
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

        // Города для фильтра (доступные роли) — до пагинации, чтобы сузить выборку безопасно
        $cities = $this->citiesForFilter($user);
        $availableCityIds = array_values(array_map(fn ($c) => (int) $c['id'], $cities));
        $citiesFilterActive = $request->boolean('filter_cities') || $request->has('cities');
        $selectedCityIds = array_values(array_unique(array_filter(
            array_map('intval', (array) $request->input('cities', [])),
            fn (int $id) => $id > 0 && in_array($id, $availableCityIds, true)
        )));
        // По умолчанию (filter_cities не передан) — все доступные, заявки не прячем.
        // Если filter_cities=1 — применяем явный выбор (в т.ч. пустой).
        if (! $searchById && $citiesFilterActive) {
            if ($selectedCityIds === []) {
                $query->whereRaw('0 = 1');
            } elseif (count($selectedCityIds) < count($availableCityIds)) {
                $query->whereIn('city_id', $selectedCityIds);
            }
        } else {
            $selectedCityIds = $availableCityIds;
        }
        $sortedSelected = $selectedCityIds;
        $sortedAvailable = $availableCityIds;
        sort($sortedSelected);
        sort($sortedAvailable);
        $citiesFilterAll = $sortedSelected === $sortedAvailable;
        $citiesFilterLabel = $this->citiesFilterCaption($cities, $selectedCityIds, $citiesFilterAll);

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
                    'cities' => $citiesFilterActive ? $selectedCityIds : null,
                    'filter_cities' => $citiesFilterActive ? '1' : null,
                    'city_id' => $request->input('city_id'),
                    'crm_id' => $request->input('crm_id'),
                    'master' => $request->input('master'),
                    'search_name' => $request->input('search_name'),
                    'search_address' => $request->input('search_address'),
                    'sort' => $request->input('sort'),
                    'dir' => $request->input('dir'),
                ],
                fn ($v) => $v !== null && $v !== '' && $v !== []
            );
            $request->session()->put($sessionKey, $savedFilters);
        }

        $orders = $query->paginate(50)->withQueryString();
        $canSeeFraud = DeskAccess::canSeeFraud($user ?? []);
        $fraudOrders = collect();
        $fraudCount = 0;
        if ($canSeeFraud) {
            $fraudCount = $this->orders->baseQueryForSession($user ?? [], true)
                ->where('fraud_status', DeskFraudService::NOT_OK)
                ->count();
            if ($request->input('tab') === 'fraud') {
                $fraudColumns = [
                    'id', 'crm_id', 'external_id', 'status', 'raw_status',
                    'client_name', 'phone', 'address', 'address_office',
                    'call_at_local', 'timezone', 'fraud_status', 'fraud_note', 'fraud_checked_at',
                ];
                $fraudOrders = $this->orders->baseQueryForSession($user ?? [], true)
                    ->select($fraudColumns)
                    ->with(['connection:id,name'])
                    ->whereIn('fraud_status', [DeskFraudService::OK, DeskFraudService::NOT_OK])
                    ->orderByRaw("CASE WHEN fraud_status = ? THEN 0 ELSE 1 END", [DeskFraudService::NOT_OK])
                    ->orderByDesc('fraud_checked_at')
                    ->orderByDesc('id')
                    ->limit(300)
                    ->get();
            }
        }
        $connections = CrmConnection::query()->select(['id', 'name'])->orderBy('id')->get();
        $closedCount = DeskStat::getValue('closed_via_desk');
        $statusLabels = DeskOrderCache::unifiedStatusLabels();
        $rawStatusLabels = DeskOrderCache::rawStatusLabels();
        foreach (DeskAccess::hiddenRawStatuses($user ?? []) as $hiddenCode) {
            unset($rawStatusLabels[$hiddenCode]);
        }
        $typeLabels = DeskOrderCache::orderTypeLabels();
        $displayDateFrom = $hasExplicitDateFrom ? $dateFrom : '';
        $displayDateTo = $hasExplicitDateTo ? $dateTo : '';

        $canSeeClientPhone = DeskAccess::canSeeClientPhone($user ?? []);

        return response()
            ->view('desk.index', compact(
                'orders', 'connections', 'closedCount',
                'statusLabels', 'typeLabels', 'cities', 'selectedCityIds', 'citiesFilterAll',
                'citiesFilterLabel', 'rawStatusLabels', 'user', 'canSeeFraud',
                'canSeeClientPhone',
                'fraudOrders', 'fraudCount',
                'showClosed', 'displayDateFrom', 'displayDateTo'
            ))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function show(Request $request, int $id)
    {
        $payload = $this->orderCardPayload($request, $id, $request->boolean('live'));
        if (! empty($payload['gone'])) {
            return response()->json(['error' => $payload['error'] ?? 'Недоступен', 'gone' => true], 410);
        }

        return response()->json($payload);
    }

    /**
     * Карточка из кэша (быстро) или с живым CRM/КП (?live=1).
     *
     * @return array<string, mixed>
     */
    protected function orderCardPayload(Request $request, int $id, bool $live): array
    {
        $user = $request->session()->get('desk_user');
        $cached = $this->orders->findForSession($user, $id);
        $cached->loadMissing('connection');
        $stale = false;
        $calculation = null;
        $cityId = $cached->city_id ? (int) $cached->city_id : null;
        $masters = DeskCardMasters::withCurrent(
            DeskCardMasters::get((int) $cached->crm_id, $cityId),
            $cached->master_external_id ? (string) $cached->master_external_id : null,
            $cached->master_name
        );

        if ($live) {
            try {
                $connection = $cached->connection;
                $credential = null;
                if ($connection?->type === 'crm2_http' && $cached->city_id) {
                    $credential = \App\Models\Crm2CityCredential::query()->where('city_id', $cached->city_id)->first();
                }
                $adapter = $this->sync->makeAdapter($connection, (int) $user['id'], $credential);
                // Никогда не дергаем get-address-office при открытии карточки —
                // в КП это навсегда открывает квартиру. Кв только по кнопке reveal.
                $liveOrder = $adapter->fetchOrder($cached->external_id);
                if (is_array($liveOrder)) {
                    $liveMasters = is_array($liveOrder['masters'] ?? null) ? $liveOrder['masters'] : [];
                    if ($liveMasters !== []) {
                        $masters = DeskCardMasters::merge($masters, $liveMasters);
                    }
                    if (! empty($liveOrder['calculation']) && is_array($liveOrder['calculation'])) {
                        $calculation = $liveOrder['calculation'];
                    }
                    if (empty($liveOrder['documents'])) {
                        unset($liveOrder['documents']);
                    }
                    $cached = $this->sync->applyLivePayload($cached, $liveOrder);
                } else {
                    $cached = $this->sync->refreshCachedOrder($cached, (int) $user['id']);
                }
            } catch (Throwable $e) {
                $stale = true;
                if ($cached->connection?->type !== 'crm2_http'
                    && (str_contains($e->getMessage(), 'закрыт') || str_contains($e->getMessage(), 'не найден'))) {
                    return ['error' => $e->getMessage(), 'gone' => true];
                }
            }
        }

        $cached->loadMissing('connection');
        $cityId = $cached->city_id ? (int) $cached->city_id : null;
        $masters = DeskCardMasters::withCurrent(
            $masters,
            $cached->master_external_id ? (string) $cached->master_external_id : null,
            $cached->master_name
        );
        if ($masters !== []) {
            DeskCardMasters::remember((int) $cached->crm_id, $cityId, $masters);
        }

        if (DeskAccess::canSeeFraud($user ?? []) && $cached->fraud_status === null) {
            $this->fraud->apply($cached);
        }

        if ($calculation === null && $cached->connection?->type !== 'crm2_http') {
            if ($cached->paid_amount !== null) {
                $calculation = \App\Support\DeskMasterCalc::computeFromCache($cached);
            } elseif (in_array((string) $cached->raw_status, DeskOrderCache::CLOSED_RAW, true)
                || $cached->status === 'closed') {
                $calculation = \App\Support\DeskMasterCalc::computeFromCache($cached);
            }
        }

        $payload = $this->orderShowPayload($user, $cached, $stale, $masters, $calculation);
        $payload['live_pending'] = ! $live;

        return $payload;
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
            return response()->json(['message' => KpHttpError::message($e)], 422);
        }

        // Импорт уже сходил в КП — карточку отдаём из кэша, без второго live.
        return $this->show($request, (int) $cached->id);
    }

    /**
     * @param  list<array{id?:string,name?:string}>  $masters
     * @return array<string, mixed>
     */
    protected function orderShowPayload(array $user, DeskOrderCache $cached, bool $stale, array $masters, mixed $calculation): array
    {
        $order = $cached;
        $order->loadMissing('connection');
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

        $officeText = $order->address_office;
        $tz = DeskAddressOffice::resolveTimezone($order->timezone);
        $canRevealOffice = DeskAddressOffice::canReveal($order->call_at_local, null, $tz);
        $revealAt = DeskAddressOffice::revealAt($order->call_at_local, $tz);
        $fullAddress = (string) ($order->address ?? '');
        // Копирование без квартиры (как в КП до кнопки). В кэше office уже может быть.
        $copyAddress = DeskAddressOffice::streetAddressForDisplay($fullAddress, $officeText);
        $copyText = \App\Support\DeskCopyFormatter::format($order, $copyAddress);
        if (! DeskAccess::canSeeClientPhone($user ?? [])) {
            $copyText = \App\Support\DeskClientPhonePrivacy::redactText($copyText, (string) $order->phone);
        }
        // В UI карточки — только улица/дом; кв/подъезд/этаж — кнопка «Показать квартиру»
        // (список тоже без кв: streetAddressForDisplay). В кэше address_office при этом может уже быть.
        $order->setAttribute(
            'address',
            DeskAddressOffice::streetAddressForDisplay($fullAddress, $officeText) ?: $fullAddress
        );
        // Локальное «стенное» время без Z — иначе браузер (+3) сдвигает 10:00 → 13:00
        foreach (['call_at_local', 'created_at_local', 'updated_at_local'] as $dtField) {
            $v = $order->{$dtField};
            if ($v instanceof \Carbon\CarbonInterface) {
                $order->setAttribute($dtField, $v->format('Y-m-d H:i:s'));
            }
        }
        $order->makeHidden(['address_office']);

        $statusLabels = $this->rawStatusLabelsForConnection($order->connection?->type);
        $hidden = DeskAccess::hiddenRawStatuses($user ?? []);
        foreach ($hidden as $code) {
            unset($statusLabels[$code]);
        }

        return [
            'order' => \App\Support\DeskClientPhonePrivacy::orderForClient($order, $user ?? []),
            'stale' => $stale,
            'can_see_client_phone' => DeskAccess::canSeeClientPhone($user ?? []),
            'copy_text' => $copyText,
            'settable_statuses' => array_keys($statusLabels),
            'status_labels' => $statusLabels,
            'type_labels' => DeskOrderCache::orderTypeLabels(),
            'masters' => $masters,
            'can_close' => ! empty($user['can_close']),
            'can_see_fraud' => DeskAccess::canSeeFraud($user ?? []),
            'address_office' => [
                'can_reveal' => $canRevealOffice,
                'has_cached' => filled($officeText),
                'available' => filled($officeText)
                    || filled($order->customer_external_id)
                    || DeskAddressOffice::addressLooksLikeHasOffice($fullAddress),
                'reveal_at' => $revealAt?->format('Y-m-d H:i:s'),
                'minutes_before' => DeskAddressOffice::REVEAL_MINUTES_BEFORE,
            ],
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

    public function revealAddressOffice(Request $request, int $id, DeskAddressOfficeRevealService $reveal)
    {
        $user = $request->session()->get('desk_user');
        $cached = $this->orders->findForSession($user, $id);

        $result = $reveal->reveal($cached, allowVisitStatus: false);
        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'ok' => false,
                'message' => $result['message'] ?? 'Не удалось показать квартиру',
            ], (int) ($result['http'] ?? 422));
        }

        return response()->json(['ok' => true, 'text' => $result['text']]);
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

            return response()->json([
                'ok' => true,
                'order' => \App\Support\DeskClientPhonePrivacy::orderForClient($updated, $user ?? []),
                'documents' => $updated->documents,
            ]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'message' => KpHttpError::message($e)], 422);
        }
    }

    public function deleteDocument(Request $request, int $id, string $documentId)
    {
        $user = $request->session()->get('desk_user');
        $cached = $this->orders->findForSession($user, $id);

        try {
            $updated = $this->orders->deleteDocument($cached, $documentId, $user);

            return response()->json([
                'ok' => true,
                'order' => \App\Support\DeskClientPhonePrivacy::orderForClient($updated, $user ?? []),
                'documents' => $updated->documents,
            ]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'message' => KpHttpError::message($e)], 422);
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
        $hasDesc = filled($cached->description) || filled($cached->comments);
        $pending = false;

        if ($cached->connection?->type === 'crm2_http' && ! $hasDesc) {
            $pending = true;
            $orderId = (int) $cached->id;
            $userId = (int) ($user['id'] ?? 0);
            dispatch(function () use ($orderId, $userId) {
                $order = DeskOrderCache::query()->with('connection')->find($orderId);
                if (! $order || filled($order->description) || filled($order->comments)) {
                    return;
                }
                try {
                    app(DeskSyncService::class)->refreshCachedOrder($order, $userId);
                } catch (Throwable) {
                }
            })->afterResponse();
        }

        $text = \App\Support\DeskCopyFormatter::format($cached);
        if (! DeskAccess::canSeeClientPhone($user ?? [])) {
            $text = \App\Support\DeskClientPhonePrivacy::redactText($text, (string) $cached->phone);
        }

        return response()->json([
            'ok' => true,
            'text' => $text,
            'pending' => $pending,
        ]);
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

            return response()->json([
                'ok' => true,
                'order' => \App\Support\DeskClientPhonePrivacy::orderForClient($updated, $user ?? []),
                'message' => 'Сохранено',
            ]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'message' => KpHttpError::message($e)], 422);
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

            $closedOrder = $result['order'];
            $orderOut = $closedOrder instanceof \App\Models\DeskOrderCache
                ? \App\Support\DeskClientPhonePrivacy::orderForClient($closedOrder, $user ?? [])
                : $closedOrder;

            return response()->json([
                'ok' => true,
                'message' => 'Заказ проведён',
                'closed_count' => DeskStat::getValue('closed_via_desk'),
                'order' => $orderOut,
                'calculation' => $result['calculation'],
            ]);
        } catch (Throwable $e) {
            \App\Models\DeskLog::write('error', $e->getMessage(), $cached->crm_id, 'close', $cached->external_id);

            return response()->json(['ok' => false, 'message' => KpHttpError::message($e)], 422);
        }
    }

    public function recheckFraud(Request $request)
    {
        $user = $request->session()->get('desk_user') ?? [];
        if (! DeskAccess::canSeeFraud($user)) {
            abort(403);
        }

        // Активные + недавно закрытые в доступе пользователя
        $orders = $this->orders->baseQueryForSession($user, true)
            ->orderByDesc('id')
            ->limit(1500)
            ->get();

        $result = $this->fraud->recheckMany($orders);

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'checked' => $result['checked'],
                'not_ok' => $result['not_ok'],
            ]);
        }

        return redirect()->route('desk.index', ['tab' => 'fraud'])
            ->with('success', "Фрод: проверено {$result['checked']}, подозрительных {$result['not_ok']}");
    }

    /**
     * Активные заказы для звуковых уведомлений (CRM1 + КП).
     */
    public function newOrdersNotify(Request $request)
    {
        $user = $request->session()->get('desk_user') ?? [];
        if ($user === []) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $rows = $this->orders->baseQueryForSession($user, false)
            ->with('connection')
            ->orderByDesc('id')
            ->limit(800)
            ->get();

        $orders = $rows->map(function (DeskOrderCache $row) {
            $type = (string) ($row->connection?->type ?? '');

            return [
                'id' => (int) $row->id,
                'external_id' => (string) $row->external_id,
                'crm_type' => $type,
                'crm_label' => $type === 'crm2_http' ? 'КП' : 'CRM',
                'city_id' => $row->city_id ? (int) $row->city_id : null,
                'city_name' => (string) ($row->city_name ?? ''),
                'client_name' => (string) ($row->client_name ?? ''),
            ];
        })->values()->all();

        return response()->json([
            'count' => count($orders),
            'orders' => $orders,
            'server_time' => now()->toDateTimeString(),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    public function sync(Request $request, int $crmId)
    {
        $user = $request->session()->get('desk_user');
        abort_unless(DeskAccess::canManageCrm2Settings($user ?? []), 403);
        $connection = CrmConnection::query()->findOrFail($crmId);

        try {
            // CRM1: всегда все филиалы в кэш; скоуп пользователя — только в UI списка.
            // X-Desk-User-Id на sync резал выдачу API до «наших» городов директора.
            $actingUserId = $connection->type === 'crm1_api' ? null : (int) $user['id'];
            $result = $this->sync->syncConnection($connection, true, $actingUserId);

            return redirect()->route('desk.settings')
                ->with('success', "{$connection->name}: {$result['upserted']} / −{$result['removed']}");
        } catch (Throwable $e) {
            return redirect()->route('desk.settings')->with('error', KpHttpError::message($e));
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

    /**
     * Города, доступные пользователю для фильтра.
     *
     * @param  array<string, mixed>  $user
     * @return list<array{id:int,name:string,label:string,is_satellite:bool}>
     */
    protected function citiesForFilter(array $user): array
    {
        $fromSso = \App\Support\DeskAccess::citiesForFilter($user);
        if ($fromSso !== []) {
            return $fromSso;
        }

        // Fallback: кэш заказов + креды КП (пока в сессии нет cities из SSO)
        $allowed = \App\Support\DeskAccess::cityIds($user);
        $byId = [];

        $credQ = \App\Models\Crm2CityCredential::query()->orderBy('city_name');
        if (is_array($allowed)) {
            $credQ->whereIn('city_id', $allowed === [] ? [-1] : $allowed);
        }
        foreach ($credQ->get() as $cred) {
            $id = (int) $cred->city_id;
            if ($id <= 0) {
                continue;
            }
            $name = trim((string) ($cred->city_name ?: ('#'.$id)));
            $byId[$id] = [
                'id' => $id,
                'name' => $name,
                'label' => $name,
                'is_satellite' => false,
            ];
        }

        $cacheQ = DeskOrderCache::query()
            ->whereNotNull('city_id')
            ->selectRaw('city_id, MIN(city_name) as name')
            ->groupBy('city_id');
        if (is_array($allowed)) {
            $cacheQ->whereIn('city_id', $allowed === [] ? [-1] : $allowed);
        }
        foreach ($cacheQ->get() as $row) {
            $id = (int) $row->city_id;
            if ($id <= 0 || isset($byId[$id])) {
                continue;
            }
            $raw = trim((string) ($row->name ?? ''));
            $name = $raw !== '' ? explode(' / ', $raw, 2)[0] : ('#'.$id);
            $byId[$id] = [
                'id' => $id,
                'name' => $name,
                'label' => $name,
                'is_satellite' => false,
            ];
        }

        $list = array_values($byId);
        usort($list, fn ($a, $b) => strcasecmp($a['label'], $b['label']));

        return $list;
    }

    /**
     * @param  list<array{id:int,name:string,label:string,is_satellite?:bool}>  $cities
     * @param  list<int>  $selectedIds
     */
    protected function citiesFilterCaption(array $cities, array $selectedIds, bool $allSelected): string
    {
        if ($cities === []) {
            return 'Города: нет доступных';
        }
        $labels = [];
        foreach ($cities as $c) {
            if ($allSelected || in_array((int) $c['id'], $selectedIds, true)) {
                $labels[] = $c['label'];
            }
        }
        if ($allSelected) {
            return 'Города: все доступные — '.implode(', ', $labels);
        }
        if ($labels === []) {
            return 'Города: ничего не выбрано';
        }

        return 'Города: '.implode(', ', $labels);
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
