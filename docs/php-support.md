# PHP Support Policy

## Release Candidate v0.9.x

O Guichê declara no Composer:

```json
"php": "^8.4"
```

A matriz oficial de validação da Release Candidate é:

| PHP | Status na RC | Observação |
|---|---|---|
| 8.4 | Suportado | baseline mínimo e runtime principal |
| 8.5 | Suportado | segunda linha estável da matriz |
| 8.6+ | Não validado nesta RC | a constraint `^8.4` não bloqueia futuras versões 8.x, mas suporte oficial depende de validação explícita |

## Política

- O código público da v1 deve passar em PHP 8.4 e PHP 8.5.
- `composer.json` permanece com `^8.4`.
- Uma versão futura do PHP não é considerada oficialmente validada apenas porque satisfaz a constraint.
- A matriz CI da RC/stable deve executar os gates do projeto em todas as versões PHP oficialmente suportadas.
- Mudança do PHP mínimo é decisão SemVer/documentada e não deve ocorrer silenciosamente.

## Validações da Fase 03

A Fase 03 executa:

1. `composer validate --strict`;
2. `composer check` em PHP 8.4;
3. instalação limpa do pacote distribuível como dependência Composer em PHP 8.4;
4. resolução Composer simulando plataforma PHP 8.5;
5. quando `php8.5` estiver instalado localmente, instalação e smoke test reais também em PHP 8.5.

A execução real e contínua da matriz 8.4/8.5 será consolidada no CI da Release Candidate.
