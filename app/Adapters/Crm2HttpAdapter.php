<?php

namespace App\Adapters;

use App\Contracts\CrmAdapter;
use App\Models\Crm2CityCredential;
use App\Models\CrmConnection;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * HTTP-адаптер kp-lead-centre (Yii2 admin).
 * Учётные данные — на филиал (Crm2CityCredential).
 *
 * Чеклист записи в КП:
 * - статус 8 = «В работе», 9 = «В работе СД», 128 = «Готов»;
 * - CustomerRequest[status] никогда не пустой (на СД select часто нет — брать из h1);
 * - проведение = POST update?id=&finish=1 (не save_close);
 * - work_in_sd_ready_at: d-m-Y (только если поле есть в форме); предоплату на save — только явно;
 * - 8→9 КП может отказать: нет сохранной расписки / лимит СД у мастера — читать flash alert.
 */
class Crm2HttpAdapter implements CrmAdapter
{
    public const HISTORY_STATUS_IDS = [1, 4, 8, 9, 32, 1001, 1002, 1003, 64, 128, 1020, 20, 3, 48];

    protected ?CookieJar $cookies = null;

    public function __construct(
        protected CrmConnection $connection,
        protected ?Crm2CityCredential $credential = null,
    ) {}

    public function authenticate(): void
    {
        if (! $this->credential?->hasCredentials()) {
            throw new RuntimeException('Не заданы логин/пароль kp-lead-centre для филиала');
        }

        // Переиспользуем cookie-сессию ~10 мин, чтобы не логиниться на каждое фото
        $cacheKey = 'kp_jar_'.$this->credential->id;
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && $cached !== []) {
            $this->cookies = new CookieJar(false, $cached);

            return; // без probe /admin — иначе каждое фото ждёт ещё одну тяжёлую страницу
        }

        $this->cookies = new CookieJar();
        $loginHtml = $this->client()->get($this->url('/admin/login'));
        if (! $loginHtml->successful()) {
            throw new RuntimeException('Не удалось открыть страницу входа kp (HTTP '.$loginHtml->status().')');
        }

        [$csrfParam, $csrfToken] = $this->extractCsrfPair($loginHtml->body());
        $payload = [
            'LoginForm[email]' => $this->credential->login,
            'LoginForm[password]' => $this->credential->password,
            'LoginForm[rememberMe]' => '1',
        ];
        if ($csrfParam && $csrfToken) {
            $payload[$csrfParam] = $csrfToken;
        }

        $res = $this->client()
            ->asForm()
            ->post($this->url('/admin/login'), $payload);

        $body = $res->body();
        $effective = (string) ($res->effectiveUri()?->__toString() ?? '');
        $stillOnLogin = str_contains($effective, '/admin/login')
            || str_contains($body, 'id="login-form"')
            || str_contains($body, 'LoginForm[email]')
            || str_contains($body, 'page-is-login');

        if ($res->status() >= 400 && $stillOnLogin) {
            throw new RuntimeException('Ошибка входа kp (HTTP '.$res->status().')');
        }
        if ($stillOnLogin) {
            $hint = str_contains(mb_strtolower($body), 'неверн') ? 'Неверный логин или пароль' : 'Не удалось войти в kp-lead-centre';
            throw new RuntimeException($hint);
        }

