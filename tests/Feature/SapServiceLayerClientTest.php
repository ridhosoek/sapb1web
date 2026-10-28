<?php

namespace Tests\Feature;

use App\Integration\Sap\Exceptions\SapBusinessException;
use App\Integration\Sap\SapCompanyContext;
use App\Integration\Sap\SapServiceLayerClient;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SapServiceLayerClientTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_client_reuses_server_side_session_and_does_not_expose_credentials(): void
    {
        config()->set('sap.base_url', 'https://sap.test/b1s/v2');
        config()->set('sap.company_db', 'TEST_DB');
        config()->set('sap.username', 'manager');
        config()->set('sap.password', 'top-secret');
        config()->set('sap.session.cache_store', 'array');

        Http::preventStrayRequests();
        Http::fake([
            'https://sap.test/b1s/v2/Login' => Http::response(['SessionId' => 'session-123'], 200, ['Set-Cookie' => 'ROUTEID=.node1; Path=/']),
            'https://sap.test/b1s/v2/PurchaseOrders(42)' => Http::response(['DocEntry' => 42, 'DocNum' => 26000812]),
        ]);

        $client = $this->app->make(SapServiceLayerClient::class);
        $first = $client->request('GET', '/PurchaseOrders(42)')->json();
        $second = $client->request('GET', '/PurchaseOrders(42)')->json();

        $this->assertSame(42, $first['DocEntry']);
        $this->assertSame(26000812, $second['DocNum']);
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->url() === 'https://sap.test/b1s/v2/Login'
            && $request['Password'] === 'top-secret');
        Http::assertSent(fn ($request) => $request->url() === 'https://sap.test/b1s/v2/PurchaseOrders(42)'
            && str_contains($request->header('Cookie')[0] ?? '', 'B1SESSION=session-123'));
        $this->assertDatabaseMissing('sap_api_logs', ['error_message' => 'top-secret']);
    }

    public function test_client_keeps_separate_service_layer_sessions_for_each_company_database(): void
    {
        config()->set('sap.base_url', 'https://sap.test/b1s/v2');
        config()->set('sap.username', 'manager');
        config()->set('sap.password', 'top-secret');
        config()->set('sap.session.cache_store', 'array');

        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if ($request->url() === 'https://sap.test/b1s/v2/Login') {
                return Http::response([
                    'SessionId' => 'session-'.$request['CompanyDB'],
                ]);
            }

            return Http::response(['DocEntry' => 42]);
        });

        $companies = $this->app->make(SapCompanyContext::class);
        $client = $this->app->make(SapServiceLayerClient::class);

        $client->request('GET', '/PurchaseOrders(42)');
        $companies->select('SECOND_DB');
        $client->request('GET', '/PurchaseOrders(42)');

        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sap.test/b1s/v2/Login'
            && $request['CompanyDB'] === 'TEST_DB');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sap.test/b1s/v2/Login'
            && $request['CompanyDB'] === 'SECOND_DB');
        $this->assertDatabaseHas('sap_api_logs', ['company_db' => 'TEST_DB', 'result' => 'SUCCESS']);
        $this->assertDatabaseHas('sap_api_logs', ['company_db' => 'SECOND_DB', 'result' => 'SUCCESS']);
    }

    public function test_client_logs_the_original_sap_error_for_administrative_diagnosis(): void
    {
        config()->set('sap.base_url', 'https://sap.test/b1s/v2');
        config()->set('sap.company_db', 'TEST_DB');
        config()->set('sap.username', 'manager');
        config()->set('sap.password', 'top-secret');
        config()->set('sap.session.cache_store', 'array');

        Http::preventStrayRequests();
        Http::fake([
            'https://sap.test/b1s/v2/Login' => Http::response(['SessionId' => 'session-123']),
            'https://sap.test/b1s/v2/PurchaseRequests' => Http::response([
                'error' => [
                    'code' => '-10',
                    'message' => ['value' => 'Invalid property U_WebTxnId'],
                ],
            ], 400),
        ]);

        try {
            $this->app->make(SapServiceLayerClient::class)->request('POST', '/PurchaseRequests', ['json' => []]);
            $this->fail('The SAP business exception was not thrown.');
        } catch (SapBusinessException $exception) {
            $this->assertSame('Invalid property U_WebTxnId', $exception->technicalMessage);
        }

        $this->assertDatabaseHas('sap_api_logs', [
            'company_db' => 'TEST_DB',
            'result' => 'ERROR',
            'error_code' => '-10',
            'error_message' => 'Invalid property U_WebTxnId',
        ]);
    }
}
