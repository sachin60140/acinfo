<?php

namespace Tests\Unit;

use App\Support\WhatsApp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The server's WhatsApp number rule, held to the same cases as the browser's.
 *
 * resources/js/whatsapp.test.js reads the same file. A case added there is a
 * case both have to pass.
 */
class WhatsAppTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function cases(): array
    {
        $file = json_decode(file_get_contents(__DIR__.'/../whatsapp-numbers.json'), true);

        $out = [];

        foreach ($file['cases'] as [$written, $expected]) {
            $out[var_export($written, true)] = [$written, $expected];
        }

        return $out;
    }

    #[DataProvider('cases')]
    public function test_a_number_as_written_becomes_the_number_whatsapp_wants(?string $written, ?string $expected): void
    {
        $this->assertSame($expected, WhatsApp::number($written));
    }

    #[DataProvider('cases')]
    public function test_there_is_a_chat_link_only_when_there_is_a_number(?string $written, ?string $expected): void
    {
        $this->assertSame($expected ? 'https://wa.me/'.$expected : null, WhatsApp::url($written));
    }
}
