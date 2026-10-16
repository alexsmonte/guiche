# Guichê v1.3.0 — Trusted External Identity

Data de preparação: 2026-10-16.

## Resumo

A v1.3 transforma o contexto externo confiável em uma experiência pública de
primeira classe.

Aplicações hospedeiras podem autenticar sua própria origem, construir uma
identity confiável e entregá-la diretamente ao Guichê:

```php
$guiche->bindIdentity($sessionId, $identity);
$response = $guiche->handle($sessionId, $message);
```

## Pipeline

```text
Aplicação externa
→ bindIdentity()
→ identity confiável
→ TenantResolver
→ tenant_id
→ ToolExecutionContext
→ Tools
```

## Destaques

- API pública `Guiche::bindIdentity()`;
- write-once de identity e tenant;
- rebinding idempotente;
- conflito fail-closed;
- resolução automática de tenant;
- sanitização de identity/tenant na observabilidade;
- contexto confiável fora do prompt da IA;
- auditoria segura por fingerprints;
- concorrência bind/handle serializada por sessão;
- adapter Chatwoot de referência;
- exemplo genérico para qualquer provider externo.

## Chatwoot

Chatwoot permanece fora do Core.

O exemplo está em:

```text
examples/chatwoot/
```

## Providers adicionais

A arquitetura suporta adapters para qualquer host que consiga estabelecer
sua identidade com segurança, como Telegram, Instagram, WhatsApp Cloud API,
Evolution API, ManyChat ou CRM próprio.

## Compatibilidade

- SemVer: minor compatível;
- Public: 32;
- Experimental: 11;
- Internal: 14;
- API hash:
  `fa76a663dcb6afdd10dee53913a50fc6686ef88ce4f9618411967bc7168b4c52`.

## Validação antes da publicação

Na Fase 05:

- regressão focada: 75 testes / 389 assertions;
- suíte completa: 222 testes;
- PHPStan: zero erros;
- coverage: 75,69%;
- minimum coverage: 70%;
- API freeze: verde;
- experimental freeze: verde.

A Fase 06 repete o quality gate e adiciona validação do pacote Composer antes
da publicação.
