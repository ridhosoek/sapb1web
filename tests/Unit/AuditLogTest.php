<?php

namespace Tests\Unit;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use LogicException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_audit_log_is_append_only(): void
    {
        $entry = AuditLog::query()->create(['event' => 'TEST_EVENT', 'created_at' => now()]);

        $this->expectException(LogicException::class);
        $entry->update(['event' => 'CHANGED']);
    }
}
