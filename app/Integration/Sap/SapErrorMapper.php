<?php

namespace App\Integration\Sap;

use App\Integration\Sap\Exceptions\SapBusinessException;
use Illuminate\Http\Client\Response;

class SapErrorMapper
{
    public function fromResponse(Response $response): SapBusinessException
    {
        $code = data_get($response->json(), 'error.code');
        $technical = (string) data_get($response->json(), 'error.message.value', 'SAP request failed.');
        $normalizedTechnical = strtolower($technical);
        $message = match (true) {
            str_contains($normalizedTechnical, 'invalid property') => 'Konfigurasi field pada database SAP belum sesuai. Hubungi administrator dan lihat Integration Monitor.',
            str_contains($normalizedTechnical, 'cost center'), str_contains($normalizedTechnical, 'distribution rule') => 'Cost center Department, Pool, atau Nopol tidak valid pada database SAP yang dipilih.',
            str_contains($normalizedTechnical, 'quantity') && str_contains($normalizedTechnical, 'open') => 'Quantity melebihi sisa dokumen SAP yang masih terbuka.',
            str_contains($normalizedTechnical, 'stock') => 'Barang tidak memiliki stok yang cukup.',
            str_contains($normalizedTechnical, 'closed') => 'Dokumen SAP telah ditutup.',
            $response->status() >= 500 => 'SAP sedang tidak dapat dihubungi. Silakan coba kembali.',
            default => 'Transaksi ditolak oleh SAP. Periksa data lalu coba kembali.',
        };

        return new SapBusinessException(
            $message,
            is_scalar($code) ? (string) $code : null,
            $response->status(),
            $technical,
        );
    }
}
