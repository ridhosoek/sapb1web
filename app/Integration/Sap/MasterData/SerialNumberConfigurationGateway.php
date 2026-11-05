<?php

namespace App\Integration\Sap\MasterData;

use App\Integration\Sap\SapCompanyContext;
use App\Integration\Sap\SapServiceLayerClient;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;
use UnexpectedValueException;

class SerialNumberConfigurationGateway
{
    public const ManufacturerSerialNumber = 'manufacturer_serial_number';

    public const InternalSerialNumber = 'internal_serial_number';

    public const LotNumber = 'lot_number';

    public const None = 'none';

    public function __construct(
        private readonly SapServiceLayerClient $client,
        private readonly SapCompanyContext $companies,
    ) {}

    public function uniqueField(): string
    {
        return $this->cache()->remember(
            'sap:serial-number-configuration:'.hash('sha256', $this->companies->current()),
            now()->addSeconds((int) config('sap.serial_numbers.configuration_ttl_seconds', 900)),
            function (): string {
                try {
                    $response = $this->client->request('GET', config('sap.endpoints.company_admin_info'));
                } catch (Throwable) {
                    $response = $this->client->request(
                        'POST',
                        config('sap.endpoints.company_admin_info'),
                        ['json' => []],
                    );
                }
                $adminInfo = $response->json('AdminInfo');
                $configuration = is_array($adminInfo) ? $adminInfo : $response->json();
                $uniqueSerialNumber = is_array($configuration)
                    ? $configuration['UniqueSerialNo'] ?? null
                    : null;

                return match ((string) $uniqueSerialNumber) {
                    'usn_MfrSerialNumber' => self::ManufacturerSerialNumber,
                    'usn_SerialNumber' => self::InternalSerialNumber,
                    'usn_LotNumber' => self::LotNumber,
                    'usn_None' => self::None,
                    default => throw new UnexpectedValueException('SAP Company Service tidak mengembalikan konfigurasi Unique Serial Numbers yang dikenali.'),
                };
            },
        );
    }

    public static function label(string $field): string
    {
        return match ($field) {
            self::ManufacturerSerialNumber => 'Nomor Seri Pabrikan',
            self::InternalSerialNumber => 'Serial Number',
            self::LotNumber => 'Lot Number',
            self::None => 'Salah satu identitas serial',
            default => 'Konfigurasi serial SAP',
        };
    }

    private function cache(): Repository
    {
        return Cache::store(config('sap.session.cache_store'));
    }
}
