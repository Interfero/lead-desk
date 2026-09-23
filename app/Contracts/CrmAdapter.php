<?php

namespace App\Contracts;

use Illuminate\Http\UploadedFile;

interface CrmAdapter
{
    public function authenticate(): void;

    /** @return list<array<string, mixed>> */
    public function fetchOrders(array $filters = []): array;

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>|null
     */
    public function fetchOrder(string $externalId, array $options = []): ?array;

    /** @param array<string, mixed> $data */
    public function updateOrder(string $externalId, array $data): void;

    /**
     * @param  array<string, mixed>  $documents
     * @return array<string, mixed>|null  расчётка (для КП) или null
     */
    public function closeOrder(string $externalId, array $documents = []): ?array;

    /** @return array<string, string> */
    public function getStatuses(): array;

    /**
     * @return array{id:string,name:string,category:string}
     */
    public function uploadDocument(string $externalId, string $category, UploadedFile $file): array;

    public function deleteDocument(string $externalId, string $documentId): void;

    /** Бинарный контент документа для превью (crm1). */
    public function downloadDocument(string $externalId, string $documentId): ?array;
}
