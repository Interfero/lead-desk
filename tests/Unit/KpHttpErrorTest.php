<?php

namespace Tests\Unit;

use App\Support\KpHttpError;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class KpHttpErrorTest extends TestCase
{
    public function test_ssl_timeout_is_russian_without_curl_url(): void
    {
        $msg = KpHttpError::message(
            'cURL error 28: SSL connection timeout (see https://curl.se/libcurl/c/libcurl-errors.html) for https://kp-lead-centre.ru/admin/login'
        );

        $this->assertStringContainsString('КП временно не отвечает', $msg);
        $this->assertStringNotContainsString('cURL', $msg);
        $this->assertStringNotContainsString('curl.se', $msg);
        $this->assertStringNotContainsString('kp-lead-centre.ru', $msg);
        $this->assertTrue(KpHttpError::isTransient($msg) || KpHttpError::isTransient(
            'cURL error 28: SSL connection timeout'
        ));
    }

    public function test_keeps_russian_adapter_messages(): void
    {
        $msg = KpHttpError::message(new RuntimeException('Не заданы логин/пароль kp-lead-centre для филиала'));
        $this->assertSame('Не заданы логин/пароль kp-lead-centre для филиала', $msg);
    }

    public function test_generic_english_becomes_safe_fallback(): void
    {
        $msg = KpHttpError::message('Something weird happened');
        $this->assertSame('Ошибка связи с КП. Попробуйте позже.', $msg);
    }
}
