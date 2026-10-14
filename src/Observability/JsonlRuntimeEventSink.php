<?php

declare(strict_types=1);

namespace Asmpkg\Guiche\Observability;

use Asmpkg\Guiche\Contracts\RuntimeEventSinkInterface;
use RuntimeException;
use Throwable;

final class JsonlRuntimeEventSink implements RuntimeEventSinkInterface
{
    public function __construct(
        private readonly string $file
    ) {
        $file = trim($this->file);

        if ($file === '') {
            throw new RuntimeException(
                'Arquivo JSONL de observabilidade não pode ser vazio.'
            );
        }

        $directory = dirname($file);

        if (
            $directory !== '.'
            && !is_dir($directory)
            && !mkdir($directory, 0775, true)
            && !is_dir($directory)
        ) {
            throw new RuntimeException(
                "Não foi possível criar diretório de observabilidade: {$directory}"
            );
        }
    }

    public function record(array $event): void
    {
        try {
            $json = json_encode(
                $event,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            );
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Evento de observabilidade não é serializável em JSON.',
                0,
                $exception
            );
        }

        $payload = $json . PHP_EOL;

        $written = file_put_contents(
            $this->file,
            $payload,
            FILE_APPEND | LOCK_EX
        );

        if (
            $written === false
            || $written !== strlen($payload)
        ) {
            throw new RuntimeException(
                "Não foi possível persistir evento JSONL em {$this->file}."
            );
        }
    }

    public function file(): string
    {
        return $this->file;
    }
}
