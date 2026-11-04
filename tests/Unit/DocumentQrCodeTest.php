<?php

namespace Tests\Unit;

use App\Application\Purchasing\DocumentQrCode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DocumentQrCodeTest extends TestCase
{
    public function test_it_renders_a_signed_verification_link_with_balanced_error_correction(): void
    {
        $svg = (new DocumentQrCode)->renderLink(
            'https://portal.example.test/v/42/approved?signature=valid-signature',
            'APPROVED',
            42,
        )->toHtml();

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('class="verification-qr-svg"', $svg);
        $this->assertStringContainsString('data-document-qr="42"', $svg);
        $this->assertStringContainsString('data-authorization-status="APPROVED"', $svg);
        $this->assertStringContainsString('data-ecc="M"', $svg);
        $this->assertStringContainsString('aria-label="QR verifikasi approved dokumen Portal #42"', $svg);
        $this->assertStringContainsString('viewBox="0 0 408 408"', $svg);
        $this->assertStringContainsString('<rect x="0" y="0" width="408" height="408" fill="#ffffff"', $svg);
        $this->assertStringContainsString('<path', $svg);
        $this->assertStringNotContainsString('<?xml', $svg);
    }

    public function test_different_verification_links_produce_different_qr_codes(): void
    {
        $first = (new DocumentQrCode)->renderLink(
            'https://portal.example.test/v/42/checked?signature=checked-signature',
            'CHECKED',
            42,
        )->toHtml();
        $second = (new DocumentQrCode)->renderLink(
            'https://portal.example.test/v/42/approved?signature=approved-signature',
            'APPROVED',
            42,
        )->toHtml();

        $this->assertNotSame($first, $second);
    }

    public function test_it_rejects_an_invalid_verification_link(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new DocumentQrCode)->renderLink(
            'not-a-url',
            'APPROVED',
            42,
        );
    }
}
