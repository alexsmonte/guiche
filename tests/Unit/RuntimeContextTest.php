<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Unit;

use Asmpkg\Guiche\RuntimeContext;
use PHPUnit\Framework\TestCase;

final class RuntimeContextTest extends TestCase
{
    public function testCurrentProvidesStableSchemaForConfiguredTimezone(): void
    {
        $current = (new RuntimeContext('America/Manaus'))->current();

        self::assertSame('America/Manaus', $current['timezone']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $current['data_atual']);
        self::assertMatchesRegularExpression('/^\d{2}\/\d{2}\/\d{4}$/', $current['data_atual_br']);
        self::assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', $current['hora_atual']);
        self::assertIsInt($current['ano_atual']);
        self::assertIsInt($current['mes_atual']);
        self::assertIsInt($current['dia_atual']);
        self::assertContains(
            $current['dia_semana'],
            [
                'segunda-feira',
                'terça-feira',
                'quarta-feira',
                'quinta-feira',
                'sexta-feira',
                'sábado',
                'domingo',
            ]
        );
    }

    public function testDifferentTimezoneIsReflectedInPayload(): void
    {
        self::assertSame(
            'UTC',
            (new RuntimeContext('UTC'))->current()['timezone']
        );
    }
}