        try {
            Cache::put($cacheKey, $this->cookies->toArray(), now()->addMinutes(10));
        } catch (\Throwable) {
            // cookie cache optional
        }
    }

    public function fetchOrders(array $filters = []): array
    {
        return $this->withHistoryCity($this->parseOrdersHtml($this->getOrdersListHtml()));
    }

    /**
     * @return array{orders:list<array<string, mixed>>,next_page:?int}
     */
    public function fetchHistoryPage(\DateTimeInterface $from, \DateTimeInterface $to, int $page): array
    {
        if ($page < 1) {
            throw new RuntimeException('Некорректная страница истории KP.');
        }

        $query = http_build_query([
            'CustomerRequestSearch' => [
                'dates' => $from->format('d.m.Y').' 00:00 - '.$to->format('d.m.Y').' 23:59',
                'statuses' => self::HISTORY_STATUS_IDS,
            ],
            'page' => $page,
            'per-page' => 50,
        ]);
        $body = $this->getOrdersListHtml($query);

        return [
            'orders' => $this->withHistoryCity($this->parseOrdersHtml($body)),
            'next_page' => $this->nextHistoryPage($body, $page),
        ];
    }

    protected function getOrdersListHtml(string $query = ''): string
    {
        $this->authenticate();

        $path = (string) data_get($this->connection->config, 'orders_path', '/admin/domain/customer-request/index');
        $url = $this->url($path).($query === '' ? '' : (str_contains($path, '?') ? '&' : '?').$query);
        $html = $this->client()->get($url);
        if (! $html->successful()) {
            throw new RuntimeException('Не удалось загрузить список заказов kp (HTTP '.$html->status().')');
        }
        $body = $html->body();
        if (str_contains($body, 'id="login-form"') || str_contains($body, 'page-is-login')) {
            throw new RuntimeException('Сессия kp не установлена — повторный вход не удался');
        }

        return $body;
    }

    /**
     * @param  list<array<string, mixed>>  $orders
     * @return list<array<string, mixed>>
     */
    protected function withHistoryCity(array $orders): array
    {
        $cityId = $this->credential?->city_id;
        $fallbackCityName = $this->credential?->city_name;

        return array_map(function (array $row) use ($cityId, $fallbackCityName) {
            $row['city_id'] = $cityId;
            $row['source'] = 'KP-LEAD';
            // город из колонки КП («Коломна (Рязань) / КП …»), иначе имя филиала из учётки
            $fromKp = trim((string) ($row['city_name'] ?? ''));
            if ($fromKp === '' || mb_strtolower($fromKp) === 'выбрать город') {
                $row['city_name'] = $fallbackCityName;
            }

            return $row;
        }, $orders);
    }

    protected function nextHistoryPage(string $html, int $currentPage): ?int
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);
        $links = $xpath->query(
            '//ul[contains(concat(" ", normalize-space(@class), " "), " pagination ")]'
            .'//li[contains(concat(" ", normalize-space(@class), " "), " next ")'
            .' and not(contains(concat(" ", normalize-space(@class), " "), " disabled "))]//a[@data-page]'
        );
        if (! $links || $links->length === 0) {
            return null;
        }

        $rawPage = trim($links->item(0)?->getAttribute('data-page') ?? '');
        if ($rawPage === '' || ! ctype_digit($rawPage)) {
            throw new RuntimeException('Некорректная следующая страница истории KP.');
        }

        $nextPage = (int) $rawPage + 1;
        if ($nextPage <= $currentPage) {
            throw new RuntimeException('Некорректная следующая страница истории KP.');
        }

        return $nextPage;
    }

    /**
     * Карточка заказа КП.
     *
     * get-address-office в КП = кнопка «Показать квартиру»: вызов навсегда открывает кв в КП.
     * По умолчанию office НЕ запрашиваем. Явно: options['fetch_office']=true
     * (только кнопка Desk после окна 30 минут).
     *
     * @param  array{fetch_office?:bool,skip_office?:bool}  $options
     */
    public function fetchOrder(string $externalId, array $options = []): ?array
    {
        $this->authenticate();
        $pathTpl = (string) data_get($this->connection->config, 'order_path', '/admin/domain/customer-request/update?id={id}');
        $path = str_replace('{id}', urlencode($externalId), $pathTpl);
        $html = $this->client()->get($this->url($path));
        if ($html->status() === 404) {
            return null;
        }
        if (! $html->successful()) {
            throw new RuntimeException('Не удалось загрузить заказ kp #'.$externalId);
        }
        $body = $html->body();
        if (str_contains($body, 'id="login-form"') || str_contains($body, 'page-is-login')) {
            throw new RuntimeException('Сессия kp не установлена');
        }

        $detail = $this->parseOrderDetailHtml($body, $externalId);
        $detail['city_id'] = $this->credential?->city_id;
        // Город с карточки: select филиала часто без НП («Палкино»), а locality уже в __tCustomerAddress.
        // Не затираем list-city пустым — оставляем city_name, если парсер вытащил отображаемое место.
        if (($detail['city_name'] ?? null) === null || trim((string) $detail['city_name']) === '') {
            unset($detail['city_name']);
        }
        // при открытии карточки сразу кэшируем URL загрузки — фото не ждут ещё один GET ~1MB
        $this->warmKpUploadMetaFromHtml($externalId, $body);

        // По умолчанию НЕ вызываем get-address-office (это unlock кв в КП).
        $fetchOffice = ! empty($options['fetch_office']) && empty($options['skip_office']);
        $customerId = $detail['customer_external_id'] ?? null;
        if ($fetchOffice && is_string($customerId) && $customerId !== '') {
            $office = $this->fetchCustomerAddressOffice($customerId, $externalId);
            if ($office !== null) {
                $detail['address_office'] = $office;
            }
        }

        return $detail;
    }

    /**
     * Кв/офис + подъезд/этаж/домофон.
     * Эндпоинт КП = «Показать квартиру»: после вызова кв остаётся открытой в КП.
     * Только из Desk reveal после canReveal (за 30 мин до визита).
     */
    public function fetchCustomerAddressOffice(string $customerId, ?string $refererExternalId = null): ?string
    {
        $this->authenticate();
        $referer = $refererExternalId
            ? $this->url('/admin/domain/customer-request/update?id='.urlencode($refererExternalId))
            : $this->url('/admin/domain/customer-request/index');

        $res = $this->client()
            ->withHeaders([
                'Referer' => $referer,
                'X-Requested-With' => 'XMLHttpRequest',
                'Accept' => 'application/json, text/javascript, */*; q=0.01',
            ])
            ->get($this->url('/admin/domain/customer/get-address-office'), [
                'cId' => $customerId,
                '_serverKey' => '',
            ]);

        if (! $res->successful()) {
            return null;
        }

        $json = $res->json();
        if (! is_array($json)) {
            return null;
        }

        $text = trim((string) ($json['text'] ?? ''));
        if ($text === '') {
            // запас: вытащить текст из html
            $html = (string) ($json['html'] ?? '');
            if ($html !== '') {
                $text = trim(html_entity_decode(strip_tags(str_replace('</span>', ', ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
                $text = trim($text, " \t\n\r\0\x0B,");
            }
        }

        return $text !== '' ? $text : null;
    }

    /**
     * Карточка kp (/update?id=) — скрытые __t* поля + comments.
     *
     * @return array<string, mixed>
     */
    public function parseOrderDetailHtml(string $html, string $externalId): array
    {
        $get = function (string $id) use ($html): ?string {
            if (preg_match('/id="'.preg_quote($id, '/').'"[^>]*value="([^"]*)"/i', $html, $m)) {
                return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }

            return null;
        };

        $comments = $get('__tComments');
        if ($comments === null || $comments === '') {
            if (preg_match('/name="CustomerRequest\[comments\]"[^>]*>(.*?)<\/textarea>/is', $html, $m)) {
                $comments = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }

        $typeTitle = $get('__tTypeTitle');
        $orderType = match (true) {
            $typeTitle && str_contains(mb_strtolower($typeTitle), 'повтор') => 'repeat',
            $typeTitle && str_contains(mb_strtolower($typeTitle), 'гарант') => 'warranty',
            default => 'first',
        };

        $address = $get('__tCustomerAddress');
        $name = $get('__tCustomerFullName');
        $age = $get('__tCustomerAge');
        $opened = $get('__tOpenedAt');
        $isNoncore = ($get('__tIsNoncore') ?? '0') === '1';
        [$statusCode, $statusLabel] = $this->parseKpStatusFromHtml($html);

        $customerExternalId = null;
        if (preg_match('/id="customerrequest-customer_id"[^>]*value="(\d+)"/i', $html, $cm)
            || preg_match('/name="CustomerRequest\[customer_id\]"[^>]*value="(\d+)"/i', $html, $cm)
            || preg_match('/\/admin\/domain\/customer\/update\?id=(\d+)/', $html, $cm)) {
            $customerExternalId = $cm[1];
        }

        $paid = null;
        if (preg_match('/name="CustomerRequest\[payed_by_customer\]"[^>]*value="([^"]*)"/i', $html, $pm)) {
            $paid = $pm[1] !== '' ? (int) $pm[1] : null;
        }
        $parts = null;
        if (preg_match('/name="CustomerRequest\[spares_cost\]"[^>]*value="([^"]*)"/i', $html, $spm)) {
            $parts = $spm[1] !== '' ? (int) $spm[1] : null;
        }
        $prepayment = null;
        if (preg_match('/name="CustomerRequest\[prepayment\]"[^>]*value="([^"]*)"/i', $html, $ppm)) {
            $prepayment = $ppm[1] !== '' ? (int) $ppm[1] : 0;
        }
        $withBso = $this->parseSelectSelectedInt($html, 'CustomerRequest[with_bso]');
        $withZip = $this->parseSelectSelectedInt($html, 'CustomerRequest[with_zip]');

        $phone = null;
        if (preg_match('/id="customerInfo".{0,2500}/is', $html, $block)) {
            if (preg_match('/(?:тел(?:ефон)?|phone)\s*:?\s*([0-9+\-\s()]{10,20})/iu', $block[0], $phm)) {
                $phone = preg_replace('/\D+/', '', $phm[1]) ?: null;
            }
        }

        $cityName = null;
        // город с select на карточке (если есть)
        if (preg_match('/name="CustomerRequest\[city_id\]"[^>]*>(.*?)<\/select>/is', $html, $cm)) {
            if (preg_match('/<option[^>]*selected[^>]*>([^<]+)/i', $cm[1], $om)
                || preg_match('/<option[^>]*value="[^"]+"[^>]*selected[^>]*>([^<]+)/i', $cm[1], $om)) {
                $label = trim(html_entity_decode($om[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($label !== '' && ! str_contains(mb_strtolower($label), 'выбер')) {
                    $cityName = $label;
                }
            }
        }

        // НП из адреса: «Псков (рабочий посёлок Палкино) Рабочая улица, 3…»
        // Select филиала часто только «Псков» — для GM нужен полный cityName.
        $split = $this->splitKpCustomerAddress($address);
        if ($split['locality'] !== null) {
            $cityName = $split['locality'];
            $address = $split['street'];
        }

        $raw = $statusCode !== null && $statusCode !== ''
            ? ($this->kpStatusIdToCode((int) $statusCode) ?? ($statusLabel ? $this->normalizeStatus($statusLabel) : 'in_progress'))
            : ($statusLabel ? $this->normalizeStatus($statusLabel) : 'in_progress');

        $masters = [];
        $masterId = null;
        $masterName = null;
        if (preg_match('/name="CustomerRequest\[employee_id\]"[^>]*>(.*?)<\/select>/is', $html, $em)) {
            if (preg_match_all('/<option[^>]*value="([^"]*)"[^>]*>([^<]*)</i', $em[1], $opts, PREG_SET_ORDER)) {
                foreach ($opts as $o) {
                    $oid = trim($o[1]);
                    $oname = trim(html_entity_decode($o[2]));
                    if ($oid === '' || $oname === '' || str_contains(mb_strtolower($oname), 'выберите')) {
                        continue;
                    }
                    $masters[] = ['id' => $oid, 'name' => $oname];
                    if (str_contains($o[0], 'selected')) {
                        $masterId = $oid;
                        $masterName = preg_replace('/\s*\(\d+\)\s*$/', '', $oname) ?: $oname;
                    }
                }
            }
            if ($masterId === null && preg_match('/<option[^>]*selected[^>]*value="([^"]+)"/i', $em[1], $sel)) {
                $masterId = $sel[1];
            }
        }

        // Документы из krajee initialPreviewConfig (не весь HTML-мусор)
        $documents = $this->parseKpDocuments($html);
        $clientHistory = $this->parseKpClientHistory($html, $externalId);
        $needsFeedback = $this->statusTextNeedsFeedback((string) ($statusLabel ?? ''));
        $rk = $this->parseKpRkFromHtml($html);

        return [
            'external_id' => $externalId,
            'source' => 'KP-LEAD',
            'raw_status' => $raw,
            'client_name' => $name ?: null,
            'client_age' => $age ?: null,
            'customer_external_id' => $customerExternalId,
            'phone' => $phone,
            'address' => $address ?: null,
            'city_name' => $cityName,
            'description' => ($comments !== null && $comments !== '') ? $comments : null,
            'comments' => ($comments !== null && $comments !== '') ? $comments : null,
            'paid_amount' => $paid,
            'parts_amount' => $parts,
            'prepayment' => $prepayment,
            'with_bso' => $withBso,
            'with_zip' => $withZip,
            'total_amount' => $paid !== null ? max(0, $paid - (int) ($parts ?? 0)) : null,
            'master_external_id' => $masterId,
            'master_id' => $masterId,
            'master_name' => $masterName,
            'masters' => $masters,
            'documents' => $documents,
            'client_history' => $clientHistory,
            'order_type' => $orderType,
            'is_noncore' => $isNoncore,
            'rk' => $rk,
            // карточка КП часто без «(Отз)» в h1 — флаг с списка не затираем false'ом
            'needs_feedback' => $needsFeedback ? true : null,
            'is_closed' => in_array($raw, ['completed', 'cancelled_cc', 'cancelled_city', 'rejected'], true),
            'call_at_local' => $this->parseDate($opened) ?? $this->parseOpenedAtAlt($opened),
            // КП отдаёт локальное wall-clock; app.timezone на desk = UTC
            'timezone' => 'Europe/Moscow',
            'type_title' => $typeTitle,
            'opened_at_raw' => $opened,
            'calculation' => $this->parseKpConductCalc($html, $externalId),
            'hash' => sha1($externalId.'|'.md5(($comments ?? '').'|'.($address ?? '').'|'.($name ?? '').'|'.$raw.'|'.$paid.'|'.$parts.'|'.$masterId)),
        ];
    }

    /** Пометка «(Отз)» в статусе списка КП. */
    protected function statusTextNeedsFeedback(string $status): bool
    {
        return (bool) preg_match('/\(\s*отз\.?\s*\)/iu', $status);
    }

    /** Рекламный источник с карточки КП (скрытые __t* или select). */
    protected function parseKpRkFromHtml(string $html): ?string
    {
        foreach (['__tSourceName', '__tAdvertisingName', '__tAdvertisingTitle', '__tPromoName', '__tRk'] as $id) {
            if (preg_match('/id="'.preg_quote($id, '/').'"[^>]*value="([^"]*)"/i', $html, $m)) {
                $text = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($text !== '') {
                    return $text;
                }
            }
        }

        foreach ([
            'CustomerRequest[advertising_campaign_id]',
            'CustomerRequest[advertising_id]',
            'CustomerRequest[source_id]',
            'CustomerRequest[promo_id]',
        ] as $name) {
            $q = preg_quote($name, '/');
            if (! preg_match('/name="'.$q.'"[^>]*>(.*?)<\/select>/is', $html, $sm)) {
                continue;
            }
            if (preg_match('/<option[^>]*selected[^>]*>([^<]+)/i', $sm[1], $om)
                || preg_match('/<option[^>]*value="[^"]+"[^>]*selected[^>]*>([^<]+)/i', $sm[1], $om)) {
                $label = trim(html_entity_decode($om[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($label !== '' && ! str_contains(mb_strtolower($label), 'выбер')) {
                    return $label;
                }
            }
        }

        return null;
    }

    /**
     * История заказов клиента с карточки КП (#customer-requests-grid).
     *
     * @return list<array<string, mixed>>
     */
    protected function parseKpClientHistory(string $html, ?string $currentExternalId = null): array
    {
        if (! str_contains($html, 'customer-requests-grid')) {
            return [];
        }

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $wrapped = '<?xml encoding="UTF-8"><div>'.$html.'</div>';
        if (! @$dom->loadHTML($wrapped, LIBXML_NOERROR | LIBXML_NOWARNING)) {
            return [];
        }
        $xpath = new \DOMXPath($dom);
        $grid = $xpath->query('//*[@id="customer-requests-grid"]')->item(0);
        if (! $grid) {
            return [];
        }

        $table = $xpath->query('.//table[contains(@class,"table")]', $grid)->item(0);
        if (! $table) {
            return [];
        }

        $trs = $xpath->query('./tbody/tr', $table);
        if (! $trs || $trs->length === 0) {
            $trs = $xpath->query('./tr', $table);
        }
        if (! $trs) {
            return [];
        }

        $out = [];
        foreach ($trs as $tr) {
            /** @var \DOMElement $tr */
            $id = $tr->getAttribute('data-key');
            if ($id === '' || ! preg_match('/^\d{5,}$/', $id)) {
                $firstTd = $xpath->query('./td', $tr)->item(0);
                $id = $firstTd ? trim(preg_replace('/\s+/u', ' ', $firstTd->textContent ?? '')) : '';
                if (! preg_match('/^\d{5,}$/', $id)) {
                    continue;
                }
            }

            $tds = $xpath->query('./td', $tr);
            if (! $tds || $tds->length < 8) {
                continue;
            }
            $cells = [];
            foreach ($tds as $td) {
                $cells[] = trim(preg_replace('/\s+/u', ' ', $td->textContent ?? ''));
            }

            $out[] = [
                'external_id' => $id,
                'is_current' => $currentExternalId !== null && (string) $currentExternalId === (string) $id,
                'order_type' => $cells[1] ?? '',
                'callback' => $cells[2] ?? '',
                'accepted_at' => $cells[3] ?? '',
                'closed_at' => $cells[4] ?? '',
                'status' => $cells[5] ?? '',
                'master' => $cells[6] ?? '',
                'amount' => $cells[7] ?? '',
                'creator' => $cells[8] ?? '',
                'rk' => $cells[9] ?? '',
            ];
        }

        return $out;
    }

    /**
     * @return list<array{id:string,name:string,category:string,url?:string}>
     */
    protected function parseKpDocuments(string $html): array
    {
        $targetToCategory = [
            'images_main' => 'contract',
            'images_check' => 'receipts',
            'images_spare' => 'parts_photos',
            'images_safetyreceipt' => 'storage_receipt',
        ];
        $docs = [];
        $base = rtrim((string) ($this->connection->base_url ?: config('desk.crm2_base_url')), '/');
        $seen = [];

        // Каждый window.fileinput_* = {...}; — отдельный блок со своим target
        $offset = 0;
        $len = strlen($html);
        while (preg_match('/window\.fileinput_\w+\s*=\s*\{/', $html, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $braceStart = $m[0][1] + strlen($m[0][0]) - 1;
            $depth = 0;
            $end = null;
            for ($i = $braceStart; $i < $len; $i++) {
                $ch = $html[$i];
                if ($ch === '{') {
                    $depth++;
                } elseif ($ch === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $end = $i;
                        break;
                    }
                }
            }
            if ($end === null) {
                break;
            }
            $block = substr($html, $braceStart, $end - $braceStart + 1);
            $offset = $end + 1;

            if (! preg_match('/"uploadUrl":"[^"]*target=([a-z_]+)[^"]*"/', $block, $um)) {
                continue;
            }
            $category = $targetToCategory[$um[1]] ?? null;
            if (! $category) {
                continue;
            }
            if (! preg_match_all('/\{"type":"image","url":"[^"]+","key":(\d+),"downloadUrl":"([^"]+)"\}/', $block, $rows, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($rows as $row) {
                $key = (string) $row[1];
                $uniq = $category.'|'.$key;
                if (isset($seen[$uniq])) {
                    continue;
                }
                $seen[$uniq] = true;
                $dl = (string) (json_decode('"'.$row[2].'"') ?: str_replace('\/', '/', $row[2]));
                $url = str_starts_with($dl, 'http') ? $dl : $base.$dl;
                $docs[] = [
                    'id' => $key,
                    'name' => basename(parse_url($dl, PHP_URL_PATH) ?: $dl) ?: ('photo-'.$key.'.jpg'),
                    'category' => $category,
                    'url' => $url,
                    'mime' => 'image/jpeg',
                ];
            }
        }

        return $docs;
    }

    public function updateOrder(string $externalId, array $data): void
    {
        $this->authenticate();
        $html = $this->fetchUpdateHtml($externalId);
        $payload = $this->extractCustomerRequestForm($html);

        if (! empty($data['raw_status'])) {
            $kpId = $this->statusCodeToKpId((string) $data['raw_status']);
            if ($kpId === null) {
                throw new RuntimeException('Неизвестный статус для kp: '.$data['raw_status']);
            }
            [$prevKpId] = $this->parseKpStatusFromHtml($html);
            $prevStatus = (string) ($payload['CustomerRequest[status]'] ?? $prevKpId ?? '');
            $payload['CustomerRequest[status]'] = (string) $kpId;
            if ($prevStatus !== (string) $kpId) {
                $payload['CustomerRequest[cr_form_extra][employee_change_work_status]'] = '1';
            }
            if ((int) $kpId === 9) {
                // дату СД добавляем только если поле уже есть в форме (иначе КП его игнорит/ругается)
                if (array_key_exists('CustomerRequest[work_in_sd_ready_at]', $payload)) {
                    $this->ensureWorkInSdReadyAt($payload);
                }
            }
        }

        $masterExt = $data['master_external_id'] ?? $data['master_id'] ?? null;
        if ($masterExt !== null && $masterExt !== '') {
            $payload['CustomerRequest[employee_id]'] = (string) $masterExt;
        }

        if (array_key_exists('paid_amount', $data) && $data['paid_amount'] !== null) {
            $payload['CustomerRequest[payed_by_customer]'] = (string) max(0, (int) $data['paid_amount']);
        }
        if (array_key_exists('parts_amount', $data) && $data['parts_amount'] !== null) {
            $payload['CustomerRequest[spares_cost]'] = (string) max(0, (int) $data['parts_amount']);
        }
        // предоплату пишем только если явно передали (null = не трогать поле КП)
        if (array_key_exists('prepayment', $data) && $data['prepayment'] !== null && $data['prepayment'] !== '') {
            $payload['CustomerRequest[prepayment]'] = (string) max(0, (int) $data['prepayment']);
        }
        if (array_key_exists('with_bso', $data) && $data['with_bso'] !== null && $data['with_bso'] !== '') {
            $payload['CustomerRequest[with_bso]'] = (string) (int) $data['with_bso'];
        }
        if (array_key_exists('with_zip', $data) && $data['with_zip'] !== null && $data['with_zip'] !== '') {
            $payload['CustomerRequest[with_zip]'] = (string) (int) $data['with_zip'];
        }
        // Отписки Desk/гильдии — только в «Комментарий филиала», основное «Комментарий» не трогаем.
        // Без префикса «[дата desk]»; если он уже пришёл во входе — срезаем.
        if (! empty($data['comment'])) {
            $line = trim((string) $data['comment']);
            $line = preg_replace('/^\[\d{2}\.\d{2}\.\d{4}\s+\d{2}:\d{2}\s+desk\]\s*/u', '', $line) ?? $line;
            $line = trim($line);
            if ($line !== '') {
                $prev = trim((string) ($payload['CustomerRequest[recommendation_comment]'] ?? ''));
                $payload['CustomerRequest[recommendation_comment]'] = $prev !== '' ? $prev."\n".$line : $line;
            }
        }

        // На «В работе СД» у КП часто нет <select status> — пустой status даёт HTTP 500
        $this->ensurePayloadHasKpStatus($payload, $html);

        $expectRaw = ! empty($data['raw_status']) ? (string) $data['raw_status'] : null;
        $responseHtml = $this->postCustomerRequestForm($externalId, $payload, $html);

        if ($expectRaw !== null) {
            $check = $this->fetchUpdateHtml($externalId);
            [$code, $label] = $this->parseKpStatusFromHtml($check);
            $got = $code !== null && $code !== ''
                ? ($this->kpStatusIdToCode((int) $code) ?? $this->normalizeStatus((string) ($label ?? '')))
                : ($label ? $this->normalizeStatus($label) : null);
            if ($got !== null && $got !== $expectRaw) {
                $hint = $this->extractKpAlertText($responseHtml)
                    ?? $this->extractKpAlertText($check);
                throw new RuntimeException(
                    $hint
                        ? ('КП: '.$hint)
                        : (
                            'КП не сменил статус на «'.($this->getStatuses()[$expectRaw] ?? $expectRaw).'»'
                            .' (сейчас: '.($label ?: $got).'). Проверьте карточку в КП.'
                        )
                );
            }
        }
    }

    public function closeOrder(string $externalId, array $documents = []): ?array
    {
        $this->authenticate();
        $html = $this->fetchUpdateHtml($externalId);
        $payload = $this->extractCustomerRequestForm($html);

        // финальные поля закрытия (если передали с desk)
        if (array_key_exists('paid_amount', $documents) && $documents['paid_amount'] !== null) {
            $payload['CustomerRequest[payed_by_customer]'] = (string) max(0, (int) $documents['paid_amount']);
        }
        if (array_key_exists('parts_amount', $documents) && $documents['parts_amount'] !== null) {
            $payload['CustomerRequest[spares_cost]'] = (string) max(0, (int) $documents['parts_amount']);
        }
        if (array_key_exists('prepayment', $documents) && $documents['prepayment'] !== null && $documents['prepayment'] !== '') {
            $payload['CustomerRequest[prepayment]'] = (string) max(0, (int) $documents['prepayment']);
        }
        if (array_key_exists('with_bso', $documents) && $documents['with_bso'] !== null && $documents['with_bso'] !== '') {
            $payload['CustomerRequest[with_bso]'] = (string) (int) $documents['with_bso'];
        }
        if (array_key_exists('with_zip', $documents) && $documents['with_zip'] !== null && $documents['with_zip'] !== '') {
            $payload['CustomerRequest[with_zip]'] = (string) (int) $documents['with_zip'];
        }
        $masterExt = $documents['master_external_id'] ?? $documents['master_id'] ?? null;
        if ($masterExt !== null && $masterExt !== '') {
            $payload['CustomerRequest[employee_id]'] = (string) $masterExt;
        }

        // уже «Готов» в КП — идемпотентно, вернём расчётку с карточки
        if ($this->kpHtmlLooksCompleted($html)) {
            return $this->parseKpConductCalc($html, $externalId);
        }

        $paid = (int) ($payload['CustomerRequest[payed_by_customer]'] ?? 0);
        if ($paid <= 0 && empty($documents['skip_paid_check'])) {
            throw new RuntimeException('Для проведения в kp укажите «Оплачено клиентом» (сумма > 0)');
        }

        if (empty($payload['CustomerRequest[employee_id]']) && empty($documents['skip_master_check'])) {
            throw new RuntimeException('Для проведения в kp назначьте мастера');
        }

        // «Провести заявку» в КП = POST …/update?id=…&finish=1 (не save_close)
        if ($payload['CustomerRequest[spares_cost]'] === '' || $payload['CustomerRequest[spares_cost]'] === null) {
            $payload['CustomerRequest[spares_cost]'] = '0';
        }
        if ($payload['CustomerRequest[prepayment]'] === '' || $payload['CustomerRequest[prepayment]'] === null) {
            $payload['CustomerRequest[prepayment]'] = '0';
        }
        $this->ensureWorkInSdReadyAt($payload);
        // «Чек чистыми» — типичный режим при закрытии с чистой суммой
        if (($payload['CustomerRequest[receipt_mode]'] ?? '') === '' || ($payload['CustomerRequest[receipt_mode]'] ?? '') === '0') {
            $payload['CustomerRequest[receipt_mode]'] = '10';
        }
        unset($payload['save_close'], $payload['CustomerRequest[cr_form_extra][employee_change_work_status]']);
        // finish сам ставит «Готов», но пустой status на update-префиксе даёт 500 — оставляем текущий из h1
        $this->ensurePayloadHasKpStatus($payload, $html);

        $this->postCustomerRequestForm($externalId, $payload, $html, expectClosed: true, finish: true);

        return $this->parseKpConductCalc($this->fetchUpdateHtml($externalId), $externalId);
    }

    public function getStatuses(): array
    {
        return [
            'pending' => 'Ожидание',
            'callback' => 'На уточнении',
            'not_processed' => 'Не оформлена',
            'on_way' => 'В пути',
            'in_progress' => 'В работе',
            'in_progress_sd' => 'В работе СД',
            'completed' => 'Готов',
            'cancelled_cc' => 'Отмена КЦ',
            'cancelled_city' => 'Отмена Филиала',
            'rejected' => 'Отказ',
        ];
    }

    /**
     * Список сотрудников КП с ролью «Мастер» (/admin/user/index?UserSearch[role_type]=employee).
     *
     * @return list<array{
     *   kp_employee_id:int,
     *   name:string,
     *   email:?string,
     *   passport:?string,
     *   role:string,
     *   city_name:?string,
     *   status:string,
     *   is_active:bool
     * }>
     */
    public function fetchMasters(): array
    {
        $this->authenticate();

        $all = [];
        $page = 1;
        $maxPages = 50;
        $seenIds = [];

        while ($page <= $maxPages) {
            $query = [
                'UserSearch[role_type]' => 'employee',
                'page' => $page,
            ];
            $res = $this->client()->get($this->url('/admin/user/index?'.http_build_query($query)));
            if (! $res->successful()) {
                throw new RuntimeException('Не удалось загрузить список сотрудников kp (HTTP '.$res->status().')');
            }
            $body = $res->body();
            if (str_contains($body, 'id="login-form"') || str_contains($body, 'page-is-login')) {
                throw new RuntimeException('Сессия kp не установлена при загрузке сотрудников');
            }

            $chunk = $this->parseUsersHtml($body);
            if ($chunk === []) {
                break;
            }

            $newOnPage = 0;
            foreach ($chunk as $row) {
                $id = (int) ($row['kp_employee_id'] ?? 0);
                if ($id <= 0 || isset($seenIds[$id])) {
                    continue;
                }
                $seenIds[$id] = true;
                $all[] = $row;
                $newOnPage++;
            }

            $total = $this->parseUsersTotal($body);
            if ($total !== null && count($all) >= $total) {
                break;
            }
            if ($newOnPage === 0) {
                break;
            }
            // если нет пагинации / одна страница
            if ($total === null && $page === 1 && ! preg_match('/[?&]page=2\b/', $body)) {
                break;
            }
            $page++;
        }

        return $all;
    }

    /**
     * @return list<array{
     *   kp_employee_id:int,
     *   name:string,
     *   email:?string,
     *   passport:?string,
     *   role:string,
     *   city_name:?string,
     *   status:string,
     *   is_active:bool
     * }>
     */
    protected function parseUsersHtml(string $html): array
    {
        $prev = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $xpath = new \DOMXPath($dom);
        $rows = $xpath->query('//table//tr');
        if (! $rows) {
            return [];
        }

        $out = [];
        foreach ($rows as $i => $tr) {
            if ($i === 0) {
                continue;
            }
            $tds = $xpath->query('./td', $tr);
            if (! $tds || $tds->length < 9) {
                continue;
            }
            $cells = [];
            foreach ($tds as $td) {
                $cells[] = trim(preg_replace('/\s+/u', ' ', $td->textContent) ?? '');
            }
            $id = $cells[0] ?? '';
            if (! preg_match('/^\d+$/', $id)) {
                continue;
            }
            $role = $cells[8] ?? '';
            // role_type=employee обычно уже мастера, но на всякий случай
            if ($role !== '' && ! str_contains(mb_strtolower($role), 'мастер')) {
                continue;
            }
            $status = $cells[10] ?? '';
            $out[] = [
                'kp_employee_id' => (int) $id,
                'email' => ($cells[5] ?? '') !== '' ? $cells[5] : null,
                'name' => $cells[6] ?? '',
                'passport' => ($cells[7] ?? '') !== '' ? $cells[7] : null,
                'role' => $role,
                'city_name' => ($cells[9] ?? '') !== '' ? $cells[9] : null,
                'status' => $status,
                'is_active' => str_contains(mb_strtolower($status), 'актив'),
            ];
        }

        return $out;
    }

    protected function parseUsersTotal(string $html): ?int
    {
        if (preg_match('/Показаны записи\s*<b>\d+-\d+<\/b>\s*из\s*<b>(\d+)<\/b>/u', $html, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/из\s*<b>(\d+)<\/b>/u', $html, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    public function uploadDocument(string $externalId, string $category, \Illuminate\Http\UploadedFile $file): array
    {
        $this->authenticate();
        $target = $this->kpDocumentTarget($category);
        $field = $this->kpDocumentField($category);
        if (! $target || ! $field) {
            throw new RuntimeException('Неизвестная категория документа: '.$category);
        }

        $contents = file_get_contents($file->getRealPath());
        if ($contents === false) {
            throw new RuntimeException('Не удалось прочитать файл');
        }
        // клиент уже сжимает; сервер — только если всё ещё тяжело
        if (strlen($contents) > 900_000) {
            $contents = $this->maybeCompressImage($contents, $file->getMimeType() ?: '');
        }
        $filename = preg_replace('/\.\w+$/', '.jpg', $file->getClientOriginalName()) ?: 'photo.jpg';

        $attempt = 0;
        $lastError = null;
        while ($attempt < 2) {
            $attempt++;
            $meta = $this->kpUploadMeta($externalId, force: $attempt > 1);
            $uploadPath = $meta['uploads'][$target] ?? null;
            if (! $uploadPath) {
                throw new RuntimeException('Не найден URL загрузки документов в КП (target='.$target.')');
            }

            $req = $this->client()
                ->timeout(60)
                ->asMultipart()
                ->withHeaders([
                    'Referer' => $this->url('/admin/domain/customer-request/update?id='.urlencode($externalId)),
                    'Origin' => rtrim((string) ($this->connection->base_url ?: config('desk.crm2_base_url')), '/'),
                    'X-Requested-With' => 'XMLHttpRequest',
                    'Accept' => 'application/json, text/javascript, */*; q=0.01',
                    'X-CSRF-Token' => (string) ($meta['csrf_token'] ?? ''),
                ]);
            if (! empty($meta['csrf_param']) && ! empty($meta['csrf_token'])) {
                $req = $req->attach($meta['csrf_param'], $meta['csrf_token']);
            }
            $req = $req->attach($field, $contents, $filename, ['Content-Type' => 'image/jpeg']);

            $res = $req->post($this->url($uploadPath));
            if (str_contains($res->body(), 'id="login-form"') || str_contains($res->body(), 'page-is-login')) {
                Cache::forget('kp_jar_'.$this->credential->id);
                $this->forgetKpUploadMeta($externalId);
                $lastError = 'Сессия kp сброшена при загрузке документа';
                $this->authenticate();

                continue;
            }
            if ($res->status() >= 400) {
                $this->forgetKpUploadMeta($externalId);
                $lastError = 'kp загрузка документа HTTP '.$res->status().': '.mb_substr(strip_tags($res->body()), 0, 180);

                continue;
            }

            $payload = $res->json();
            $url = null;
            if (is_array($payload) && isset($payload[0]) && is_string($payload[0])) {
                $url = $payload[0];
            } elseif (is_array($payload)) {
                $url = $payload['downloadUrl'] ?? $payload['url'] ?? null;
                if (is_array($url)) {
                    $url = $url[0] ?? null;
                }
            }
            if (! is_string($url) || $url === '') {
                $this->forgetKpUploadMeta($externalId);
                $lastError = 'КП не вернул URL загруженного файла';

                continue;
            }

            $base = rtrim((string) ($this->connection->base_url ?: config('desk.crm2_base_url')), '/');
            $abs = str_starts_with($url, 'http') ? $url : $base.$url;

            // без повторной загрузки 900KB-страницы: id = kpurl:…, image_id резолвится при удалении
            return [
                'id' => 'kpurl:'.md5($abs),
                'name' => $filename,
                'category' => $category,
                'url' => $abs,
                'mime' => 'image/jpeg',
            ];
        }

        throw new RuntimeException($lastError ?: 'Не удалось загрузить документ в КП');
    }

    /**
     * Кэш uploadUrl + CSRF на заказ (~15 мин), чтобы не качать HTML на каждое фото.
     *
     * @return array{uploads: array<string,string>, csrf_param:?string, csrf_token:?string}
     */
    protected function kpUploadMeta(string $externalId, bool $force = false): array
    {
        $cacheKey = $this->kpUploadMetaCacheKey($externalId);
        if (! $force) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && ! empty($cached['uploads']) && is_array($cached['uploads'])) {
                return $cached;
            }
        }

        $html = $this->fetchUpdateHtml($externalId);
        [$csrfParam, $csrfToken] = $this->extractCsrfPair($html);
        $uploads = [];
        foreach (['images_main', 'images_check', 'images_spare', 'images_safetyreceipt'] as $target) {
            $path = $this->extractKpUploadUrl($html, $target);
            if ($path) {
                $uploads[$target] = $path;
            }
        }
        if ($uploads === []) {
            throw new RuntimeException('Не найдены URL загрузки документов в карточке КП');
        }

        $meta = [
            'uploads' => $uploads,
            'csrf_param' => $csrfParam,
            'csrf_token' => $csrfToken,
        ];
        Cache::put($cacheKey, $meta, now()->addMinutes(15));

        return $meta;
    }

    protected function forgetKpUploadMeta(string $externalId): void
    {
        Cache::forget($this->kpUploadMetaCacheKey($externalId));
    }

    protected function kpUploadMetaCacheKey(string $externalId): string
    {
        return 'kp_upmeta_'.($this->credential?->id ?? 0).'_'.$externalId;
    }

    protected function warmKpUploadMetaFromHtml(string $externalId, string $html): void
    {
        try {
            [$csrfParam, $csrfToken] = $this->extractCsrfPair($html);
            $uploads = [];
            foreach (['images_main', 'images_check', 'images_spare', 'images_safetyreceipt'] as $target) {
                $path = $this->extractKpUploadUrl($html, $target);
                if ($path) {
                    $uploads[$target] = $path;
                }
            }
            if ($uploads === []) {
                return;
            }
            Cache::put($this->kpUploadMetaCacheKey($externalId), [
                'uploads' => $uploads,
                'csrf_param' => $csrfParam,
                'csrf_token' => $csrfToken,
            ], now()->addMinutes(15));
        } catch (\Throwable) {
            // optional
        }
    }

    protected function maybeCompressImage(string $binary, string $mime): string
    {
        if (! function_exists('imagecreatefromstring') || (! str_starts_with(strtolower($mime), 'image/') && $mime !== '')) {
            // всё равно пробуем как картинку
            if (! function_exists('imagecreatefromstring')) {
                return $binary;
            }
        }
        if (strlen($binary) < 400_000 && str_contains(strtolower($mime), 'jpeg')) {
            return $binary;
        }
        $img = @imagecreatefromstring($binary);
        if (! $img) {
            return $binary;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $max = 1600;
        if ($w > $max || $h > $max) {
            $scale = min($max / max(1, $w), $max / max(1, $h));
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($img);
            $img = $dst;
        }
        ob_start();
        imagejpeg($img, null, 82);
        imagedestroy($img);
        $out = ob_get_clean();

        return is_string($out) && $out !== '' ? $out : $binary;
    }

    public function deleteDocument(string $externalId, string $documentId): void
    {
        $this->authenticate();

        // старые локальные id без загрузки в КП — только убрать из кэша desk
        if (str_starts_with($documentId, 'kp-') && ! str_starts_with($documentId, 'kpurl:')) {
            return;
        }

        $imageId = null;
        $meta = null;
        $html = null;

        if (ctype_digit($documentId)) {
            $imageId = $documentId;
            $meta = $this->kpUploadMeta($externalId);
        } else {
            $html = $this->fetchUpdateHtml($externalId);
            if (str_starts_with($documentId, 'kpurl:')) {
                foreach ($this->parseKpDocuments($html) as $d) {
                    $url = (string) ($d['url'] ?? '');
                    if ($documentId === ('kpurl:'.md5($url)) || (string) ($d['id'] ?? '') === $documentId) {
                        $imageId = (string) $d['id'];
                        break;
                    }
                    // совпадение по basename, если md5 от относительного/абсолютного url
                    if ($url !== '' && str_contains($documentId, md5($url))) {
                        $imageId = (string) $d['id'];
                        break;
                    }
                }
                if ($imageId === null) {
                    // попробовать найти по хвосту url из кэша desk — парсер уже прошёл; fallback по hash в downloadUrl
                    $needle = substr($documentId, 6);
                    if (preg_match('/"key":(\d+),"downloadUrl":"([^"]+)"/', $html)) {
                        foreach ($this->parseKpDocuments($html) as $d) {
                            if (md5((string) ($d['url'] ?? '')) === $needle) {
                                $imageId = (string) $d['id'];
                                break;
                            }
                        }
                    }
                }
            } else {
                $imageId = $this->findKpImageIdByUrl($html, $documentId);
            }
            [$csrfParam, $csrfToken] = $this->extractCsrfPair($html);
            $meta = [
                'csrf_param' => $csrfParam,
                'csrf_token' => $csrfToken,
            ];
        }

        if ($imageId === null || ! ctype_digit((string) $imageId)) {
            throw new RuntimeException('Не найден файл в КП для удаления (id='.$documentId.')');
        }

        $payload = [];
        if (! empty($meta['csrf_param']) && ! empty($meta['csrf_token'])) {
            $payload[$meta['csrf_param']] = $meta['csrf_token'];
        }
        $path = '/admin/domain/customer-request/image-delete?id='.urlencode($externalId).'&image_id='.urlencode((string) $imageId);
        $res = $this->client()
            ->timeout(30)
            ->asForm()
            ->withHeaders([
                'Referer' => $this->url('/admin/domain/customer-request/update?id='.urlencode($externalId)),
                'X-Requested-With' => 'XMLHttpRequest',
                'Accept' => 'application/json, text/javascript, */*; q=0.01',
                'X-CSRF-Token' => (string) ($meta['csrf_token'] ?? ''),
            ])
            ->post($this->url($path), $payload);
        if ($res->status() >= 400) {
            throw new RuntimeException('kp удаление документа HTTP '.$res->status());
        }
        $json = $res->json();
        if (is_array($json) && ! empty($json['error'])) {
            throw new RuntimeException('kp удаление: '.(string) $json['error']);
        }
    }

    public function downloadDocument(string $externalId, string $documentId): ?array
    {
        $this->authenticate();

        // ищем URL в актуальной карточке КП
        try {
            $html = $this->fetchUpdateHtml($externalId);
            foreach ($this->parseKpDocuments($html) as $doc) {
                if ((string) ($doc['id'] ?? '') === (string) $documentId && ! empty($doc['url'])) {
                    return $this->fetchAuthenticatedFile((string) $doc['url'], (string) ($doc['name'] ?? 'photo.jpg'));
                }
            }
        } catch (\Throwable) {
            // ниже — null
        }

        return null;
    }

    /**
     * Скачать файл КП по абсолютному/относительному URL под cookie-сессией филиала.
     *
     * @return array{body:string,mime:string,name:string}|null
     */
    public function fetchDocumentByUrl(string $url, ?string $name = null): ?array
    {
        $this->authenticate();

        return $this->fetchAuthenticatedFile($url, $name ?: 'photo.jpg');
    }

    /**
     * @return array{body:string,mime:string,name:string}|null
     */
    protected function fetchAuthenticatedFile(string $url, string $name): ?array
    {
        $base = rtrim((string) ($this->connection->base_url ?: config('desk.crm2_base_url')), '/');
        $abs = str_starts_with($url, 'http') ? $url : $base.'/'.ltrim($url, '/');

        $res = $this->client()
            ->timeout(45)
            ->withHeaders([
                'Referer' => $base.'/admin/domain/customer-request/index',
                'Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
            ])
            ->get($abs);

        if (! $res->successful()) {
            return null;
        }

        $body = $res->body();
        if ($body === '' || str_contains($body, 'id="login-form"')) {
            return null;
        }

        $mime = (string) ($res->header('Content-Type') ?: 'application/octet-stream');
        if (str_contains($mime, ';')) {
            $mime = trim(explode(';', $mime, 2)[0]);
        }
        if ($mime === '' || $mime === 'text/html') {
            $mime = match (true) {
                str_ends_with(mb_strtolower($name), '.png') => 'image/png',
                str_ends_with(mb_strtolower($name), '.gif') => 'image/gif',
                str_ends_with(mb_strtolower($name), '.webp') => 'image/webp',
                default => 'image/jpeg',
            };
        }

        return [
            'body' => $body,
            'mime' => $mime,
            'name' => $name,
        ];
    }

    protected function kpDocumentTarget(string $category): ?string
    {
        return match ($category) {
            'contract' => 'images_main',
            'receipts' => 'images_check',
            'parts_photos' => 'images_spare',
            'storage_receipt' => 'images_safetyreceipt',
            default => null,
        };
    }

    protected function kpDocumentField(string $category): ?string
    {
        $target = $this->kpDocumentTarget($category);

        return $target ? 'CustomerRequest['.$target.'][]' : null;
    }

    protected function extractKpUploadUrl(string $html, string $target): ?string
    {
        if (! preg_match('/"uploadUrl":"([^"]*target='.preg_quote($target, '/').'[^"]*)"/', $html, $m)) {
            return null;
        }
        $path = json_decode('"'.$m[1].'"');

        return is_string($path) && $path !== '' ? $path : null;
    }

    protected function findKpImageIdByUrl(string $html, string $url): ?string
    {
        $needle = basename(parse_url($url, PHP_URL_PATH) ?: $url);
        if ($needle === '') {
            return null;
        }
        $needle = preg_quote($needle, '/');
        if (preg_match('/\{"type":"image","url":"[^"]*image_id=(\d+)[^"]*","key":\d+,"downloadUrl":"[^"]*'.$needle.'[^"]*"\}/', $html, $m)
            || preg_match('/"key":(\d+),"downloadUrl":"[^"]*'.$needle.'[^"]*"/', $html, $m)) {
            return $m[1];
        }

        return null;
    }

    protected function fetchUpdateHtml(string $externalId): string
    {
        $pathTpl = (string) data_get($this->connection->config, 'order_path', '/admin/domain/customer-request/update?id={id}');
        $path = str_replace('{id}', urlencode($externalId), $pathTpl);
        $html = $this->client()->get($this->url($path));
        if ($html->status() === 404) {
            throw new RuntimeException('Заказ kp #'.$externalId.' не найден');
        }
        if (! $html->successful()) {
            throw new RuntimeException('Не удалось открыть карточку kp #'.$externalId.' (HTTP '.$html->status().')');
        }
        $body = $html->body();
        if (str_contains($body, 'id="login-form"') || str_contains($body, 'page-is-login')) {
            throw new RuntimeException('Сессия kp не установлена');
        }

        return $body;
    }

    /**
     * @return array<string, string>
     */
    protected function extractCustomerRequestForm(string $html): array
    {
        if (! preg_match('/<form[^>]*id="customerRequestForm"[^>]*>(.*?)<\/form>/is', $html, $fm)) {
            // fallback: first form targeting update
            if (! preg_match('/<form[^>]*action="[^"]*customer-request\/update[^"]*"[^>]*>(.*?)<\/form>/is', $html, $fm)) {
                throw new RuntimeException('Не найдена форма сохранения заказа в kp');
            }
        }
        $formHtml = $fm[1];
        $payload = [];

        if (preg_match_all('/<input\b([^>]*)>/i', $formHtml, $inputs, PREG_SET_ORDER)) {
            foreach ($inputs as $input) {
                $attrs = $input[1];
                if (! preg_match('/\bname="([^"]+)"/i', $attrs, $nm)) {
                    continue;
                }
                $type = 'text';
                if (preg_match('/\btype="([^"]+)"/i', $attrs, $tm)) {
                    $type = strtolower($tm[1]);
                }
                if (in_array($type, ['submit', 'button', 'file', 'image'], true)) {
                    continue;
                }
                if (in_array($type, ['checkbox', 'radio'], true) && ! preg_match('/\bchecked\b/i', $attrs)) {
                    continue;
                }
                $value = '';
                if (preg_match('/\bvalue="([^"]*)"/i', $attrs, $vm)) {
                    $value = html_entity_decode($vm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                $name = html_entity_decode($nm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (str_ends_with($name, '[]')) {
                    $payload[$name] = array_merge((array) ($payload[$name] ?? []), [$value]);
                } else {
                    $payload[$name] = $value;
                }
            }
        }

        if (preg_match_all('/<textarea\b([^>]*)>(.*?)<\/textarea>/is', $formHtml, $areas, PREG_SET_ORDER)) {
            foreach ($areas as $area) {
                if (! preg_match('/\bname="([^"]+)"/i', $area[1], $nm)) {
                    continue;
                }
                $payload[html_entity_decode($nm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')] = trim(
                    html_entity_decode(strip_tags($area[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')
                );
            }
        }

        if (preg_match_all('/<select\b([^>]*)>(.*?)<\/select>/is', $formHtml, $selects, PREG_SET_ORDER)) {
            foreach ($selects as $sel) {
                if (! preg_match('/\bname="([^"]+)"/i', $sel[1], $nm)) {
                    continue;
                }
                $name = html_entity_decode($nm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $value = null;
                if (preg_match('/<option[^>]*selected[^>]*value="([^"]*)"/i', $sel[2], $om)
                    || preg_match('/<option[^>]*value="([^"]*)"[^>]*selected/i', $sel[2], $om)) {
                    $value = html_entity_decode($om[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                if ($value !== null) {
                    $payload[$name] = $value;
                }
            }
        }

        // action-flags с кнопок не тащим по умолчанию
        foreach ([
            'save_close', 'not_taken', 'pay_first', 'cancel_kc', 'cancel_branch', 'cancel_regional',
            'on_precheck', 'not_taken_to_await', 'precheck_to_await', 'precheck_to_nottaken',
            'rejected_by_customer', 'to_not_created', 'transfer_request', 'create_claim', 'create_draft',
            'unroll_finish',
        ] as $flag) {
            unset($payload[$flag]);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return string HTML ответа КП
     */
    protected function postCustomerRequestForm(
        string $externalId,
        array $payload,
        string $pageHtml,
        bool $expectClosed = false,
        bool $finish = false,
    ): string {
        [$csrfParam, $csrfToken] = $this->extractCsrfPair($pageHtml);
        if ($csrfParam && $csrfToken) {
            $payload[$csrfParam] = $csrfToken;
        }

        // flatten: Laravel asForm умеет массивы; файловые поля пропускаем
        $flat = [];
        foreach ($payload as $key => $value) {
            if (str_starts_with($key, 'Upload_') || str_contains($key, 'images_')) {
                continue;
            }
            if (is_array($value)) {
                $flat[$key] = array_values(array_map('strval', $value));
            } else {
                $flat[$key] = (string) $value;
            }
        }

        $path = '/admin/domain/customer-request/update?id='.urlencode($externalId);
        if ($finish) {
            $path .= '&finish=1';
        }
        $refererPath = '/admin/domain/customer-request/update?id='.urlencode($externalId);
        $res = $this->client()
            ->asForm()
            ->timeout(60)
            ->withHeaders([
                'Referer' => $this->url($refererPath),
                'Origin' => rtrim((string) ($this->connection->base_url ?: config('desk.crm2_base_url')), '/'),
                // HTML-форма (как «Провести заявку»), не XHR — у finish XHR часто даёт {"result":false}
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ])
            ->post($this->url($path), $flat);

        $body = $res->body();
        if ($res->status() >= 400) {
            $hint = $this->extractKpAlertText($body)
                ?? $this->extractKpFormValidationError($body)
                ?? $this->extractKpInternalErrorMessage($body);
            throw new RuntimeException(
                $hint
                    ? ('КП: '.$hint)
                    : ('kp сохранение HTTP '.$res->status().': '.mb_substr(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '', 0, 220))
            );
        }
        if (str_contains($body, 'id="login-form"') || str_contains($body, 'page-is-login')) {
            throw new RuntimeException('Сессия kp сброшена при сохранении');
        }

        $validationError = $this->extractKpFormValidationError($body);
        if ($validationError !== null) {
            throw new RuntimeException('kp: '.$validationError);
        }

        if ($expectClosed) {
            $check = $this->fetchUpdateHtml($externalId);
            if (! $this->kpHtmlLooksCompleted($check)) {
                $hint = $this->extractKpFormValidationError($check)
                    ?? $this->extractKpAlertText($body)
                    ?? $this->extractKpAlertText($check);
                throw new RuntimeException(
                    'КП не перевёл заказ в «Готов»'
                    .($hint ? ': '.$hint : '')
                    .'. Проверьте БСО/суммы/документы и нажмите «Провести» ещё раз.'
                );
            }
        }

        return $body;
    }

    protected function kpHtmlLooksCompleted(string $html): bool
    {
        [$code] = $this->parseKpStatusFromHtml($html);
        if ($code !== null && $code !== '') {
            $raw = $this->kpStatusIdToCode((int) $code) ?? $this->normalizeStatus((string) $code);

            return in_array($raw, ['completed', 'cancelled_cc', 'cancelled_city', 'rejected'], true);
        }

        return false;
    }

    /**
     * Статус карточки КП: select, иначе заголовок «Статус: …» (на СД select часто отсутствует).
     *
     * @return array{0:?string,1:?string} [kpId, label]
     */
    protected function parseKpStatusFromHtml(string $html): array
    {
        $statusCode = null;
        $statusLabel = null;

        if (preg_match('/name="CustomerRequest\[status\]"[^>]*>(.*?)<\/select>/is', $html, $sm)) {
            if (preg_match('/<option[^>]*selected[^>]*value="([^"]*)"[^>]*>([^<]*)/i', $sm[1], $om)
                || preg_match('/<option[^>]*value="([^"]*)"[^>]*selected[^>]*>([^<]*)/i', $sm[1], $om)) {
                $statusCode = trim($om[1]);
                $statusLabel = trim(html_entity_decode($om[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }

        if (($statusCode === null || $statusCode === '')
            && preg_match('/name="CustomerRequest\[status\]"[^>]*value="([^"]+)"/i', $html, $hm)) {
            $statusCode = trim($hm[1]);
        }

        if ($statusLabel === null || $statusLabel === '') {
            if (preg_match('/<h1[^>]*>([\s\S]*?)<\/h1>/i', $html, $h)) {
                $title = trim(preg_replace('/\s+/', ' ', strip_tags($h[1])));
                if (preg_match('/Статус:\s*(.+)$/ui', $title, $tm)) {
                    $statusLabel = trim($tm[1]);
                }
            }
        }

        if (($statusCode === null || $statusCode === '') && $statusLabel) {
            $statusCode = (string) ($this->statusCodeToKpId($this->normalizeStatus($statusLabel)) ?? '');
            if ($statusCode === '') {
                $statusCode = null;
            }
        }

        return [$statusCode, $statusLabel];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function ensurePayloadHasKpStatus(array &$payload, string $html): void
    {
        $current = trim((string) ($payload['CustomerRequest[status]'] ?? ''));
        if ($current !== '') {
            return;
        }

        [$kpId] = $this->parseKpStatusFromHtml($html);
        if ($kpId !== null && $kpId !== '') {
            $payload['CustomerRequest[status]'] = (string) $kpId;

            return;
        }

        // лучше не слать пустой status — КП падает с 500
        unset($payload['CustomerRequest[status]']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function ensureWorkInSdReadyAt(array &$payload): void
    {
        $current = trim((string) ($payload['CustomerRequest[work_in_sd_ready_at]'] ?? ''));
        if ($current !== '') {
            return;
        }
        // как на карточке КП (__tOpenedAt): d-m-Y
        $payload['CustomerRequest[work_in_sd_ready_at]'] = now()->format('d-m-Y');
    }

    protected function extractKpInternalErrorMessage(string $html): ?string
    {
        if (preg_match('/<(?:h1|h2)[^>]*>\s*Internal Server Error[^<]*<\/(?:h1|h2)>\s*<(?:p|div)[^>]*>\s*([^<]{5,240})/i', $html, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if (preg_match('/Не выбран СТАТУС ЗАЯВКИ[^.<]*/u', $html, $m)) {
            return trim($m[0]);
        }

        return null;
    }

    /**
     * Блок проведения с карточки КП («Готов»):
     * Проведенная сумма / Сумма к сдаче / Группа расчета.
     *
     * @return array{
     *   source:string,external_id:?string,paid:int,amount_to_pay:int,master_salary:int,
     *   calc_group:?string,calc_group_code:?string,text:string
     * }|null
     */
    protected function parseKpConductCalc(string $html, ?string $externalId = null): ?array
    {
        if (! preg_match(
            '/Проведенная сумма по заявке:\s*<\/b>\s*([\d\s\x{00a0}&nbsp;]+)\s*р\.?/u',
            $html,
            $paidM
        )) {
            return null;
        }
        if (! preg_match(
            '/Сумма к сдаче:\s*<\/b>\s*(?:<span[^>]*>\s*)?([\d\s\x{00a0}&nbsp;]+)\s*р\.?/u',
            $html,
            $toPayM
        )) {
            return null;
        }

        $paid = (int) preg_replace('/\D+/', '', html_entity_decode($paidM[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $toPay = (int) preg_replace('/\D+/', '', html_entity_decode($toPayM[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $group = null;
        $groupCode = null;
        if (preg_match(
            '/Группа расчета:\s*<\/b>\s*(&quot;|"|«)?\s*([A-Za-zА-Яа-яЁё])\s*(&quot;|"|»)?\s*(?:<sub[^>]*>\s*([^<]+?)\s*<\/sub>)?/u',
            $html,
            $gM
        )) {
            $group = trim($gM[2]);
            $groupCode = isset($gM[4]) ? trim(html_entity_decode($gM[4], ENT_QUOTES | ENT_HTML5, 'UTF-8')) : null;
        }

        if ($externalId === null || $externalId === '') {
            if (preg_match('/Заявка\s*№\s*(\d+)/u', $html, $idM)) {
                $externalId = $idM[1];
            }
        }

        $groupLabel = $group !== null ? '"'.$group.'"' : null;
        $lines = [];
        if ($externalId) {
            $lines[] = 'Заявка №'.$externalId;
        }
        $lines[] = 'Проведенная сумма по заявке: '.number_format($paid, 0, ',', ' ').' р.';
        $lines[] = 'Сумма к сдаче: '.number_format($toPay, 0, ',', ' ').' р.';
        if ($groupLabel !== null) {
            $lines[] = 'Группа расчета: '.$groupLabel.($groupCode ? ' '.$groupCode : '');
        }

        return [
            'source' => 'kp',
            'external_id' => $externalId,
            'paid' => $paid,
            'amount_to_pay' => $toPay,
            'master_salary' => max(0, $paid - $toPay),
            'calc_group' => $groupLabel,
            'calc_group_code' => $groupCode,
            'text' => implode("\n", $lines),
        ];
    }

    protected function extractKpFormValidationError(string $html): ?string
    {
        // Только реальные ошибки полей Yii: .has-error … .help-block с текстом
        if (! preg_match_all(
            '/<(?:div|td|tr|li)\b[^>]*\bhas-error\b[^>]*>[\s\S]{0,1200}?<div[^>]*\bhelp-block\b[^>]*>\s*([^<]+?)\s*<\/div>/i',
            $html,
            $mm
        )) {
            return null;
        }
        $msgs = [];
        foreach ($mm[1] as $raw) {
            $msg = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($msg === '' || str_starts_with($msg, '#') || str_contains($msg, '{')) {
                continue;
            }
            $msgs[$msg] = $msg;
        }

        return $msgs === [] ? null : implode('; ', array_values($msgs));
    }

    protected function extractKpAlertText(string $html): ?string
    {
        $messages = [];

        // flash в .alert-container / .alert-danger
        if (preg_match_all(
            '/alert-(?:danger|warning)\b[^>]*>\s*(?:&times;|×)?\s*([\s\S]*?)(?:<\/div>|(?=\.alert-widget)|(?=<style))/iu',
            $html,
            $mm
        )) {
            foreach ($mm[1] as $raw) {
                $msg = $this->normalizeKpFlashMessage($raw);
                if ($msg !== null) {
                    $messages[] = $msg;
                }
            }
        }

        // типовые отказы КП по статусу/СД
        if (preg_match_all(
            '/Загрузите сохранн\S{0,30}распис\S{0,20}\.?\s*Требуется для перевода в статус[^.<]{0,80}|Отказ в смене статуса\.\s*[^.<]{0,180}|Изменение заявки в этом статусе не разрешено\./iu',
            $html,
            $pm
        )) {
            foreach ($pm[0] as $raw) {
                $msg = $this->normalizeKpFlashMessage($raw);
                if ($msg !== null) {
                    $messages[] = $msg;
                }
            }
        }

        $messages = array_values(array_unique(array_filter($messages)));
        if ($messages === []) {
            return null;
        }

        // бизнес-ошибки статуса важнее «Примечание: браузер…»
        usort($messages, function (string $a, string $b): int {
            $score = function (string $m): int {
                return match (true) {
                    str_contains(mb_strtolower($m), 'сохран') => 30,
                    str_contains(mb_strtolower($m), 'отказ') => 25,
                    str_contains(mb_strtolower($m), 'статус') => 20,
                    str_contains(mb_strtolower($m), 'сд') => 15,
                    str_starts_with(mb_strtolower($m), 'примечание') => -50,
                    default => 0,
                };
            };

            return $score($b) <=> $score($a);
        });

        // одна-две самые релевантные
        $top = array_slice($messages, 0, 2);

        return implode(' ', $top);
    }

    protected function normalizeKpFlashMessage(string $raw): ?string
    {
        $msg = trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $msg = preg_replace('/\s+/u', ' ', $msg) ?? $msg;
        $msg = trim($msg, " \t\n\r\0\x0B×");
        if ($msg === '' || mb_strlen($msg) < 8 || mb_strlen($msg) > 280) {
            return null;
        }
        if (preg_match('/alert-widget|errorCssClass|yiiActiveForm|filterUrl/i', $msg)) {
            return null;
        }
        if (str_starts_with(mb_strtolower($msg), 'примечание:')) {
            return null;
        }

        return $msg;
    }

    /** @return array<int, string> */
    protected function kpStatusMap(): array
    {
        return [
            1 => 'pending',
            3 => 'callback',
            4 => 'on_way',
            8 => 'in_progress',
            9 => 'in_progress_sd',
            20 => 'not_processed',
            32 => 'cancelled_city',
            48 => 'not_processed',
            64 => 'rejected',
            128 => 'completed',
            1001 => 'cancelled_cc',
            1002 => 'cancelled_city',
            1003 => 'cancelled_city',
            1020 => 'completed',
        ];
    }

    protected function kpStatusIdToCode(int $id): ?string
    {
        return $this->kpStatusMap()[$id] ?? null;
    }

    protected function statusCodeToKpId(string $code): ?int
    {
        $map = array_flip($this->kpStatusMap());
        // уникальные предпочтения
        $preferred = [
            'pending' => 1,
            'callback' => 3,
            'on_way' => 4,
            'in_progress' => 8,
            'in_progress_sd' => 9,
            'not_processed' => 20,
            'completed' => 128,
            'cancelled_cc' => 1001,
            'cancelled_city' => 1002,
            'rejected' => 64,
            'review' => 8,
        ];

        return $preferred[$code] ?? ($map[$code] ?? null);
    }

    /** @return list<array<string, mixed>> */
    protected function parseOrdersHtml(string $html): array
    {
        $prev = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $xpath = new \DOMXPath($dom);
        $tables = $xpath->query('//table[contains(@class,"table") or contains(@class,"grid") or contains(@class,"kv-grid") or @id] | //table');
        if (! $tables || $tables->length === 0) {
            return [];
        }

        $best = null;
        $bestScore = -1;
        foreach ($tables as $table) {
            $rows = $xpath->query('.//tr', $table);
            $score = $rows ? $rows->length : 0;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $table;
            }
        }
        if (! $best) {
            return [];
        }

        $headerMap = [];
        // только первая строка thead (заголовки), без строки фильтров
        $headerCells = $xpath->query('.//thead/tr[1]/th|.//thead/tr[1]/td', $best);
        if (! $headerCells || $headerCells->length === 0) {
            $headerCells = $xpath->query('.//tr[1]/th|.//tr[1]/td', $best);
        }
        if ($headerCells) {
            foreach ($headerCells as $i => $th) {
                $label = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $th->textContent ?? '')));
                $headerMap[$i] = $label;
            }
        }

        $orders = [];
        $trs = $xpath->query('.//tbody/tr', $best);
        if (! $trs || $trs->length === 0) {
            $trs = $xpath->query('.//tr', $best);
        }
        if (! $trs) {
            return [];
        }

        foreach ($trs as $tr) {
            /** @var \DOMElement $tr */
            $tds = $xpath->query('./td', $tr);
            if (! $tds || $tds->length < 2) {
                continue;
            }
            $cells = [];
            foreach ($tds as $td) {
                $cells[] = trim(preg_replace('/\s+/u', ' ', $td->textContent ?? ''));
            }

            $dataKey = $tr->hasAttribute('data-key') ? $tr->getAttribute('data-key') : null;
            $id = $dataKey
                ?: $this->cellByHeaders($cells, $headerMap, ['id', '№', 'номер', '#'])
                ?: ($cells[0] ?? null);
            if (! $id || ! preg_match('/\d+/', (string) $id, $m)) {
                continue;
            }
            $externalId = $m[0];

            $status = $this->cellByHeaders($cells, $headerMap, ['статус', 'status']) ?: '';
            $address = $this->cellByHeaders($cells, $headerMap, ['адрес', 'address']) ?: '';
            $client = $this->cellByHeaders($cells, $headerMap, ['клиент', 'имя', 'фио', 'customer']) ?: '';
            $phone = $this->cellByHeaders($cells, $headerMap, ['телефон', 'phone', 'тел']) ?: '';
            $master = $this->cellByHeaders($cells, $headerMap, ['мастер', 'master']) ?: '';
            $rk = $this->cellByHeaders($cells, $headerMap, ['рк', 'реклам', 'кампан', 'источник', 'source']) ?: '';
            $type = $this->cellByHeaders($cells, $headerMap, ['тип', 'type']) ?: null;
            $created = $this->cellByHeaders($cells, $headerMap, ['создан', 'дата', 'created']) ?: null;
            $call = $this->cellByHeaders($cells, $headerMap, ['вызов', 'время заявки', 'время']) ?: null;
            $cityFromList = $this->cellByHeaders($cells, $headerMap, ['город', 'city']) ?: null;
            if ($cityFromList !== null) {
                $cityFromList = trim($cityFromList);
                if ($cityFromList === '' || str_contains(mb_strtolower($cityFromList), 'выбрать')) {
                    $cityFromList = null;
                }
            }

            // колонка «Сумма» в КП (= total_cost) — для отображения в desk до открытия карточки
            $sumRaw = $this->cellByHeaders($cells, $headerMap, ['сумма', 'total', 'cost']);
            $listAmount = null;
            if ($sumRaw !== null && $sumRaw !== '' && $sumRaw !== '—' && $sumRaw !== '-') {
                $digits = preg_replace('/[^\d]/u', '', $sumRaw);
                if ($digits !== null && $digits !== '') {
                    $listAmount = (int) $digits;
                }
            }

            // колонка «Н» — непрофиль
            $isNoncore = false;
            foreach ($headerMap as $i => $label) {
                if ($label === 'н' || str_contains($label, 'непроф')) {
                    $cell = $cells[$i] ?? '';
                    $isNoncore = $cell !== '' && $cell !== '—' && ! in_array(mb_strtolower($cell), ['нет', '0', 'false'], true);
                }
            }

            $raw = $this->normalizeStatus($status);
            $orderType = $this->normalizeType($type);
            $needsFeedback = $this->statusTextNeedsFeedback($status);

            $row = [
                'external_id' => $externalId,
                'raw_status' => $raw,
                'client_name' => $client ?: null,
                'phone' => $phone ?: null,
                'address' => $address ?: null,
                'city_name' => $cityFromList,
                'master_name' => $master ?: null,
                'order_type' => $orderType,
                'needs_feedback' => $needsFeedback,
                'total_amount' => $listAmount,
                'created_at_local' => $this->parseDate($created),
                'call_at_local' => $this->parseDate($call),
                'timezone' => 'Europe/Moscow',
                'is_closed' => in_array($raw, ['completed', 'cancelled_cc', 'cancelled_city', 'rejected'], true),
                'hash' => sha1(implode('|', $cells)),
                'priority' => 0,
                'row_highlight' => null,
            ];
            // Пустой РК/«Н» из списка не затирают уже выжатую карточку
            if ($rk !== '') {
                $row['rk'] = $rk;
            }
            if ($isNoncore) {
                $row['is_noncore'] = true;
            }
            $orders[] = $row;
        }

        return $orders;
    }

    /**
     * Разбор __tCustomerAddress: «Псков (рабочий посёлок Палкино) Рабочая улица, 3, …».
     *
     * @return array{locality:?string, street:?string}
     */
    protected function splitKpCustomerAddress(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return ['locality' => null, 'street' => null];
        }

        // Город/НП со скобками, затем улица (типичный шаблон КП для спутников/посёлков).
        if (preg_match('/^(.+?\([^)]+\))\s+(.+)$/u', $raw, $m) === 1) {
            $locality = trim($m[1]);
            $street = trim($m[2]);
            // Убрать хвост «кв/офис» из улицы, если он уже уйдёт в address_office.
            $street = preg_replace('/,\s*(?:кв\.?\/?офис|подъезд|этаж|домофон|встретит)\b.*$/iu', '', $street) ?? $street;
            $street = trim($street, " \t,");

            return [
                'locality' => $locality !== '' ? $locality : null,
                'street' => $street !== '' ? $street : null,
            ];
        }

        return ['locality' => null, 'street' => $raw];
    }

    /**
     * @param  list<string>  $cells
     * @param  array<int, string>  $headerMap
     * @param  list<string>  $needles
     */
    protected function cellByHeaders(array $cells, array $headerMap, array $needles): ?string
    {
        foreach ($headerMap as $i => $label) {
            foreach ($needles as $n) {
                if ($label !== '' && str_contains($label, $n)) {
                    return $cells[$i] ?? null;
                }
            }
        }

        return null;
    }

    protected function normalizeStatus(string $status): string
    {
        $s = mb_strtolower(trim($status));

        return match (true) {
            $s === '' => 'in_progress',
            str_contains($s, 'ожид') => 'pending',
            str_contains($s, 'прозвон') || str_contains($s, 'перезвон') => 'callback',
            str_contains($s, 'не оформ') => 'not_processed',
            str_contains($s, 'пути') || str_contains($s, 'выезд') => 'on_way',
            // «В работе СД» — до общего «работ»
            str_contains($s, 'сд') => 'in_progress_sd',
            str_contains($s, 'работ') => 'in_progress',
            str_contains($s, 'провер') || (str_contains($s, 'закрыт') && str_contains($s, 'к ')) => 'review',
            str_contains($s, 'готов') || str_contains($s, 'выполн') => 'completed',
            // Сначала КЦ — иначе «Отмена КЦ» падает в общий «отмен» → cancelled_city
            str_contains($s, 'отмен') && (
                str_contains($s, 'кц')
                || str_contains($s, 'kc')
                || str_contains($s, 'cc')
                || str_contains($s, 'колл')
                || str_contains($s, 'call')
                || str_contains($s, 'диспетч')
            ) => 'cancelled_cc',
            str_contains($s, 'отмен') && (
                str_contains($s, 'филиал')
                || str_contains($s, 'город')
                || str_contains($s, 'branch')
                || str_contains($s, 'рег')
            ) => 'cancelled_city',
            str_contains($s, 'отмен') => 'cancelled_city',
            str_contains($s, 'отказ') => 'rejected',
            default => 'in_progress',
        };
    }

    protected function normalizeType(?string $type): ?string
    {
        if (! $type) {
            return null;
        }
        $t = mb_strtolower($type);

        return match (true) {
            str_contains($t, 'повтор') => 'repeat',
            str_contains($t, 'гарант') => 'warranty',
            str_contains($t, 'вперв') || str_contains($t, 'нов') => 'first',
            default => null,
        };
    }

    protected function parseSelectSelectedInt(string $html, string $name): ?int
    {
        $q = preg_quote($name, '/');
        if (! preg_match('/name="'.$q.'"[^>]*>(.*?)<\/select>/is', $html, $sm)) {
            return null;
        }
        if (preg_match('/<option[^>]*selected[^>]*value="([^"]*)"/i', $sm[1], $om)
            || preg_match('/<option[^>]*value="([^"]*)"[^>]*selected/i', $sm[1], $om)) {
            $v = trim($om[1]);
            if ($v === '') {
                return null;
            }

            return (int) $v;
        }

        return null;
    }

    protected function parseDate(?string $value): ?string
    {
        if (! $value) {
            return null;
        }
        $value = trim(html_entity_decode($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        if (preg_match('/(\d{1,2}[.\-]\d{1,2}[.\-]\d{2,4}(?:\s+\d{1,2}:\d{2}(?::\d{2})?)?)/u', $value, $m)) {
            $value = str_replace('-', '.', $m[1]);
        }

        $formats = [
            'd.m.y H:i:s', 'd.m.y H:i', 'd.m.y',
            'd.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y',
            'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d',
        ];
        foreach ($formats as $fmt) {
            $dt = \DateTimeImmutable::createFromFormat('!'.$fmt, $value);
            if (! $dt instanceof \DateTimeImmutable) {
                continue;
            }
            $year = (int) $dt->format('Y');
            if ($year < 2000 || $year > 2100) {
                continue;
            }

            return $dt->format('Y-m-d H:i:s');
        }

        return null;
    }

    protected function parseOpenedAtAlt(?string $value): ?string
    {
        if (! $value) {
            return null;
        }
        // 09-08-2026 20:03
        $value = str_replace('-', '.', trim($value));

        return $this->parseDate($value);
    }

    /**
     * @return array{0:?string,1:?string}
     */
    protected function extractCsrfPair(string $html): array
    {
        $param = '_csrf-frontend';
        if (preg_match('/name="csrf-param"\s+content="([^"]+)"/', $html, $m)) {
            $param = html_entity_decode($m[1]);
        }
        if (preg_match('/name="'.preg_quote($param, '/').'"\s+value="([^"]+)"/', $html, $m)) {
            return [$param, html_entity_decode($m[1])];
        }
        if (preg_match('/csrf-token"\s+content="([^"]+)"/', $html, $m)) {
            return [$param, html_entity_decode($m[1])];
        }
        if (preg_match('/name="_csrf"\s+value="([^"]+)"/', $html, $m)) {
            return ['_csrf', html_entity_decode($m[1])];
        }

        return [null, null];
    }

    protected function url(string $path): string
    {
        $base = rtrim((string) ($this->connection->base_url ?: config('desk.crm2_base_url')), '/');

        return $base.(str_starts_with($path, '/') ? $path : '/'.$path);
    }

    protected function client(): PendingRequest
    {
        $this->cookies ??= new CookieJar();
        // KP с VPS иногда «зависает» на TLS; 15с мало при деградации, 45с — потолок
        $timeout = (int) ($this->connection->timeout ?: 30);
        if ($timeout < 20) {
            $timeout = 30;
        }
        if ($timeout > 60) {
            $timeout = 60;
        }
        $connect = min(30, max(20, $timeout));

        return Http::timeout($timeout)
            ->connectTimeout($connect)
            ->retry(2, 800, function ($exception) {
                return $exception instanceof \Illuminate\Http\Client\ConnectionException;
            })
            ->withOptions([
                'cookies' => $this->cookies,
                'allow_redirects' => true,
                'verify' => (bool) data_get($this->connection->config, 'verify_ssl', true),
                // Только IPv4: на части хостов AAAA/happy-eyeballs даёт ложные SSL timeout
                'force_ip_resolve' => 'v4',
                'curl' => [
                    \CURLOPT_IPRESOLVE => \CURL_IPRESOLVE_V4,
                    \CURLOPT_TCP_KEEPALIVE => 1,
                ],
            ])
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; LeadDesk/1.0)',
                'Accept' => 'text/html,application/xhtml+xml',
            ]);
    }
}
