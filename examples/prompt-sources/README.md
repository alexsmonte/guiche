# Guichê v1.4 — Prompt Sources

A v1.4 usa `PromptResolverInterface` como fronteira para qualquer fonte
externa de prompt.

O Core não precisa conhecer PDO, ORM, Redis, CMS ou cliente HTTP.

## Arquivo

```php
use Asmpkg\Guiche\Prompt\FilePromptResolver;

$resolver = new FilePromptResolver(
    __DIR__ . '/prompts/default.md'
);

$builder->withPromptResolver($resolver);
```

O arquivo é relido a cada turno.

## Banco de dados

Use `CallbackPromptResolver` para adaptar repository, ORM ou query
existente:

```php
use Asmpkg\Guiche\Prompt\CallbackPromptResolver;
use Asmpkg\Guiche\Prompt\PromptContext;

$resolver = new CallbackPromptResolver(
    static function (
        PromptContext $context
    ) use ($promptRepository): string {
        $tenantId = $context->tenantId();

        if ($tenantId === null) {
            return '';
        }

        return $promptRepository
            ->findActivePrompt((string)$tenantId)
            ?? '';
    }
);
```

O callback recebe somente `PromptContext`, construído pelo runtime a
partir do contexto operacional confiável da sessão.

## API / CMS externo

```php
use Asmpkg\Guiche\Prompt\CallbackPromptResolver;
use Asmpkg\Guiche\Prompt\PromptContext;

$resolver = new CallbackPromptResolver(
    static function (
        PromptContext $context
    ) use ($promptClient): string {
        return $promptClient->fetchPrompt(
            tenantId: (string)$context->tenantId()
        );
    }
);
```

Erros reais da API devem lançar exception. Não converta falha de rede,
timeout ou erro de autenticação em string vazia.

## Fallback explícito

```php
use Asmpkg\Guiche\Prompt\FallbackPromptResolver;
use Asmpkg\Guiche\Prompt\FilePromptResolver;

$resolver = new FallbackPromptResolver(
    $databaseResolver,
    new FilePromptResolver(
        __DIR__ . '/prompts/default.md'
    )
);
```

A regra é deliberadamente estrita:

```text
resolver retorna prompt
→ usa o prompt

resolver retorna ""
→ tenta a próxima fonte

resolver lança exception
→ FAIL-CLOSED
→ não tenta a próxima fonte
```

Assim uma indisponibilidade de banco/API não troca silenciosamente o
agente para outro prompt.

## Composition Root

No `guiche.php` criado por `vendor/bin/guiche init`:

```php
$factory = require __DIR__ . '/guiche.php';

$guiche = $factory([
    'prompt_resolver' => $resolver,
]);
```

A mesma técnica funciona em PHP puro, CakePHP, Laravel, Symfony ou
qualquer container de dependências.
