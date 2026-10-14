<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Conversation;

use Asmpkg\Guiche\Guiche;
use RuntimeException;
use Throwable;

final class ConversationRuntime
{
    public function __construct(
        private readonly Guiche $guiche,
        private readonly MessageBuffer $buffer,
        private readonly int $quietWindowSeconds = 40,
        private readonly int $maxTurnWindowSeconds = 120
    ) {
        if ($this->quietWindowSeconds < 0) {
            throw new RuntimeException(
                'quietWindowSeconds cannot be negative.'
            );
        }

        if ($this->maxTurnWindowSeconds <= 0) {
            throw new RuntimeException(
                'maxTurnWindowSeconds must be positive.'
            );
        }

        if (
            $this->maxTurnWindowSeconds
            < $this->quietWindowSeconds
        ) {
            throw new RuntimeException(
                'maxTurnWindowSeconds cannot be lower than quietWindowSeconds.'
            );
        }
    }

    public function processReady(
        string $conversationKey
    ): ?array {
        $turn = $this->buffer->openReadyTurn(
            $conversationKey,
            $this->quietWindowSeconds,
            $this->maxTurnWindowSeconds
        );

        if (!$turn instanceof ConversationTurn) {
            return null;
        }

        if ($turn->status === 'processing') {
            return [
                'processed' => false,
                'reason' => 'already_processing',
                'turn_id' => $turn->turnId,
                'sequence_snapshot' =>
                    $turn->sequenceSnapshot,
            ];
        }

        if ($turn->status !== 'pending') {
            return null;
        }

        $processing =
            $this->buffer->tryStartProcessing(
                $conversationKey,
                $turn->turnId
            );

        if (!$processing instanceof ConversationTurn) {
            return [
                'processed' => false,
                'reason' => 'already_processing',
                'turn_id' => $turn->turnId,
                'sequence_snapshot' =>
                    $turn->sequenceSnapshot,
            ];
        }

        try {
            $message =
                $this->messageFromTurn($processing);

            $result =
                $this->guiche->handle(
                    $conversationKey,
                    $message
                );

            $completed =
                $this->buffer->completeTurn(
                    $conversationKey,
                    $processing->turnId
                );

            return [
                'processed' => true,
                'turn_id' => $completed->turnId,
                'sequence_snapshot' =>
                    $completed->sequenceSnapshot,
                'message' => $message,
                'result' => $result,
            ];
        } catch (Throwable $exception) {
            $this->buffer->failTurn(
                $conversationKey,
                $processing->turnId,
                $exception::class
            );

            throw $exception;
        }
    }

    private function messageFromTurn(
        ConversationTurn $turn
    ): string {
        $parts = [];

        foreach ($turn->events as $event) {
            if (!is_array($event)) {
                continue;
            }

            if (($event['type'] ?? null) !== 'message') {
                continue;
            }

            $text =
                $event['payload']['text']
                ?? null;

            if (!is_string($text)) {
                continue;
            }

            $text = trim($text);

            if ($text !== '') {
                $parts[] = $text;
            }
        }

        if ($parts === []) {
            throw new RuntimeException(
                'Conversation turn has no textual message.'
            );
        }

        return implode(PHP_EOL, $parts);
    }
}
