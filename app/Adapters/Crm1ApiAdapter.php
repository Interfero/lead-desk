<?php

namespace App\Adapters;

use App\Contracts\CrmAdapter;
use App\Models\CrmConnection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class Crm1ApiAdapter implements CrmAdapter
{
    public function __construct(
        protected CrmConnection $connection,
        protected ?int $actingUserId = null
    ) {}

    public function authenticate(): void
    {
        if (! $this->token()) {
            throw new RuntimeException('CRM1 API token пуст');
        }
    }

    public function fetchOrders(array $filters = []): array
    {
        $query = ['lite' => 1];
        if (! empty($filters['full'])) {
            $query['full'] = 1;
        } elseif (! empty($filters['updated_after'])) {
            $query['updated_after'] = $filters['updated_after'] instanceof \DateTimeInterface
                ? $filters['updated_after']->format('Y-m-d H:i:s')
                : (string) $filters['updated_after'];
        }
        // Все филиалы LC, даже если передан acting user (скоуп — в UI Desk).
        $query['all_cities'] = 1;

        $res = $this->client()->get('/api/v1/desk/orders', $query);
        $this->assertOk($res, 'fetchOrders');

        return $res->json('orders') ?? [];
    }

    public function fetchOrder(string $externalId, array $options = []): ?array
    {
        $res = $this->client()->get('/api/v1/desk/orders/'.$externalId);
        if ($res->status() === 404) {
            return null;
        }
        $this->assertOk($res, 'fetchOrder');

        return $res->json('order');
    }

    public function updateOrder(string $externalId, array $data): void
    {
        $res = $this->client()->patch('/api/v1/desk/orders/'.$externalId, $data);
        $this->assertOk($res, 'updateOrder');
    }

    public function closeOrder(string $externalId, array $documents = []): ?array
    {
        $res = $this->client()->post('/api/v1/desk/orders/'.$externalId.'/close', $documents);
        $this->assertOk($res, 'closeOrder');

        $calculation = $res->json('calculation');
        if (is_array($calculation) && $calculation !== []) {
            return $calculation;
        }

        $order = $res->json('order');
        if (is_array($order) && is_array($order['calculation'] ?? null)) {
            return $order['calculation'];
        }

        return null;
    }

    public function getStatuses(): array
    {
        $res = $this->client()->get('/api/v1/desk/statuses');
        $this->assertOk($res, 'getStatuses');

        return $res->json('statuses') ?? [];
    }

    public function uploadDocument(string $externalId, string $category, UploadedFile $file): array
    {
        $res = $this->client()
            ->attach('file', file_get_contents($file->getRealPath()), $file->getClientOriginalName())
            ->attach('category', $category)
            ->post('/api/v1/desk/orders/'.$externalId.'/documents');
        $this->assertOk($res, 'uploadDocument');

        $doc = $res->json('document') ?? [];

        return [
            'id' => (string) ($doc['id'] ?? ''),
            'name' => (string) ($doc['name'] ?? $file->getClientOriginalName()),
            'category' => (string) ($doc['category'] ?? $category),
        ];
    }

    public function deleteDocument(string $externalId, string $documentId): void
    {
        $res = $this->client()->delete('/api/v1/desk/orders/'.$externalId.'/documents/'.$documentId);
        $this->assertOk($res, 'deleteDocument');
    }

    public function downloadDocument(string $externalId, string $documentId): ?array
    {
        $res = $this->client()->withHeaders(['Accept' => '*/*'])
            ->get('/api/v1/desk/orders/'.$externalId.'/documents/'.$documentId);
        if ($res->status() === 404) {
            return null;
        }
        $this->assertOk($res, 'downloadDocument');

        return [
            'body' => $res->body(),
            'mime' => $res->header('Content-Type') ?: 'application/octet-stream',
            'name' => 'document',
        ];
    }

    protected function client()
    {
        $timeout = (int) ($this->connection->timeout ?: 90);
        $headers = [
            'Authorization' => 'Bearer '.$this->token(),
            'Accept' => 'application/json',
        ];
        if ($this->actingUserId) {
            $headers['X-Desk-User-Id'] = (string) $this->actingUserId;
        }

        return Http::baseUrl(rtrim((string) $this->connection->base_url, '/'))
            ->timeout($timeout)
            ->retry(2, 200)
            ->withHeaders($headers);
    }

    protected function token(): string
    {
        return (string) ($this->connection->config['api_token'] ?? config('desk.crm1_token', ''));
    }

    protected function assertOk($response, string $action): void
    {
        if ($response->successful()) {
            return;
        }

        throw new RuntimeException(
            "CRM1 {$action} HTTP {$response->status()}: ".mb_substr($response->body(), 0, 500)
        );
    }
}
