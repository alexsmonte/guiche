<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class LegacyAcceptanceAvailabilityTest extends TestCase
{
    public function testLegacyV051AcceptanceIsStillPresentDuringMigration(): void
    {
        self::assertFileExists(dirname(__DIR__, 2) . '/bin/test-v051-acceptance.php');
    }
}
