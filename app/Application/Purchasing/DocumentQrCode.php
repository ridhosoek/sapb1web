<?php

namespace App\Application\Purchasing;

use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\SvgWriter;
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

        $qrCode = new QrCode(
            data: $verificationUrl,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 360,
            margin: 24,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        );
        $writer = new SvgWriter;
        $svg = $writer->write($qrCode, options: [
            SvgWriter::WRITER_OPTION_COMPACT => true,
            SvgWriter::WRITER_OPTION_EXCLUDE_XML_DECLARATION => true,
        ])->getString();
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
