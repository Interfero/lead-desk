<?php

namespace App\Support;

use App\Models\DeskOrderCache;

/**
 * Номер клиента не уходит в HTML/JSON ролям без права видеть телефон.
 * Не меняет модель в БД — только копию для ответа клиенту.
 */
class DeskClientPhonePrivacy
{
    /**
     * @param  array<string, mixed>  $user
     * @return array<string, mixed>
     */
    public static function orderForClient(DeskOrderCache $order, array $user): array
    {
        $known = (string) ($order->getAttribute('phone') ?? '');
        $data = $order->toArray();
        unset($data['phone_norm']);

        if (DeskAccess::canSeeClientPhone($user)) {
            return $data;
        }

        unset($data['phone'], $data['phone_norm']);
        foreach (['comments', 'description', 'fraud_note'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = self::redactText($data[$field], $known);
            }
        }
        if (isset($data['client_history']) && is_array($data['client_history'])) {
            $data['client_history'] = self::redactHistory($data['client_history'], $known);
        }

        return $data;
    }

    public static function redactText(?string $text, ?string $knownPhone = null): string
    {
        $text = (string) $text;
        if ($text === '') {
            return '';
        }

        $digits = preg_replace('/\D+/', '', (string) $knownPhone) ?? '';
        if (strlen($digits) >= 10) {
            $last10 = substr($digits, -10);
            $text = preg_replace(
                '/(?:\+7|8|7)?[\s\-\(]*'.preg_quote(substr($last10, 0, 3), '/').'[\)\s\-]*'
                .preg_quote(substr($last10, 3, 3), '/').'[\s\-]*'
                .preg_quote(substr($last10, 6, 2), '/').'[\s\-]*'
                .preg_quote(substr($last10, 8, 2), '/').'/u',
                '',
                $text
            ) ?? $text;
            $text = str_replace([$digits, $last10], '', $text);
        }

        $text = preg_replace('/(?:\+7|8)[\s\-\(]*9\d{2}[\)\s\-]*\d{3}[\s\-]*\d{2}[\s\-]*\d{2}/u', '', $text) ?? $text;
        $text = preg_replace('/(?<!\d)9\d{9}(?!\d)/u', '', $text) ?? $text;
        $text = preg_replace('/[ \t]{2,}/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * @param  list<array<string, mixed>>  $history
     * @return list<array<string, mixed>>
     */
    public static function redactHistory(array $history, ?string $knownPhone = null): array
    {
        foreach ($history as &$row) {
            if (! is_array($row)) {
                continue;
            }
            unset($row['phone'], $row['phone_norm']);
            foreach (['comments', 'description', 'note'] as $field) {
                if (isset($row[$field]) && is_string($row[$field])) {
                    $row[$field] = self::redactText($row[$field], $knownPhone);
                }
            }
        }
        unset($row);

        return $history;
    }
}
