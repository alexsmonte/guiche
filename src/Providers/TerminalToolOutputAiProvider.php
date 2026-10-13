<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Providers;

use Asmpkg\Guiche\Contracts\AiProviderInterface;

final class TerminalToolOutputAiProvider implements AiProviderInterface
{
    public function __construct(private readonly AiProviderInterface $inner)
    {
    }

    public function respond(
        string $instructions,
        array $messages,
        array $tools = []
    ): array {
        return $this->inner->respond(
            $instructions,
            $messages,
            $tools
        );
    }

    public function continueWithToolOutputs(
        string $previousResponseId,
        string $instructions,
        array $toolOutputs,
        array $tools = []
    ): array {
        /*
         * terminal_message:
         * a Tool já produziu a resposta final e não precisamos
         * consultar novamente o provider de IA.
         */
        $terminalMessage = $this->findGuicheMessage(
            $toolOutputs,
            'terminal_message'
        );

        if ($terminalMessage !== null) {
            return $this->syntheticResponse(
                $previousResponseId,
                $terminalMessage
            );
        }

        /*
         * presentation_message:
         * ainda deixamos a IA decidir se precisa encadear
         * outra Tool. Se não houver novo tool call e vier
         * apenas texto livre, preservamos a apresentação
         * determinística produzida pela Tool.
         */
        $presentationMessage = $this->findGuicheMessage(
            $toolOutputs,
            'presentation_message'
        );

        $response = $this->inner
            ->continueWithToolOutputs(
                $previousResponseId,
                $instructions,
                $toolOutputs,
                $tools
            );

        if (
            $presentationMessage !== null
            && !$this->hasToolCalls($response)
        ) {
            $response['text'] = $presentationMessage;
            $response['output_text'] = $presentationMessage;
            $response['message'] = $presentationMessage;
            $response['deterministic_presentation'] = true;
        }

        return $response;
    }

    private function findGuicheMessage(
        array $toolOutputs,
        string $key
    ): ?string {
        $message = null;

        foreach ($toolOutputs as $toolOutput) {
            $output = $toolOutput['output'] ?? null;

            if (is_string($output)) {
                $decoded = json_decode(
                    $output,
                    true
                );

                if (is_array($decoded)) {
                    $output = $decoded;
                }
            }

            if (!is_array($output)) {
                continue;
            }

            $candidate = trim((string)(
                $output['_guiche'][$key]
                ?? ''
            ));

            if ($candidate !== '') {
                $message = $candidate;
            }
        }

        return $message;
    }

    private function hasToolCalls(array $response): bool
    {
        $toolCalls = $response['tool_calls'] ?? [];

        return is_array($toolCalls)
            && $toolCalls !== [];
    }

    private function syntheticResponse(
        string $previousResponseId,
        string $message
    ): array {
        return [
            'id' =>
                'local_'
                . bin2hex(
                    random_bytes(8)
                ),

            'response_id' =>
                $previousResponseId,

            'text' =>
                $message,

            'output_text' =>
                $message,

            'message' =>
                $message,

            'tool_calls' => [],

            'usage' => [
                'input_tokens' => 0,
                'output_tokens' => 0,
                'total_tokens' => 0,
                'details' => [
                    'synthetic' => true,
                ],
            ],

            'synthetic' => true,
        ];
    }
}
