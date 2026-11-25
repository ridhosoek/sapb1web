<?php

namespace Tests\Unit;

use App\Integration\Sap\MasterData\GoodsIssueTypeGateway;
use App\Integration\Sap\SapCompanyContext;
use App\Integration\Sap\SapServiceLayerClient;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Mockery;
use Tests\TestCase;
use UnexpectedValueException;

class GoodsIssueTypeGatewayTest extends TestCase
{
    public function test_reads_issue_type_accounts_from_the_selected_company(): void
    {
        config()->set('sap.company_db', 'IA');
        config()->set('sap.company_databases', ['IA', 'IAL_DEV']);
        $this->mock(SapServiceLayerClient::class, fn ($mock) => $mock
            ->shouldReceive('request')
            ->twice()
            ->with('GET', '/U_VCGI', Mockery::on(fn (array $options): bool => $options['query']['$select'] === 'Code,Name,U_COA'))
            ->andReturn(
                $this->sapResponse(['value' => [['Code' => 'BBM-OU', 'Name' => 'BBM IA', 'U_COA' => '5114.3002']]]),
                $this->sapResponse(['value' => [['Code' => 'BBM-OU', 'Name' => 'BBM IAL', 'U_COA' => '6123.4567']]]),
            ));

        $gateway = app(GoodsIssueTypeGateway::class);
        $this->assertSame('5114.3002', $gateway->forCompany('IA')['BBM-OU']['account_code']);
        $this->assertSame('6123.4567', $gateway->forCompany('IAL_DEV')['BBM-OU']['account_code']);
        $this->assertSame('IAL_DEV', app(SapCompanyContext::class)->current());
    }

    public function test_rejects_an_invalid_sap_collection(): void
    {
        config()->set('sap.company_db', 'IA');
        config()->set('sap.company_databases', ['IA']);
        $this->mock(SapServiceLayerClient::class, fn ($mock) => $mock
            ->shouldReceive('request')
            ->once()
            ->andReturn($this->sapResponse(['unexpected' => []])));

        $this->expectException(UnexpectedValueException::class);
        app(GoodsIssueTypeGateway::class)->forCompany('IA');
    }

    /** @param array<string, mixed> $payload */
    private function sapResponse(array $payload): Response
    {
        return new Response(new Psr7Response(200, [], json_encode($payload, JSON_THROW_ON_ERROR)));
    }
}
