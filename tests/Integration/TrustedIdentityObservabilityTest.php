<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\AiProviderInterface;
use Asmpkg\Guiche\Contracts\RuntimeEventSinkInterface;
use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\Observability\RuntimeEventFactory;
use Asmpkg\Guiche\Tenant\CallbackTenantResolver;
use Asmpkg\Guiche\ToolRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TrustedIdentityObservabilityTest extends TestCase
{
    private string $directory;
    private ContextStore $store;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . '/guiche-v13-trusted-observability-'
            . bin2hex(random_bytes(8));

        $this->store = new ContextStore(
            $this->directory,
            'America/Manaus'
        );
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->directory);
    }

    public function testSuccessfulBindingEmitsSafeAuditEvents(): void
    {
        $sink = new CollectingTrustedIdentitySink();

        $identity = [
            'provider' => 'chatwoot',
            'account_id' => 'ACCOUNT-SECRET-91',
            'inbox_id' => 'INBOX-SECRET-22',
            'conversation_id' => 'CONVERSATION-SECRET-73',
        ];

        $guiche = new Guiche(
            tools: new ToolRegistry(),
            context: $this->store,
            eventSink: $sink,
            tenantResolver: new CallbackTenantResolver(
                static fn (array $identity): array => [
                    'tenant_id' => 'TENANT-SECRET-142',
                ]
            )
        );

        $context = $guiche->bindIdentity(
            'SESSION-SECRET-900',
            $identity
        );

        self::assertSame(
            'TENANT-SECRET-142',
            $context->tenantId()
        );

        self::assertSame(
            [
                'trusted_identity_binding_started',
                'trusted_identity_bound',
                'trusted_tenant_resolution_succeeded',
            ],
            array_column($sink->events, 'type')
        );

        $serialized = json_encode(
            $sink->events,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        );

        self::assertIsString($serialized);
        self::assertStringNotContainsString(
            'ACCOUNT-SECRET-91',
            $serialized
        );
        self::assertStringNotContainsString(
            'INBOX-SECRET-22',
            $serialized
        );
        self::assertStringNotContainsString(
            'CONVERSATION-SECRET-73',
            $serialized
        );
        self::assertStringNotContainsString(
            'TENANT-SECRET-142',
            $serialized
        );
        self::assertStringNotContainsString(
            'SESSION-SECRET-900',
            $serialized
        );

        self::assertNotSame(
            '',
            (string) (
                $sink->events[0]
                    ['attributes']
                    ['identity_fingerprint']
                ?? ''
            )
        );

        self::assertNotSame(
            '',
            (string) (
                $sink->events[2]
                    ['attributes']
                    ['tenant_fingerprint']
                ?? ''
            )
        );
    }

    public function testIdempotentRebindingIsAuditable(): void
    {
        $sink = new CollectingTrustedIdentitySink();

        $guiche = new Guiche(
            tools: new ToolRegistry(),
            context: $this->store,
            eventSink: $sink
        );

        $identity = [
            'provider' => 'chatwoot',
            'account_id' => 10,
            'inbox_id' => 20,
            'conversation_id' => 30,
        ];

        $guiche->bindIdentity(
            'audit-idempotent',
            $identity
        );

        $guiche->bindIdentity(
            'audit-idempotent',
            [
                'conversation_id' => 30,
                'inbox_id' => 20,
                'account_id' => 10,
                'provider' => 'chatwoot',
            ]
        );

        $boundEvents = array_values(
            array_filter(
                $sink->events,
                static fn (array $event): bool =>
                    ($event['type'] ?? null)
                    === 'trusted_identity_bound'
            )
        );

        self::assertCount(2, $boundEvents);
        self::assertFalse(
            $boundEvents[0]['attributes']['idempotent']
        );
        self::assertTrue(
            $boundEvents[1]['attributes']['idempotent']
        );

        self::assertSame(
            $boundEvents[0]
                ['attributes']
                ['identity_fingerprint'],
            $boundEvents[1]
                ['attributes']
                ['identity_fingerprint']
        );
    }

    public function testIdentitySwapAttemptIsAuditedAndRejected(): void
    {
        $sink = new CollectingTrustedIdentitySink();

        $guiche = new Guiche(
            tools: new ToolRegistry(),
            context: $this->store,
            eventSink: $sink
        );

        $guiche->bindIdentity(
            'audit-conflict',
            [
                'provider' => 'chatwoot',
                'account_id' => 1,
                'inbox_id' => 2,
                'conversation_id' => 3,
            ]
        );

        try {
            $guiche->bindIdentity(
                'audit-conflict',
                [
                    'provider' => 'chatwoot',
                    'account_id' => 1,
                    'inbox_id' => 999,
                    'conversation_id' => 3,
                ]
            );

            self::fail(
                'Troca de identidade deveria falhar.'
            );
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'já está vinculado',
                $exception->getMessage()
            );
        }

        $failed = array_values(
            array_filter(
                $sink->events,
                static fn (array $event): bool =>
                    ($event['type'] ?? null)
                    === 'trusted_identity_binding_failed'
            )
        );

        self::assertCount(1, $failed);
        self::assertSame(
            'identity_conflict',
            $failed[0]['attributes']['reason']
        );

        self::assertSame(
            2,
            $this->store
                ->load('audit-conflict')
                ['execution_context']
                ['identity']
                ['inbox_id']
        );
    }

    public function testTenantResolverFailureIsAuditedWithoutSecretMessage(): void
    {
        $sink = new CollectingTrustedIdentitySink();

        $guiche = new Guiche(
            tools: new ToolRegistry(),
            context: $this->store,
            eventSink: $sink,
            tenantResolver: new CallbackTenantResolver(
                static function (array $identity): array {
                    throw new RuntimeException(
                        'PASSWORD=resolver-super-secret'
                    );
                }
            )
        );

        try {
            $guiche->bindIdentity(
                'audit-resolver-failure',
                [
                    'provider' => 'chatwoot',
                    'account_id' => 10,
                    'inbox_id' => 20,
                    'conversation_id' => 30,
                ]
            );

            self::fail(
                'Falha do resolver deveria continuar explícita.'
            );
        } catch (RuntimeException $exception) {
            self::assertStringContainsString(
                'Falha ao resolver tenant',
                $exception->getMessage()
            );
        }

        $failed = array_values(
            array_filter(
                $sink->events,
                static fn (array $event): bool =>
                    ($event['type'] ?? null)
                    === 'trusted_tenant_resolution_failed'
            )
        );

        self::assertCount(1, $failed);

        $serialized = json_encode(
            $failed,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        );

        self::assertIsString($serialized);
        self::assertStringNotContainsString(
            'resolver-super-secret',
            $serialized
        );

        $stored = $this->store
            ->load('audit-resolver-failure')
            ['execution_context'];

        self::assertArrayHasKey('identity', $stored);
        self::assertArrayNotHasKey('tenant_id', $stored);
    }

    public function testObservabilitySinkFailureDoesNotBreakBinding(): void
    {
        $guiche = new Guiche(
            tools: new ToolRegistry(),
            context: $this->store,
            eventSink: new ThrowingTrustedIdentitySink(),
            tenantResolver: new CallbackTenantResolver(
                static fn (array $identity): array => [
                    'tenant_id' => 700,
                ]
            )
        );

        $context = $guiche->bindIdentity(
            'audit-sink-failure',
            [
                'provider' => 'chatwoot',
                'account_id' => 7,
                'inbox_id' => 8,
                'conversation_id' => 9,
            ]
        );

        self::assertSame(700, $context->tenantId());
        self::assertSame(
            700,
            $this->store
                ->load('audit-sink-failure')
                ['execution_context']
                ['tenant_id']
        );
    }

    public function testRuntimeEventFactoryStripsRawTrustedContextRecursively(): void
    {
        $event = RuntimeEventFactory::fromLegacy(
            'factory-secret-session',
            'factory-test',
            [
                'type' => 'trusted_context_test',
                'identity' => [
                    'conversation_id' =>
                        'RAW-IDENTITY-SECRET',
                ],
                'tenant_id' => 'RAW-TENANT-SECRET',
                'execution_context' => [
                    'identity' => [
                        'account_id' =>
                            'RAW-NESTED-SECRET',
                    ],
                    'tenant_id' =>
                        'RAW-NESTED-TENANT',
                ],
                'metadata' => [
                    'identity' => [
                        'inbox_id' =>
                            'RAW-META-IDENTITY',
                    ],
                    'tenant_id' =>
                        'RAW-META-TENANT',
                    'safe_value' => 'ok',
                ],
                'identity_fingerprint' =>
                    'safe-identity-hash',
                'tenant_fingerprint' =>
                    'safe-tenant-hash',
            ]
        );

        $serialized = json_encode(
            $event,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        );

        self::assertIsString($serialized);

        foreach (
            [
                'RAW-IDENTITY-SECRET',
                'RAW-TENANT-SECRET',
                'RAW-NESTED-SECRET',
                'RAW-NESTED-TENANT',
                'RAW-META-IDENTITY',
                'RAW-META-TENANT',
                'factory-secret-session',
            ] as $secret
        ) {
            self::assertStringNotContainsString(
                $secret,
                $serialized
            );
        }

        self::assertSame(
            'ok',
            $event['attributes']
                ['metadata']
                ['safe_value']
        );

        self::assertSame(
            'safe-identity-hash',
            $event['attributes']
                ['identity_fingerprint']
        );

        self::assertSame(
            'safe-tenant-hash',
            $event['attributes']
                ['tenant_fingerprint']
        );
    }

    public function testTrustedExecutionContextIsNotExposedToAiPrompt(): void
    {
        $ai = new CapturingTrustedIdentityAiProvider();

        $guiche = new Guiche(
            tools: new ToolRegistry(),
            context: $this->store,
            ai: $ai,
            tenantResolver: new CallbackTenantResolver(
                static fn (array $identity): array => [
                    'tenant_id' => 'AI-HIDDEN-TENANT',
                ]
            )
        );

        $guiche->bindIdentity(
            'ai-hidden-session',
            [
                'provider' => 'chatwoot',
                'account_id' => 'AI-HIDDEN-ACCOUNT',
                'inbox_id' => 'AI-HIDDEN-INBOX',
                'conversation_id' =>
                    'AI-HIDDEN-CONVERSATION',
            ]
        );

        $result = $guiche->handle(
            'ai-hidden-session',
            'Olá'
        );

        self::assertSame('ok', $result['message']);

        self::assertStringNotContainsString(
            'AI-HIDDEN-ACCOUNT',
            $ai->instructions
        );
        self::assertStringNotContainsString(
            'AI-HIDDEN-INBOX',
            $ai->instructions
        );
        self::assertStringNotContainsString(
            'AI-HIDDEN-CONVERSATION',
            $ai->instructions
        );
        self::assertStringNotContainsString(
            'AI-HIDDEN-TENANT',
            $ai->instructions
        );
        self::assertStringNotContainsString(
            'execution_context',
            $ai->instructions
        );
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $target = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($target)) {
                $this->removeTree($target);
                continue;
            }

            @unlink($target);
        }

        @rmdir($path);
    }
}

