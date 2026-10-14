<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Safety;

use Asmpkg\Guiche\Plugin\ToolRegistration;
use Asmpkg\Guiche\ToolPolicy;
use Asmpkg\Guiche\ToolRegistry;

/**
 * Gate neutro de autorização pré-execução.
 *
 * Responsabilidades desta fase:
 * - validar registro da Tool;
 * - validar operação declarada;
 * - validar ToolPolicy quando presente;
 * - validar allowed_stages;
 * - validar requires_state;
 * - validar forbids_state;
 * - validar confirmação declarativa;
 * - validar argumentos contra o schema da Tool.
 *
 * Importante:
 * esta classe NÃO executa a Tool.
 * O acoplamento imediatamente antes de ToolInterface::execute()
 * será feito na Fase 03.
 */
final class ToolExecutionGate
{
    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly ?ToolPolicy $policy = null,
        private readonly ?ToolArgumentValidator $argumentValidator = null
    ) {
    }

    /**
     * @throws ToolBlockedException
     * @param array<mixed> $arguments
     * @param array<mixed> $context
     */
    public function assertCanExecute(
        string $toolName,
        array $arguments,
        array $context
    ): ToolRegistration {
        $toolName = trim($toolName);

        if ($toolName === '' || !$this->tools->has($toolName)) {
            throw $this->blocked(
                $toolName,
                'tool_not_registered',
                'A ferramenta solicitada não está registrada no runtime.'
            );
        }

        $registration = $this->tools->registration($toolName);
        $metadata = $registration->metadata();
        $operation = ToolSafetyMetadata::operation($metadata);

        if ($operation === ToolSafetyMetadata::OPERATION_UNKNOWN) {
            throw $this->blocked(
                $toolName,
                'operation_invalid',
                'A ferramenta possui uma operação de segurança inválida.',
                [
                    'operation' => $metadata['operation']
                        ?? $metadata['kind']
                        ?? null,
                ]
            );
        }

        if (
            $this->policy !== null
            && !$this->policy->allows($toolName, $context)
        ) {
            throw $this->blocked(
                $toolName,
                'policy_denied',
                'A ferramenta não está autorizada pela ToolPolicy no contexto atual.',
                [
                    'allowed_tools' =>
                        $this->policy->allowedToolNames($context),
                ]
            );
        }

        $this->assertAllowedStage(
            $toolName,
            $metadata,
            $context
        );

        $this->assertRequiredState(
            $toolName,
            ToolSafetyMetadata::requiredState($metadata),
            $context
        );

        $this->assertForbiddenState(
            $toolName,
            ToolSafetyMetadata::forbiddenState($metadata),
            $context
        );

        $this->assertConfirmation(
            $toolName,
            ToolSafetyMetadata::confirmation($metadata),
            $context
        );

        $validator = $this->argumentValidator
            ?? new ToolArgumentValidator();

        $errors = $validator->validate(
            $registration->tool()->parameters(),
            $arguments
        );

        if ($errors !== []) {
            throw $this->blocked(
                $toolName,
                'invalid_arguments',
                'Os argumentos da ferramenta não atendem ao schema declarado.',
                [
                    'errors' => $errors,
                ]
            );
        }

        return $registration;
    }

    /**
     * @param array<mixed> $context
     * @param array<mixed> $metadata
     */
    private function assertAllowedStage(
        string $toolName,
        array $metadata,
        array $context
    ): void {
        $allowedStages = ToolSafetyMetadata::allowedStages(
            $metadata
        );

        if ($allowedStages === []) {
            return;
        }

        $stagePath = ToolSafetyMetadata::stagePath($metadata)
            ?? 'state.stage';

        [$exists, $stage] = $this->readContextPath(
            $context,
            $stagePath
        );

        $stage = $exists
            ? trim((string)$stage)
            : '';

        if (
            $stage === ''
            || !in_array($stage, $allowedStages, true)
        ) {
            throw $this->blocked(
                $toolName,
                'stage_not_allowed',
                'A ferramenta não está autorizada para o estágio atual.',
                [
                    'stage_path' => $stagePath,
                    'current_stage' => $stage !== ''
                        ? $stage
                        : null,
                    'allowed_stages' => $allowedStages,
                ]
            );
        }
    }

    /**
     * @param array<mixed> $conditions
     * @param array<mixed> $context
     */
    private function assertRequiredState(
        string $toolName,
        array $conditions,
        array $context
    ): void {
        foreach ($conditions as $condition) {
            $path = trim((string)($condition['path'] ?? ''));

            if ($path === '') {
                continue;
            }

            [$exists, $actual] = $this->readContextPath(
                $context,
                $path
            );

            if (!$exists) {
                throw $this->blocked(
                    $toolName,
                    'required_state_missing',
                    'Um estado obrigatório para a ferramenta está ausente.',
                    [
                        'path' => $path,
                    ]
                );
            }

            if (
                ($condition['operator'] ?? 'exists') === 'equals'
                && (
                    !array_key_exists('value', $condition)
                    || $actual !== $condition['value']
                )
            ) {
                throw $this->blocked(
                    $toolName,
                    'required_state_mismatch',
                    'Um estado obrigatório para a ferramenta possui valor incompatível.',
                    [
                        'path' => $path,
                        'expected' => $condition['value'] ?? null,
                        'actual' => $actual,
                    ]
                );
            }
        }
    }

    /**
     * @param array<mixed> $conditions
     * @param array<mixed> $context
     */
    private function assertForbiddenState(
        string $toolName,
        array $conditions,
        array $context
    ): void {
        foreach ($conditions as $condition) {
            $path = trim((string)($condition['path'] ?? ''));

            if ($path === '') {
                continue;
            }

            [$exists, $actual] = $this->readContextPath(
                $context,
                $path
            );

            if (!$exists) {
                continue;
            }

            $operator = $condition['operator'] ?? 'exists';

            if ($operator === 'exists') {
                throw $this->blocked(
                    $toolName,
                    'forbidden_state_present',
                    'Um estado incompatível com a execução está presente.',
                    [
                        'path' => $path,
                        'actual' => $actual,
                    ]
                );
            }

            if (
                $operator === 'equals'
                && array_key_exists('value', $condition)
                && $actual === $condition['value']
            ) {
                throw $this->blocked(
                    $toolName,
                    'forbidden_state_match',
                    'Um estado incompatível com a execução possui valor proibido.',
                    [
                        'path' => $path,
                        'forbidden' => $condition['value'],
                        'actual' => $actual,
                    ]
                );
            }
        }
    }

    /**
     * @param array<mixed>|null $confirmation
     * @param array<mixed> $context
     */
    private function assertConfirmation(
        string $toolName,
        ?array $confirmation,
        array $context
    ): void {
        if ($confirmation === null) {
            return;
        }

        $path = trim((string)(
            $confirmation['path']
            ?? ''
        ));

        if ($path === '') {
            throw $this->blocked(
                $toolName,
                'confirmation_invalid',
                'A configuração de confirmação da ferramenta é inválida.'
            );
        }

        [$exists, $actual] = $this->readContextPath(
            $context,
            $path
        );

        $expected = $confirmation['value'] ?? true;

        if (!$exists || $actual !== $expected) {
            throw $this->blocked(
                $toolName,
                'confirmation_required',
                'A ferramenta exige confirmação válida antes da execução.',
                [
                    'path' => $path,
                    'expected' => $expected,
                    'actual' => $exists
                        ? $actual
                        : null,
                ]
            );
        }
    }

    /**
     * @return array{0: bool, 1: mixed}
     * @param array<mixed> $context
     */
    private function readContextPath(
        array $context,
        string $path
    ): array {
        $path = trim($path);

        if ($path === '') {
            return [false, null];
        }

        $value = $context;

        foreach (explode('.', $path) as $segment) {
            $segment = trim($segment);

            if (
                $segment === ''
                || !is_array($value)
                || !array_key_exists($segment, $value)
            ) {
                return [false, null];
            }

            $value = $value[$segment];
        }

        return [true, $value];
    }

    /**
     * @param array<mixed> $details
     */
    private function blocked(
        string $toolName,
        string $reason,
        string $message,
        array $details = []
    ): ToolBlockedException {
        return new ToolBlockedException(
            $toolName,
            $reason,
            $message,
            $details
        );
    }
}
