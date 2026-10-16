<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Unit;

use Asmpkg\Guiche\Prompt\PromptContext;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PromptContextTest extends TestCase
{
    public function testRejectsEmptySessionId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PromptContext('   ');
    }

    public function testExposesSessionTenantAndTrustedValues(): void
    {
        $context = new PromptContext(
            'chatwoot:101:9001',
            [
                'tenant_id' => 142,
                'provider' => 'chatwoot',
                'profile' => 'meg',
            ]
        );

        self::assertSame(
            'chatwoot:101:9001',
            $context->sessionId()
        );
        self::assertSame(142, $context->tenantId());
        self::assertTrue($context->has('provider'));
        self::assertSame('chatwoot', $context->get('provider'));
        self::assertSame('meg', $context->get('profile'));
        self::assertSame(
            'fallback',
            $context->get('missing', 'fallback')
        );
    }

    public function testNormalizesStringTenantId(): void
    {
        self::assertSame(
            'tenant-142',
            (new PromptContext(
                'session-1',
                ['tenant_id' => ' tenant-142 ']
            ))->tenantId()
        );

        self::assertNull(
            (new PromptContext(
                'session-2',
                ['tenant_id' => '   ']
            ))->tenantId()
        );
    }

    public function testAllReturnsOriginalSnapshot(): void
    {
        $values = [
            'tenant_id' => 142,
            'assistant' => ['name' => 'Meg'],
        ];

        $context = new PromptContext('session-3', $values);

        self::assertSame($values, $context->all());
    }
}
