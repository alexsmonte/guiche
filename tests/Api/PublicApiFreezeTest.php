<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PublicApiFreezeTest extends TestCase
{
    public function testPublicApiMatchesFrozenV1Baseline(): void
    {
        require_once dirname(__DIR__, 2) . '/tools/api-freeze-lib.php';

        guicheApiLoadAutoload();
        $map = guicheApiLoadStabilityMap();

        self::assertSame([], guicheApiValidateStabilityMap($map), 'Todo símbolo de src/ deve possuir estabilidade explícita.');

        $baseline = guicheApiReadBaseline();
        $current = guicheApiCurrentSnapshot($map);

        self::assertSame([], guicheApiCompare($baseline, $current), 'A API pública divergiu do baseline congelado da v1.');
        self::assertSame($baseline['public_api_sha256'] ?? null, $current['public_api_sha256'] ?? null);
    }
}
