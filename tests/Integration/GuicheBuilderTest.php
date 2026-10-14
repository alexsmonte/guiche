<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\GuicheBuilder;
use Asmpkg\Guiche\Plugin\ToolRegistration;
use Asmpkg\Guiche\Tests\Fixtures\FakePresenter;
use Asmpkg\Guiche\Tests\Fixtures\FakeStateManager;
use Asmpkg\Guiche\Tests\Fixtures\FakeTool;
use Asmpkg\Guiche\Tests\Fixtures\InMemoryContextStore;
use Asmpkg\Guiche\ToolPolicy;
use LogicException;
use PHPUnit\Framework\TestCase;

final class GuicheBuilderTest extends TestCase
{
    public function testBuildRequiresContextStore(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ContextStore');

        (new GuicheBuilder())->build();
    }

    public function testBuildComposesToolPresenterStatePolicyAndSnapshot(): void
    {
        $tool = new FakeTool(
            'RuntimePing',
            [
                'success' => true,
                'data' => ['value' => 'test'],
                'meta' => ['internal' => 'secret', 'public' => 'visible'],
            ]
        );
        $presenter = new FakePresenter('APRESENTADO: test');
        $stateManager = new FakeStateManager();
        $policy = new ToolPolicy(bootstrapTools: ['RuntimePing']);

        $builder = (new GuicheBuilder())
            ->withContextStore(new InMemoryContextStore())
            ->withInstructions('BASE')
            ->registerTool(
                new ToolRegistration(
                    $tool,
                    ['operation' => 'read']
                )
            )
            ->registerPresenter('RuntimePing', $presenter)
            ->useStateManager($stateManager)
            ->useToolPolicy($policy)
            ->addInstructionFragment('test.fragment', 'FRAGMENT')
            ->registerPolicy('test.policy', ['mode' => 'declarative'])
            ->registerResultProjection(
                'RuntimePing',
                ['exclude_paths' => ['meta.internal']]
            );

        $guiche = $builder->build();

        self::assertInstanceOf(Guiche::class, $guiche);
        self::assertSame(['RuntimePing'], array_column($guiche->tools(), 'name'));

        $result = $guiche->executeTool('RuntimePing', []);

        self::assertSame('test', $result['data']['value']);
        self::assertSame(
            'APRESENTADO: test',
            $result['_guiche']['presentation_message']
        );
        self::assertSame($stateManager, $builder->stateManager());
        self::assertSame($policy, $builder->toolPolicy());
        self::assertSame(
            ['mode' => 'declarative'],
            $builder->policies()['test.policy']
        );

        $builder->registerTool(new FakeTool('LateTool'));

        self::assertNotContains(
            'LateTool',
            array_column($guiche->tools(), 'name')
        );
    }

    public function testRejectsSecondStateManagerAndSecondToolPolicy(): void
    {
        $builder = new GuicheBuilder();
        $builder->useStateManager(new FakeStateManager());

        try {
            $builder->useStateManager(new FakeStateManager());
            self::fail('Second StateManager should be rejected.');
        } catch (LogicException) {
            self::assertTrue(true);
        }

        $builder->useToolPolicy(new ToolPolicy());

        $this->expectException(LogicException::class);
        $builder->useToolPolicy(new ToolPolicy());
    }
}
