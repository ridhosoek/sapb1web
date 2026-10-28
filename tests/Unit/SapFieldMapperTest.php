<?php

namespace Tests\Unit;

use App\Integration\Sap\Mapping\SapFieldMapper;
use Tests\TestCase;

class SapFieldMapperTest extends TestCase
{
    public function test_business_fields_are_mapped_from_central_configuration(): void
    {
        config()->set('sap.fields.fpk_number', 'U_TEST_FPK');
        config()->set('sap.fields.comments', 'Comments');

        $payload = (new SapFieldMapper)->map([
            'fpk_number' => '025953',
            'comments' => 'BK8748ES - FPK 025953 - DUMAI',
        ]);

        $this->assertSame([
            'U_TEST_FPK' => '025953',
            'Comments' => 'BK8748ES - FPK 025953 - DUMAI',
        ], $payload);
    }
}
