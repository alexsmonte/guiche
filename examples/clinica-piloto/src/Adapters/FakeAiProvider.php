<?php

declare(strict_types=1);

namespace GuicheExample\ClinicaPiloto\Adapters;

use Asmpkg\Guiche\Contracts\AiProviderInterface;

final class FakeAiProvider implements AiProviderInterface
{
    private int $sequence = 0;

    public function respond(
        string $instructions,
        array $messages,
        array $tools = []
    ): array {
        return $this->response(
            'Fake Mode ativo: nenhum serviço externo foi chamado.'
        );
    }

    public function continueWithToolOutputs(
        string $previousResponseId,
        string $instructions,
        array $toolOutputs,
        array $tools = []
    ): array {
        return $this->response(
            'Fake Mode ativo: retorno local determinístico.'
        );
    }

    private function response(string $text): array
    {
        $this->sequence++;
        $id = sprintf('fake_response_%03d', $this->sequence);

        return [
            'id' => $id,
            'response_id' => $id,
            'text' => $text,
            'output_text' => $text,
            'message' => $text,
            'tool_calls' => [],
            'usage' => [
                'input_tokens' => 0,
                'output_tokens' => 0,
                'total_tokens' => 0,
                'details' => ['fake_mode' => true],
            ],
            'synthetic' => true,
        ];
    }
}
