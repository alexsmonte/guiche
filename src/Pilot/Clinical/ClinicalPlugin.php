<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Pilot\Clinical;

use Asmpkg\Guiche\Contracts\HttpClientInterface;
use Asmpkg\Guiche\Contracts\PluginInterface;
use Asmpkg\Guiche\Contracts\SqlExecutorInterface;
use Asmpkg\Guiche\GuicheBuilder;
use Asmpkg\Guiche\Infrastructure\Http\CurlHttpClient;
use Asmpkg\Guiche\Pilot\Clinical\Presentation\ConsultarHorariosPresenter;
use Asmpkg\Guiche\Pilot\Clinical\Presentation\DisponibilidadeConveniosPresenter;
use Asmpkg\Guiche\Pilot\Clinical\Tools\ConsultarHorariosTool;
use Asmpkg\Guiche\Pilot\Clinical\Tools\ConsultarPacienteTool;
use Asmpkg\Guiche\Pilot\Clinical\Tools\ConsultarProfissionalTool;
use Asmpkg\Guiche\Pilot\Clinical\Tools\CriarAgendamentoTool;
use Asmpkg\Guiche\Pilot\Clinical\Tools\DisponibilidadeConveniosTool;
use Asmpkg\Guiche\Plugin\ToolRegistration;
use Asmpkg\Guiche\ToolPolicy;
use ReflectionClass;
use RuntimeException;

/**
 * Plugin do domínio clínico usado pelo piloto.
 *
 * O Core permanece neutro: dependências, Tools, estado, apresentação,
 * política e projeções específicas do domínio ficam concentradas aqui.
 */
final class ClinicalPlugin implements PluginInterface
{
    public function __construct(
        private readonly SqlExecutorInterface $sql,
        private readonly array $config,
        private readonly string $instructions = '',
        private readonly ?HttpClientInterface $http = null
    ) {
    }

    public function register(GuicheBuilder $builder): void
    {
        $procedures = is_array(
            $this->config['procedures'] ?? null
        )
            ? $this->config['procedures']
            : [];

        $availabilityProcedure = $this->procedure(
            $procedures,
            'disponibilidade_convenios',
            'profissionais_por_tratamento'
        );

        $professionalProcedure = $this->procedure(
            $procedures,
            'consultar_profissional',
            'sp_consulta_profissional'
        );

        $scheduleProcedure = $this->procedure(
            $procedures,
            'consultar_horarios',
            'horarios_disponiveis_por_turno'
        );

        $hoursPerDate = max(
            1,
            (int)($this->config['horarios_por_data'] ?? 3)
        );

        $builder
            ->registerTool(
                $this->registration(
                    new DisponibilidadeConveniosTool(
                        $this->sql,
                        $availabilityProcedure
                    ),
                    'read'
                )
            )
            ->registerTool(
                $this->registration(
                    new ConsultarProfissionalTool(
                        $this->sql,
                        $professionalProcedure
                    ),
                    'read'
                )
            )
            ->registerTool(
                $this->registration(
                    new ConsultarHorariosTool(
                        $this->sql,
                        $scheduleProcedure,
                        $hoursPerDate
                    ),
                    'read'
                )
            );

        $patientProcedure = trim(
            (string)($procedures['consultar_paciente'] ?? '')
        );

        if ($patientProcedure !== '') {
            $builder->registerTool(
                $this->registration(
                    new ConsultarPacienteTool(
                        $this->sql,
                        $patientProcedure
                    ),
                    'read'
                )
            );
        }

        $booking = is_array(
            $this->config['agendamento_api'] ?? null
        )
            ? $this->config['agendamento_api']
            : [];

        $bookingUrl = trim(
            (string)($booking['url'] ?? '')
        );

        if ($bookingUrl !== '') {
            $builder->registerTool(
                $this->registration(
                    new CriarAgendamentoTool(
                        $this->http ?? new CurlHttpClient(),
                        $bookingUrl,
                        (string)($booking['token'] ?? ''),
                        max(
                            1,
                            (int)($booking['timeout'] ?? 15)
                        )
                    ),
                    'write'
                )
            );
        }

        $builder
            ->registerPresenter(
                'DisponibilidadeConvenios',
                new DisponibilidadeConveniosPresenter()
            )
            ->registerPresenter(
                'ConsultarHorarios',
                new ConsultarHorariosPresenter()
            )
            ->useStateManager(
                new ClinicalStateManager()
            );

        $this->registerToolPolicy($builder);
        $this->registerInstructions($builder);
        $this->registerCompletionGuards($builder);
        $this->registerResultProjections($builder);
    }

