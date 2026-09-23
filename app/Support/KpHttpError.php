<?php

namespace App\Support;

use Throwable;

/**
 * Человекочитаемые ошибки связи с kp-lead-centre (без сырого cURL в UI).
 */
class KpHttpError
{
    public static function message(Throwable|string $error): string
    {
        $raw = is_string($error) ? $error : $error->getMessage();
        $raw = trim($raw);
        if ($raw === '') {
            return 'Ошибка связи с КП. Попробуйте позже.';
        }

        $low = mb_strtolower($raw);

        if (
            str_contains($low, 'curl error 28')
            || str_contains($low, 'ssl connection timeout')
            || str_contains($low, 'connection timeout')
            || str_contains($low, 'operation timed out')
        ) {
            return 'КП временно не отвечает (таймаут связи). Попробуйте через 1–2 минуты. Если квартира уже была показана раньше — обновите страницу.';
        }

        if (
            str_contains($low, 'curl error 7')
            || str_contains($low, 'failed to connect')
            || str_contains($low, 'couldn\'t connect')
            || str_contains($low, 'connection refused')
        ) {
            return 'Нет связи с КП. Попробуйте позже.';
        }

        if (
            str_contains($low, 'curl error 60')
            || str_contains($low, 'ssl certificate')
            || str_contains($low, 'certificate has expired')
            || str_contains($low, 'certificate problem')
        ) {
            return 'Ошибка сертификата КП. Сообщите разработчику.';
        }

        if (
            str_contains($low, 'curl error 35')
            || str_contains($low, 'ssl connect error')
            || str_contains($low, 'ssl handshake')
        ) {
            return 'Не удалось установить защищённое соединение с КП. Попробуйте позже.';
        }

        // Уже русское сообщение адаптера/контроллера — оставляем как есть
        if (preg_match('/[а-яё]/iu', $raw) === 1) {
            // Убрать хвост с URL / curl.se, если вдруг попал
            $clean = preg_replace('/\s*\(see https?:\/\/[^)]+\)\s*/i', '', $raw) ?? $raw;
            $clean = preg_replace('/\s+for https?:\/\/\S+/i', '', $clean) ?? $clean;

            return trim($clean) !== '' ? trim($clean) : 'Ошибка связи с КП. Попробуйте позже.';
        }

        return 'Ошибка связи с КП. Попробуйте позже.';
    }

    public static function isTransient(Throwable|string $error): bool
    {
        $raw = mb_strtolower(is_string($error) ? $error : $error->getMessage());

        return str_contains($raw, 'curl error 28')
            || str_contains($raw, 'ssl connection timeout')
            || str_contains($raw, 'connection timeout')
            || str_contains($raw, 'curl error 7')
            || str_contains($raw, 'failed to connect')
            || str_contains($raw, 'curl error 35');
    }
}
