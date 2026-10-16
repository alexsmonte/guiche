<?php

declare(strict_types=1);

namespace GuicheExample\Chatwoot;

use Asmpkg\Guiche\Guiche;
use InvalidArgumentException;

final class ChatwootWebhookAdapter
{
    /**
     * Este adapter recebe SOMENTE payload que o host já decidiu confiar.
     *
     * Autenticação do webhook, controles de rede, gateway, allowlist,
     * segredo de endpoint e demais mecanismos de confiança pertencem ao
     * aplicativo hospedeiro. O Guichê não autentica Chatwoot.
     *
     * @param array<mixed> $trustedPayload
     * @return array{
     *     provider: string,
     *     account_id: int,
     *     inbox_id: int,
     *     conversation_id: int
     * }
     */
    public function identityFromTrustedPayload(
        array $trustedPayload
    ): array {
        $this->assertMessageCreated($trustedPayload);

        $accountId = $this->positiveInteger(
            $trustedPayload['account']['id'] ?? null,
            'account.id'
        );

        $conversationId = $this->positiveInteger(
            $trustedPayload['conversation']['id'] ?? null,
            'conversation.id'
        );

        $inboxId = $this->resolveInboxId(
            $trustedPayload
        );

        $conversationInbox = $trustedPayload
            ['conversation']
            ['inbox_id']
            ?? null;

        if ($conversationInbox !== null) {
            $conversationInbox = $this->positiveInteger(
                $conversationInbox,
                'conversation.inbox_id'
            );

            if ($conversationInbox !== $inboxId) {
                throw new InvalidArgumentException(
                    'Chatwoot payload inconsistente: '
                    . 'inbox.id difere de conversation.inbox_id.'
                );
            }
        }

        $conversationAccount = $trustedPayload
            ['conversation']
            ['account_id']
            ?? null;

        if ($conversationAccount !== null) {
            $conversationAccount = $this->positiveInteger(
                $conversationAccount,
                'conversation.account_id'
            );

            if ($conversationAccount !== $accountId) {
                throw new InvalidArgumentException(
                    'Chatwoot payload inconsistente: '
                    . 'account.id difere de conversation.account_id.'
                );
            }
        }

        return [
            'provider' => 'chatwoot',
            'account_id' => $accountId,
            'inbox_id' => $inboxId,
            'conversation_id' => $conversationId,
        ];
    }

    /**
     * Session ID opaca e determinística por conversa.
     *
     * A mensagem individual NÃO participa da chave.
     *
     * @param array{
     *     provider: string,
     *     account_id: int,
     *     inbox_id: int,
     *     conversation_id: int
     * } $identity
     */
    public function sessionId(array $identity): string
    {
        foreach (
            [
                'account_id',
                'inbox_id',
                'conversation_id',
            ] as $key
        ) {
            if (!isset($identity[$key])) {
                throw new InvalidArgumentException(
                    "Identity Chatwoot sem {$key}."
                );
            }
        }

        if (($identity['provider'] ?? null) !== 'chatwoot') {
            throw new InvalidArgumentException(
                'Identity não pertence ao provider chatwoot.'
            );
        }

        $material = implode(
            ':',
            [
                'chatwoot',
                (string) $identity['account_id'],
                (string) $identity['inbox_id'],
                (string) $identity['conversation_id'],
            ]
        );

        return 'chatwoot-'
            . hash('sha256', $material);
    }

    /**
     * @param array<mixed> $trustedPayload
     */
    public function messageFromTrustedPayload(
        array $trustedPayload
    ): string {
        $this->assertMessageCreated($trustedPayload);

        $messageType = $trustedPayload['message_type']
            ?? null;

        if (
            $messageType !== 'incoming'
            && $messageType !== 0
            && $messageType !== '0'
        ) {
            throw new InvalidArgumentException(
                'Somente message_created incoming deve chegar ao Guichê.'
            );
        }

        if (($trustedPayload['private'] ?? false) === true) {
            throw new InvalidArgumentException(
                'Mensagem privada do Chatwoot não deve chegar ao Guichê.'
            );
        }

        $content = $trustedPayload['content']
            ?? null;

        if (!is_string($content)) {
            throw new InvalidArgumentException(
                'Chatwoot payload sem content textual.'
            );
        }

        $content = trim($content);

        if ($content === '') {
            throw new InvalidArgumentException(
                'Chatwoot payload possui content vazio.'
            );
        }

        return $content;
    }

    /**
     * Fluxo de referência do host:
     *
     * payload confiável
     * -> identity
     * -> sessionId
     * -> Guiche::bindIdentity()
     * -> Guiche::handle()
     *
     * @param array<mixed> $trustedPayload
     * @return array<mixed>
     */
    public function handleTrustedIncoming(
        Guiche $guiche,
        array $trustedPayload
    ): array {
        $identity = $this->identityFromTrustedPayload(
            $trustedPayload
        );

        $sessionId = $this->sessionId($identity);

        $guiche->bindIdentity(
            $sessionId,
            $identity
        );

        return $guiche->handle(
            $sessionId,
            $this->messageFromTrustedPayload(
                $trustedPayload
            )
        );
    }

    /**
     * @param array<mixed> $payload
     */
    private function assertMessageCreated(
        array $payload
    ): void {
        if (($payload['event'] ?? null) !== 'message_created') {
            throw new InvalidArgumentException(
                'Adapter de referência aceita somente event=message_created.'
            );
        }

        if (
            !isset($payload['account'])
            || !is_array($payload['account'])
            || !isset($payload['conversation'])
            || !is_array($payload['conversation'])
        ) {
            throw new InvalidArgumentException(
                'Chatwoot payload sem account/conversation válidos.'
            );
        }
    }

    /**
     * @param array<mixed> $payload
     */
    private function resolveInboxId(
        array $payload
    ): int {
        $inbox = $payload['inbox'] ?? null;

        if (
            is_array($inbox)
            && array_key_exists('id', $inbox)
        ) {
            return $this->positiveInteger(
                $inbox['id'],
                'inbox.id'
            );
        }

        return $this->positiveInteger(
            $payload['conversation']['inbox_id']
                ?? null,
            'conversation.inbox_id'
        );
    }

    private function positiveInteger(
        mixed $value,
        string $path
    ): int {
        if (
            is_int($value)
            && $value > 0
        ) {
            return $value;
        }

        if (
            is_string($value)
            && ctype_digit($value)
            && (int) $value > 0
        ) {
            return (int) $value;
        }

        throw new InvalidArgumentException(
            "Chatwoot {$path} deve ser inteiro positivo."
        );
    }
}