    private function registration(
        object $tool,
        string $operation
    ): ToolRegistration {
        if (
            !$tool
            instanceof \Asmpkg\Guiche\Contracts\ToolInterface
        ) {
            throw new RuntimeException(
                'ClinicalPlugin recebeu uma Tool inválida.'
            );
        }

        $toolSafety = $this->config['tool_safety'] ?? [];
        $safety = [];

        if (is_array($toolSafety)) {
            $candidate = $toolSafety[$tool->name()] ?? [];

            if (is_array($candidate)) {
                $safety = $candidate;
            }
        }

        /*
         * Metadados estruturais do Plugin não podem ser
         * sobrescritos pela configuração de Safety.
         */
        $metadata = array_merge(
            $safety,
            [
                'plugin' => self::class,
                'domain' => 'clinical',
                'operation' => $operation,
            ]
        );

        return new ToolRegistration(
            $tool,
            $metadata
        );
    }

    private function registerToolPolicy(
        GuicheBuilder $builder
    ): void {
        $policy = is_array(
            $this->config['tool_policy'] ?? null
        )
            ? $this->config['tool_policy']
            : [];

        $builder->registerPolicy(
            'clinical.tool-policy',
            $policy
        );

        $builder->useToolPolicy(
            $this->createToolPolicy($policy)
        );
    }

    private function createToolPolicy(
        array $policy
    ): ToolPolicy {
        $stages = is_array($policy['stages'] ?? null)
            ? $policy['stages']
            : [];

        $default = is_array($policy['default'] ?? null)
            ? $policy['default']
            : [];

        $bootstrap = is_array(
            $policy['bootstrap'] ?? null
        )
            ? $policy['bootstrap']
            : [];

        $stagePath = trim(
            (string)(
                $policy['stage_path']
                ?? $policy['state_path']
                ?? $policy['context_path']
                ?? 'state.etapa'
            )
        );

        if ($stagePath === '') {
            $stagePath = 'state.etapa';
        }

        /*
         * A v0.2 generalizou ToolPolicy para aceitar o caminho de estado.
         * A reflexão abaixo mantém compatibilidade defensiva caso uma
         * revisão intermediária ainda tenha somente os 3 argumentos
         * originais.
         */
        $reflection = new ReflectionClass(
            ToolPolicy::class
        );

        $constructor = $reflection->getConstructor();
        $arguments = [
            $stages,
            $default,
            $bootstrap,
        ];

        if (
            $constructor !== null
            && count($constructor->getParameters()) >= 4
        ) {
            $arguments[] = $stagePath;
        }

        $instance = $reflection->newInstanceArgs(
            $arguments
        );

        if (!$instance instanceof ToolPolicy) {
            throw new RuntimeException(
                'Não foi possível criar ToolPolicy clínica.'
            );
        }

        return $instance;
    }

    private function registerInstructions(
        GuicheBuilder $builder
    ): void {
        $instructions = trim(
            $this->instructions
        );

        if ($instructions === '') {
            return;
        }

        $builder->addInstructionFragment(
            'clinical.instructions',
            $instructions
        );
    }

    private function registerCompletionGuards(
        GuicheBuilder $builder
    ): void {
        $guards = $this->config['completion_guards']
            ?? [];

        if (!is_array($guards)) {
            return;
        }

        foreach ($guards as $index => $guard) {
            if (!is_array($guard)) {
                continue;
            }

            $suffix = is_string($index)
                ? trim($index)
                : (string)$index;

            if ($suffix === '') {
                $suffix = (string)count(
                    $builder->completionGuards()
                );
            }

            $builder->registerCompletionGuard(
                'clinical.completion-guard.' . $suffix,
                $guard
            );
        }
    }

    private function registerResultProjections(
        GuicheBuilder $builder
    ): void {
        $excludeMap =
            $this->config['ai_tool_result_exclude_paths']
            ?? [];

        $includeMap =
            $this->config['ai_tool_result_include_paths']
            ?? [];

        if (!is_array($excludeMap)) {
            $excludeMap = [];
        }

        if (!is_array($includeMap)) {
            $includeMap = [];
        }

        $toolNames = array_values(
            array_unique(
                array_merge(
                    array_keys($excludeMap),
                    array_keys($includeMap)
                )
            )
        );

        foreach ($toolNames as $toolName) {
            $toolName = trim(
                (string)$toolName
            );

            if ($toolName === '') {
                continue;
            }

            $definition = [];

            $excludePaths = $excludeMap[$toolName]
                ?? [];

            if (is_array($excludePaths)) {
                $definition['exclude_paths'] =
                    $excludePaths;
            }

            $includePaths = $includeMap[$toolName]
                ?? [];

            if (is_array($includePaths)) {
                $definition['include_paths'] =
                    $includePaths;
            }

            if ($definition === []) {
                continue;
            }

            $builder->registerResultProjection(
                $toolName,
                $definition
            );
        }
    }

    private function procedure(
        array $procedures,
        string $key,
        string $default
    ): string {
        $value = trim(
            (string)($procedures[$key] ?? '')
        );

        return $value !== ''
            ? $value
            : $default;
    }
}
