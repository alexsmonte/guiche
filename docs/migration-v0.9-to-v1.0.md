# Migração v0.9 → v1.0

## Resumo

A v1.0.0 formaliza como estável a superfície `public` que foi congelada e validada durante a v0.9.0.

Para consumidores que usavam apenas símbolos `public`, a migração foi desenhada para ser direta.

## Composer

Antes:

```bash
composer require asmpkg/guiche:^0.9
```

Na linha estável:

```bash
composer require asmpkg/guiche:^1.0
```

## API pública

A superfície `public` mantém o mesmo baseline candidato da v0.9.0.

Hash SHA-256:

`39e417149aaee5ac9008de4bb3e477c579826f86ea9902b8755965fc1482368d`

Mudanças incompatíveis nessa superfície, após a v1.0.0, exigem uma nova major.

## Conversation Runtime experimental

Os 11 símbolos classificados como `experimental` continuam disponíveis, mas não entram no compromisso BC da linha v1.x.

Consumidores que usam diretamente esses símbolos devem acompanhar as release notes antes de upgrades minor/patch que os afetem.

`Asmpkg\Guiche\Conversation\ScheduledEvent` permanece `public` por ser exposto transitivamente por contratos públicos.

## PHP

Suporte oficial:

- PHP 8.4;
- PHP 8.5.

## Segurança

Nenhuma Tool call produzida por um provider é autorização automática de side effect.

O hard execution gate, preconditions de estado, validação de argumentos e replay idempotente de WRITE continuam invariantes da linha estável.

## Checklist

1. altere a constraint Composer de `^0.9` para `^1.0`;
2. confirme que sua integração usa APIs classificadas como `public`;
3. se usa símbolos `experimental`, revise `docs/api-stability.md`;
4. execute seus testes de domínio;
5. execute seus testes de WRITE/idempotência;
6. valide em PHP 8.4 ou PHP 8.5.
