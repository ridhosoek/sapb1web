<?php

namespace App\Application\Purchasing;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;

final class DocumentQrCode
{
    public function renderLink(string $verificationUrl, string $authorizationStatus, int $documentId): HtmlString
    {
        if (
            ! filter_var($verificationUrl, FILTER_VALIDATE_URL)
            || ! in_array($authorizationStatus, ['CHECKED', 'APPROVED'], true)
            || $documentId < 1
        ) {
            throw new InvalidArgumentException('A valid verification URL, status, and document ID are required.');
        }

        $writer = new Writer(new ImageRenderer(
            new RendererStyle(size: 360, margin: 26),
            new SvgImageBackEnd,
        ));
        $svg = preg_replace(
            '/<\?xml[^>]+>\s*/',
            '',
            $writer->writeString($verificationUrl, ecLevel: ErrorCorrectionLevel::M()),
            1,
        ) ?? '';
        $svg = preg_replace(
            '/<svg\b/',
            sprintf(
                '<svg class="verification-qr-svg" data-document-qr="%d" data-authorization-status="%s" data-ecc="M" role="img" aria-label="QR verifikasi %s dokumen Portal #%d"',
                $documentId,
                $authorizationStatus,
                strtolower($authorizationStatus),
                $documentId,
            ),
            $svg,
            1,
        ) ?? '';

        return new HtmlString($svg);
    }
}
