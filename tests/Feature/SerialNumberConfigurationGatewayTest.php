<?php

namespace Tests\Feature;

use App\Integration\Sap\MasterData\SerialNumberConfigurationGateway;
use App\Integration\Sap\SapCompanyContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SerialNumberConfigurationGatewayTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sap.base_url', 'https://sap.test/b1s/v2');
        config()->set('sap.company_db', 'TEST_DB');
        config()->set('sap.company_databases', ['TEST_DB']);
        config()->set('sap.username', 'manager');
        config()->set('sap.password', 'secret');
        config()->set('sap.session.cache_store', 'array');
    }

    #[DataProvider('uniqueSerialNumberSettings')]
    public function test_maps_sap_unique_serial_number_configuration(string $sapValue, string $expectedField): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($sapValue) {
            if (str_ends_with($request->url(), '/Login')) {
                return Http::response(['SessionId' => 'serial-config-session']);
            }

            if ($request->method() === 'GET' && str_ends_with($request->url(), '/CompanyService_GetAdminInfo')) {
                return Http::response(['AdminInfo' => ['UniqueSerialNo' => $sapValue]]);
            }

            return Http::response([], 404);
        });
        app(SapCompanyContext::class)->select('TEST_DB');

        $actualField = app(SerialNumberConfigurationGateway::class)->uniqueField();

        $this->assertSame($expectedField, $actualField);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_ends_with($request->url(), '/CompanyService_GetAdminInfo'));
    }

    /** @return array<string, array{string, string}> */
    public static function uniqueSerialNumberSettings(): array
    {
        return [
            'manufacturer serial number' => ['usn_MfrSerialNumber', SerialNumberConfigurationGateway::ManufacturerSerialNumber],
            'serial number' => ['usn_SerialNumber', SerialNumberConfigurationGateway::InternalSerialNumber],
            'lot number' => ['usn_LotNumber', SerialNumberConfigurationGateway::LotNumber],
            'none' => ['usn_None', SerialNumberConfigurationGateway::None],
        ];
    }
}
