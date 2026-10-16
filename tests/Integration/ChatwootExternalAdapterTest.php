<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Tests\Integration;

use Asmpkg\Guiche\ContextStore;
use Asmpkg\Guiche\Contracts\AiProviderInterface;
use Asmpkg\Guiche\Guiche;
use Asmpkg\Guiche\Tenant\CallbackTenantResolver;
use Asmpkg\Guiche\ToolRegistry;
use GuicheExample\Chatwoot\ChatwootWebhookAdapter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2)
    . '/examples/chatwoot/src/ChatwootWebhookAdapter.php';

final class ChatwootExternalAdapterTest extends TestCase
{
    private string $directory;
    private ContextStore $store;
    private ChatwootWebhookAdapter $adapter;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . '/guiche-v13-chatwoot-'
            . bin2hex(random_bytes(8));

        $this->store = new ContextStore(
            $this->directory,
            'America/Manaus'
        );

        $this->adapter = new ChatwootWebhookAdapter();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->directory);
    }

    public function testTrustedPayloadProducesExpectedIdentity(): void
    {
        $identity = $this->adapter
            ->identityFromTrustedPayload(
                $this->payload()
            );

        self::assertSame(
            [
                'provider' => 'chatwoot',
                'account_id' => 10,
                'inbox_id' => 20,
                'conversation_id' => 30,
            ],
            $identity
        );
    }

    public function testSessionIdIsStableAndOpaquePerConversation(): void
    {
        $identity = $this->adapter
            ->identityFromTrustedPayload(
                $this->payload()
            );

        $first = $this->adapter->sessionId(
            $identity
        );

        $second = $this->adapter->sessionId(
            $identity
        );

        self::assertSame($first, $second);
        self::assertStringStartsWith(
            'chatwoot-',
            $first
        );

        self::assertStringNotContainsString(
            ':10:',
            $first
        );
        self::assertStringNotContainsString(
            ':20:',
            $first
        );
        self::assertStringNotContainsString(
            ':30',
            $first
        );

        self::assertSame(
            73,
            strlen($first)
        );
    }

    public function testDifferentMessagesInSameConversationReuseIdentityAndSession(): void
    {
        $firstPayload = $this->payload();
        $secondPayload = $this->payload();

        $secondPayload['id'] = 999999;
        $secondPayload['content'] = 'Segunda mensagem';

        $firstIdentity = $this->adapter
            ->identityFromTrustedPayload(
                $firstPayload
            );

        $secondIdentity = $this->adapter
            ->identityFromTrustedPayload(
                $secondPayload
            );

        self::assertSame(
            $firstIdentity,
            $secondIdentity
        );

        self::assertSame(
            $this->adapter->sessionId(
                $firstIdentity
            ),
            $this->adapter->sessionId(
                $secondIdentity
            )
        );
    }

    public function testDifferentConversationGetsDifferentSession(): void
    {
        $first = $this->adapter
            ->identityFromTrustedPayload(
                $this->payload()
            );

        $secondPayload = $this->payload();
        $secondPayload['conversation']['id'] = 31;

        $second = $this->adapter
            ->identityFromTrustedPayload(
                $secondPayload
            );

        self::assertNotSame(
            $this->adapter->sessionId($first),
            $this->adapter->sessionId($second)
        );
    }

    public function testInboxMismatchFailsClosed(): void
    {
        $payload = $this->payload();
        $payload['conversation']['inbox_id'] = 999;

        $this->expectException(
            InvalidArgumentException::class
        );
        $this->expectExceptionMessage(
            'inbox.id difere'
        );

        $this->adapter
            ->identityFromTrustedPayload(
                $payload
            );
    }

    public function testAccountMismatchFailsClosed(): void
    {
        $payload = $this->payload();
        $payload['conversation']['account_id'] = 999;

        $this->expectException(
            InvalidArgumentException::class
        );
        $this->expectExceptionMessage(
            'account.id difere'
        );

        $this->adapter
            ->identityFromTrustedPayload(
                $payload
            );
    }

    public function testOutgoingMessageIsRejected(): void
    {
        $payload = $this->payload();
        $payload['message_type'] = 'outgoing';

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->adapter
            ->messageFromTrustedPayload(
                $payload
            );
    }

    public function testPrivateMessageIsRejected(): void
    {
        $payload = $this->payload();
        $payload['private'] = true;

        $this->expectException(
            InvalidArgumentException::class
        );

        $this->adapter
            ->messageFromTrustedPayload(
                $payload
            );
    }

    public function testEndToEndHostFlowBindsTenantBeforeHandle(): void
    {
        $ai = new ChatwootReferenceAiProvider();

        $resolverCalls = 0;
        $receivedIdentity = null;

        $guiche = new Guiche(
            tools: new ToolRegistry(),
            context: $this->store,
            ai: $ai,
            tenantResolver: new CallbackTenantResolver(
                static function (
                    array $identity
                ) use (
                    &$resolverCalls,
                    &$receivedIdentity
                ): array {
                    $resolverCalls++;
                    $receivedIdentity = $identity;

                    return [
                        'tenant_id' => 142,
                    ];
                }
            )
        );

        $result = $this->adapter
            ->handleTrustedIncoming(
                $guiche,
                $this->payload()
            );

        self::assertSame(
            'resposta-chatwoot',
            $result['message']
        );

        self::assertSame(1, $resolverCalls);

        self::assertSame(
            [
                'provider' => 'chatwoot',
                'account_id' => 10,
                'inbox_id' => 20,
                'conversation_id' => 30,
            ],
            $receivedIdentity
        );

        self::assertSame(
            'Olá, gostaria de atendimento.',
            $ai->lastUserMessage
        );

        $sessionId = $this->adapter->sessionId(
            $receivedIdentity
        );

        $executionContext = $this->store
            ->load($sessionId)
            ['execution_context'];

        self::assertSame(
            142,
            $executionContext['tenant_id']
        );

        self::assertSame(
            $receivedIdentity,
            $executionContext['identity']
        );
    }

    public function testSecondMessageDoesNotResolveTenantAgain(): void
    {
        $calls = 0;

        $guiche = new Guiche(
            tools: new ToolRegistry(),
            context: $this->store,
            ai: new ChatwootReferenceAiProvider(),
            tenantResolver: new CallbackTenantResolver(
                static function (
                    array $identity
                ) use (&$calls): array {
                    $calls++;

                    return ['tenant_id' => 142];
                }
            )
        );

        $first = $this->payload();
        $second = $this->payload();

        $second['id'] = 987655;
        $second['content'] = 'Outra mensagem';

        $this->adapter->handleTrustedIncoming(
            $guiche,
            $first
        );

        $this->adapter->handleTrustedIncoming(
            $guiche,
            $second
        );

        self::assertSame(1, $calls);
    }

    public function testReferenceAdapterDoesNotUseExecutionContextSeeder(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2)
            . '/examples/chatwoot/src/ChatwootWebhookAdapter.php'
        );

        self::assertIsString($source);
        self::assertStringContainsString(
            'bindIdentity(',
            $source
        );
        self::assertStringContainsString(
            '->handle(',
            $source
        );
        self::assertStringNotContainsString(
            'ExecutionContextSeeder',
            $source
        );
    }

    public function testCoreHasNoChatwootDependency(): void
    {
        $root = dirname(__DIR__, 2) . '/src';

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $root,
                \FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $file) {
            if (
                !$file->isFile()
                || $file->getExtension() !== 'php'
            ) {
                continue;
            }

            $source = file_get_contents(
                $file->getPathname()
            );

            self::assertIsString($source);
            self::assertStringNotContainsString(
                'Chatwoot',
                $source,
                $file->getPathname()
            );
        }
    }

    /**
     * @return array<mixed>
     */
    private function payload(): array
    {
        return [
            'event' => 'message_created',
            'id' => 987654,
            'content' =>
                'Olá, gostaria de atendimento.',
            'message_type' => 'incoming',
            'private' => false,
            'inbox' => [
                'id' => 20,
                'name' => 'WhatsApp',
            ],
            'conversation' => [
                'id' => 30,
                'inbox_id' => 20,
                'account_id' => 10,
                'status' => 'open',
            ],
            'account' => [
                'id' => 10,
                'name' => 'Empresa de exemplo',
            ],
        ];
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

            $target = $path
                . DIRECTORY_SEPARATOR
                . $item;

            if (is_dir($target)) {
                $this->removeTree($target);
                continue;
            }

            @unlink($target);
        }

        @rmdir($path);
    }
}

final class ChatwootReferenceAiProvider implements AiProviderInterface
{
    public string $lastUserMessage = '';

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
        $last = end($messages);

        $this->lastUserMessage = is_array($last)
            ? (string) ($last['content'] ?? '')
            : '';

        return [
            'id' => 'chatwoot-reference-response',
            'text' => 'resposta-chatwoot',
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
        return [
            'id' => 'chatwoot-reference-continued',
            'text' => 'resposta-chatwoot',
            'tool_calls' => [],
            'usage' => [],
        ];
    }
}
