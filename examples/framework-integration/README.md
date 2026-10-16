# Guichê v1.4 — Integração com frameworks

O Guichê não depende do framework da aplicação.

A fronteira recomendada é sempre:

```text
Request/Webhook/Job
      ↓
aplicação host autentica/resgata identidade
      ↓
$guiche->bindIdentity($sessionId, $trustedIdentity)
      ↓
$guiche->handle($sessionId, $message)
```

A mensagem do usuário nunca deve ser usada para montar a identidade
confiável ou selecionar diretamente o tenant.

## Composition Root

O arquivo `guiche.php` criado por `vendor/bin/guiche init` retorna uma
factory:

```php
$factory = require ROOT . '/guiche.php';

$guiche = $factory([
    'ai_provider' => $aiProvider,
    'context_store' => $contextStore,
    'tenant_resolver' => $tenantResolver,
    'prompt_resolver' => $promptResolver,
    'plugins' => $plugins,
    'tools' => $tools,
]);
```

As dependências podem vir do container do framework.

---

## CakePHP

Em CakePHP, carregue a factory em um Service/Factory da aplicação.

```php
use Asmpkg\Guiche\Guiche;

final class GuicheFactory
{
    public function __construct(
        private readonly string $root,
        private readonly object $dependencies
    ) {
    }

    public function create(): Guiche
    {
        $factory = require $this->root
            . '/guiche.php';

        return $factory([
            'ai_provider' =>
                $this->dependencies->aiProvider(),
            'context_store' =>
                $this->dependencies->contextStore(),
            'tenant_resolver' =>
                $this->dependencies->tenantResolver(),
            'prompt_resolver' =>
                $this->dependencies->promptResolver(),
        ]);
    }
}
```

No Controller/Webhook:

```php
$guiche->bindIdentity(
    $sessionId,
    [
        'provider' => 'chat',
        'account_id' => $accountId,
        'conversation_id' => $conversationId,
    ]
);

$result = $guiche->handle(
    $sessionId,
    $message
);
```

A identidade deve vir de dados autenticados/validados pelo Controller,
middleware, assinatura do webhook ou banco da aplicação — nunca do
texto da conversa.

---

## Laravel

Registre `Guiche` no service container usando a factory:

```php
$this->app->singleton(
    \Asmpkg\Guiche\Guiche::class,
    function ($app) {
        $factory = require base_path(
            'guiche.php'
        );

        return $factory([
            'ai_provider' =>
                $app->make(
                    \Asmpkg\Guiche\Contracts\AiProviderInterface::class
                ),
            'context_store' =>
                $app->make(
                    \Asmpkg\Guiche\Contracts\ContextStoreInterface::class
                ),
            'tenant_resolver' =>
                $app->make(
                    \Asmpkg\Guiche\Contracts\TenantResolverInterface::class
                ),
            'prompt_resolver' =>
                $app->make(
                    \Asmpkg\Guiche\Contracts\PromptResolverInterface::class
                ),
        ]);
    }
);
```

---

## Symfony

Registre uma factory de serviço que carregue `guiche.php` e injete os
contratos da aplicação.

A integração deve terminar produzindo uma instância única de:

```text
Asmpkg\Guiche\Guiche
```

O Core não precisa conhecer `Kernel`, `ContainerInterface`, Request ou
Response do Symfony.

---

## PHP puro

```php
$factory = require __DIR__
    . '/guiche.php';

$guiche = $factory();

$guiche->bindIdentity(
    $sessionId,
    $trustedIdentity
);

$response = $guiche->handle(
    $sessionId,
    $message
);
```

---

## Regra multi-tenant

Fluxo obrigatório:

```text
SESSION
  ↓
identity confiável
  ↓
TenantResolver
  ↓
execution_context.tenant_id
  ↓
PromptContext
  ↓
PromptResolver
  ↓
prompt do tenant
```

Nunca:

```text
mensagem do usuário
→ tenant

state conversacional
→ tenant

argumento de Tool gerado pela IA
→ tenant
```

## Write-once

Depois que uma sessão foi vinculada à identidade A:

```text
session X → identity A → tenant A
```

uma tentativa de trocar para identidade B deve falhar fechada.

Para outra identidade/tenant, use outra sessão.

## Concorrência

Bindings idempotentes da mesma identidade são seguros. Bindings
conflitantes não podem vencer por corrida.

A aplicação host deve manter um `sessionId` estável para a mesma
conversa externa.