final class CollectingTrustedIdentitySink implements RuntimeEventSinkInterface
{
    /** @var array<int, array<mixed>> */
    public array $events = [];

    /** @param array<mixed> $event */
    public function record(array $event): void
    {
        $this->events[] = $event;
    }
}

final class ThrowingTrustedIdentitySink implements RuntimeEventSinkInterface
{
    /** @param array<mixed> $event */
    public function record(array $event): void
    {
        throw new RuntimeException(
            'sink-secret-failure'
        );
    }
}

final class CapturingTrustedIdentityAiProvider implements AiProviderInterface
{
    public string $instructions = '';

    /**
     * @param array<mixed> $messages
     * @param array<mixed> $tools
     * @return array<mixed>
     */
    public function respond(
        string $instructions,
        array $messages,
        array $tools = []
    ): array {
        $this->instructions = $instructions;

        return [
            'id' => 'trusted-observability-ai-1',
            'text' => 'ok',
            'tool_calls' => [],
            'usage' => [],
        ];
    }

    /**
     * @param array<mixed> $toolOutputs
     * @param array<mixed> $tools
     * @return array<mixed>
     */
    public function continueWithToolOutputs(
        string $previousResponseId,
        string $instructions,
        array $toolOutputs,
        array $tools = []
    ): array {
        $this->instructions = $instructions;

        return [
            'id' => 'trusted-observability-ai-2',
            'text' => 'ok',
            'tool_calls' => [],
            'usage' => [],
        ];
    }
}
