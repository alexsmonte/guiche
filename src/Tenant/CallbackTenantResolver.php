<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tenant;

use Asmpkg\Guiche\Contracts\TenantResolverInterface;
use Closure;

final class CallbackTenantResolver implements TenantResolverInterface
{
    /**
     * @var Closure(array<mixed>): array<mixed>
     */
    private Closure $resolver;

    /**
     * @param callable(array<mixed>): array<mixed> $resolver
     */
    public function __construct(callable $resolver)
    {
        $this->resolver = Closure::fromCallable($resolver);
    }

    /**
     * @param array<mixed> $identity
     * @return array<mixed>
     */
    public function resolve(array $identity): array
    {
        return ($this->resolver)($identity);
    }
}
