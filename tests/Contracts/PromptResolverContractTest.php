<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Contracts;

use Asmpkg\Guiche\Contracts\PromptResolverInterface;
use Asmpkg\Guiche\Prompt\PromptContext;
use PHPUnit\Framework\TestCase;

final class PromptResolverContractTest extends TestCase
{
    public function testResolverReceivesPromptContext(): void
    {
        $resolver = new class implements PromptResolverInterface {
            public ?PromptContext $received = null;

            public function resolve(PromptContext $context): string
            {
                $this->received = $context;

                return 'prompt-tenant-' . (string)$context->tenantId();
            }
        };

        $context = new PromptContext(
            'session-tenant-142',
            ['tenant_id' => 142]
        );

        self::assertSame(
            'prompt-tenant-142',
            $resolver->resolve($context)
        );
        self::assertSame($context, $resolver->received);
    }

    public function testContractSupportsResolverWithoutTenant(): void
    {
        $resolver = new class implements PromptResolverInterface {
            public function resolve(PromptContext $context): string
            {
                return $context->tenantId() === null
                    ? 'prompt-default'
                    : 'prompt-tenant';
            }
        };

        self::assertSame(
            'prompt-default',
            $resolver->resolve(
                new PromptContext('session-default')
            )
        );
    }
}
