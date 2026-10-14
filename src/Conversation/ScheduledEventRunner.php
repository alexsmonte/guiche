<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Conversation;

use Asmpkg\Guiche\Contracts\ContextStoreInterface;
use Asmpkg\Guiche\Contracts\ConversationStoreInterface;
use RuntimeException;
use Throwable;

final class ScheduledEventRunner
{
    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly ConversationStoreInterface $conversationStore,
        private readonly ContextStoreInterface $contextStore
    ) {
    }

    /**
     * @param callable(ScheduledEvent): mixed $handler
     *
     * @return list<array{
     *     event_id:string,
     *     status:string,
     *     reason?:string
     * }>
     */
    public function runDue(
        string $workerId,
        int $leaseSeconds,
        callable $handler,
        int $limit = 10
    ): array {
        $claimed =
            $this->scheduler->claimDue(
                $workerId,
                $leaseSeconds,
                $limit
            );

        $results = [];

        foreach ($claimed as $event) {
            $results[] =
                $this->runClaimed(
                    $event,
                    $handler
                );
        }

        return $results;
    }

    /**
     * @param callable(ScheduledEvent): mixed $handler
     *
     * @return array{
     *     event_id:string,
     *     status:string,
     *     reason?:string
     * }
     */
    private function runClaimed(
        ScheduledEvent $event,
        callable $handler
    ): array {
        $result = null;
        $failure = null;

        try {
            $this->conversationStore->mutate(
                $event->conversationKey,
                function (array $aggregate) use (
                    $event,
                    $handler,
                    &$result,
                    &$failure
                ): array {
                    if (
                        $event->sequenceSnapshot !== null
                        && (int) ($aggregate['sequence'] ?? 0)
                            !== $event->sequenceSnapshot
                    ) {
                        $this->scheduler->markStale(
                            $event,
                            'sequence_changed'
                        );

                        $result = [
                            'event_id' => $event->eventId,
                            'status' => 'stale',
                            'reason' => 'sequence_changed',
                        ];

                        return $aggregate;
                    }

                    if (
                        $event->requiredState !== []
                        && !$this->requiredStateMatches(
                            $event->conversationKey,
                            $event->requiredState
                        )
                    ) {
                        $this->scheduler->markStale(
                            $event,
                            'state_condition_changed'
                        );

                        $result = [
                            'event_id' => $event->eventId,
                            'status' => 'stale',
                            'reason' => 'state_condition_changed',
                        ];

                        return $aggregate;
                    }

                    /*
                     * A mutação mantém o lock desta conversa durante
                     * a revalidação final e o início/execução do handler.
                     * IncomingEventRecorder da mesma conversa aguarda
                     * este lock, eliminando a janela entre "check stale"
                     * e o side effect.
                     */
                    try {
                        $handler($event);

                        $completed =
                            $this->scheduler->complete(
                                $event
                            );

                        $result = [
                            'event_id' =>
                                $completed->eventId,
                            'status' => 'completed',
                        ];
                    } catch (Throwable $exception) {
                        $failure = $exception;
                    }

                    return $aggregate;
                }
            );
        } catch (Throwable $exception) {
            if ($failure === null) {
                throw $exception;
            }
        }

        if ($failure instanceof Throwable) {
            $indeterminate =
                $this->scheduler
                    ->markIndeterminate(
                        $event,
                        $failure
                    );

            return [
                'event_id' =>
                    $indeterminate->eventId,
                'status' => 'indeterminate',
            ];
        }

        if (!is_array($result)) {
            throw new RuntimeException(
                'Scheduled event runner produced no terminal result.'
            );
        }

        return $result;
    }

    private function requiredStateMatches(
        string $conversationKey,
        array $requirements
    ): bool {
        $context =
            $this->contextStore->load(
                $conversationKey
            );

        foreach ($requirements as $path => $expected) {
            [$found, $actual] =
                $this->valueAtPath(
                    $context,
                    (string) $path
                );

            if (!$found || $actual !== $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{0:bool,1:mixed}
     */
    private function valueAtPath(
        array $source,
        string $path
    ): array {
        $segments =
            array_values(
                array_filter(
                    explode('.', $path),
                    static fn (string $segment): bool =>
                        $segment !== ''
                )
            );

        if ($segments === []) {
            return [false, null];
        }

        $value = $source;

        foreach ($segments as $segment) {
            if (
                !is_array($value)
                || !array_key_exists(
                    $segment,
                    $value
                )
            ) {
                return [false, null];
            }

            $value = $value[$segment];
        }

        return [true, $value];
    }
}
