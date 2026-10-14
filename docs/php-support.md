# PHP Support Policy

## Linha estável v1.x

O Guichê declara no Composer:

```json
"php": "^8.4"
```

A matriz oficial da linha v1 é:

| PHP | Status | Observação |
|---|---|---|
| 8.4 | Suportado | baseline mínimo |
| 8.5 | Suportado | validado em CI real |
| 8.6+ | Ainda não validado oficialmente | a constraint `^8.4` permite futuras versões 8.x, mas suporte oficial exige validação explícita |

## Política

- Toda release da linha v1 deve passar em PHP 8.4 e PHP 8.5.
- `composer.json` permanece com `^8.4`.
- Uma versão futura do PHP não é considerada oficialmente suportada apenas porque satisfaz a constraint.
- A matriz CI executa os gates do projeto em todas as versões PHP oficialmente suportadas.
- Mudança do PHP mínimo exige decisão SemVer/documentada e não ocorre silenciosamente.

## Gates executados na matriz suportada

A CI oficial executa, em PHP 8.4 e PHP 8.5:

1. `composer validate --strict`;
2. resolução das dependências;
3. `composer audit`;
4. `composer check`;
5. smoke do pacote distribuível.

A v0.9.0 e o mesmo SHA promovido para a preparação da v1 foram validados em ambos os runtimes antes do início do fechamento estável.
