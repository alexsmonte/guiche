# Guichê v1.6.0 — Operational State & Runtime DX

## Resumo

A v1.6 reduz dependência de histórico textual e melhora runtime/DX sem quebrar a linha pública v1.

## Fases e commits

| Fase | Entrega | Commit |
| --- | --- | --- |
| 01 | baseline e gaps | `59b03150ead88a8c622e61dc26a2aa89a9e7ea57` |
| 02 | Operational State | `934daf3b2cfcedb2fabba87e3fd8323b9577b76d` |
| 03 | Clinical Interoperability | `68ab747d939fd0bb2f5c645f81841aee48a817dd` |
| 04 | Trusted Parameter Binding | `06ddf2dd29a3c0557218a63caba7f1a8652e82b8` |
| 05 | Context-aware Reusable Tools | `2720c68c885989810794dbbeefbf42696e43494a` |
| 06 | Session Lifecycle + TTL | `b750c02be6bc6c104837a24657fd656a32c07116` |
| 07 | Follow-up Runtime DX | `82d6b1ffaefd587a8ea55025c5746247ebb39411` |
| 08 | Multiple Prompts | `8abf8b1bdb739dd539c155b1b7374c1823ed8f84` |
| 09 | Declarative Loading | `ed80e86b1279af9df52533733d3ffdc7d309ab7f` |

## Freeze

```text
Public: 40
Experimental: 12
Internal: 19
SHA-256: 21f759500406f1fa5b1479a24a4ffdbb212c60133f034ba104e18290f11a8814
```

## Quality Gate

```text
391 testes
1959 assertions
PHPStan: 0 erros
coverage: 78,50%
threshold: 70%
```

## Publicação

Após o acceptance final verde: revisar commit, fast-forward de `main`, tag anotada `v1.6.0`, push de `main` e tag, e confirmação da instalação pública via Composer/Packagist.
