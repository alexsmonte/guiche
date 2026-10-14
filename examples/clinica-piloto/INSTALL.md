# Instalação — Exemplo Clínico do Guichê v0.8

Este diretório é um **exemplo avançado de integração** do Guichê. Ele demonstra
como um domínio real pode usar Plugin API, StateManager, Tools, Presenters,
Tool Policy, Safety Layer, Result Projection, adapters e Fake Mode sem adicionar
regras clínicas ao Core público.

> O namespace `GuicheExample\ClinicaPiloto` é de exemplo/desenvolvimento. Ele
> não faz parte da API pública `Asmpkg\Guiche`.

## 1. Requisitos

Use os mesmos requisitos de PHP e Composer declarados no `composer.json` da
raiz do projeto.

Na raiz do repositório:

```bash
composer install
```

No ambiente PHP 8.4 em que existe um comando Composer específico, use o comando
equivalente configurado para essa versão.

## 2. Primeira execução: Fake Mode

O exemplo distribuído inicia em **Fake Mode**. Esse é o caminho recomendado para
avaliar o fluxo sem banco real, OpenAI real ou API real de agendamento.

Crie sua configuração local:

```bash
cp examples/clinica-piloto/.env.example examples/clinica-piloto/.env
```

O arquivo `.env` é local e não deve ser commitado.

Confirme no arquivo:

```dotenv
GUICHE_CLINICAL_MODE=fake
```

Execute o smoke test:

```bash
php examples/clinica-piloto/fake-smoke.php
```

O smoke test deve funcionar de forma determinística e offline usando:

- `FakeSqlExecutor`;
- `FakeHttpClient`;
- `FakeAiProvider`;
- `fixtures/clinical.php`.

## 3. Acceptance clínico

Na raiz do projeto:

```bash
bash bin/test-v08-clinical-acceptance.sh
```

Esse acceptance reúne PHPUnit clínico, Fake Mode, Write Safety e a regressão
da Safety Layer.

O quality gate completo continua sendo:

```bash
composer check
```

## 4. Real Mode

Real Mode é apenas um exemplo de integração. Não existem credenciais reais,
URLs privadas nem dados operacionais distribuídos neste diretório.

Edite somente seu `.env` local:

```dotenv
GUICHE_CLINICAL_MODE=real

OPENAI_API_KEY=
DB_DSN=
DB_HOST=
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

GUICHE_AGENDAMENTO_API_URL=
GUICHE_AGENDAMENTO_API_TOKEN=
GUICHE_AGENDAMENTO_API_TIMEOUT=15
```

Preencha os valores de acordo com o seu sistema.

Antes de ativar Real Mode, revise também os nomes das procedures configuradas
no `.env`/configuração clínica. Eles pertencem ao sistema integrador, não ao
Core do Guichê.

## 5. Segurança de escrita

O exemplo demonstra composição de duas camadas:

1. **Safety Layer genérica do Core**
   - Tool Policy;
   - estágio permitido;
   - preconditions;
   - confirmação;
   - `forbids_state`;
   - idempotência de WRITE;
   - Result Projection.

2. **Write Safety do domínio clínico**
   - tratamento de agendamento já existente;
   - revalidação final de disponibilidade;
   - fail closed quando a disponibilidade não pode ser confirmada;
   - zero POST quando o slot deixou de existir.

A revalidação do slot ocorre no caminho da Tool de escrita imediatamente antes
do side effect HTTP.

## 6. Isolamento arquitetural

O pacote público usa:

```text
Asmpkg\Guiche\  -> src/
```

O exemplo usa:

```text
GuicheExample\ClinicaPiloto\ -> examples/clinica-piloto/src/
```

O namespace do exemplo pertence ao `autoload-dev` e não pertence ao autoload público da biblioteca. Portanto, consumidores da
biblioteca não precisam conhecer `ClinicPlugin`, `ClinicStateManager`, convênio,
paciente, agenda ou qualquer outra semântica clínica.

## 7. Arquivos principais

```text
examples/clinica-piloto/
├── .env.example
├── README.md
├── INSTALL.md
├── fake-smoke.php
├── fixtures/
├── config/
└── src/
    ├── Adapters/
    ├── Presentation/
    ├── Safety/
    ├── Tools/
    ├── ClinicPlugin.php
    └── ClinicStateManager.php
```

## 8. O que não deve ser feito

- não mover classes clínicas para `src/`;
- não adicionar o namespace do exemplo ao `autoload` público;
- não commitar `.env`;
- não distribuir token, senha, CPF, endpoint privado ou nome de clínica real;
- não substituir a idempotência do Core por um mecanismo paralelo no exemplo;
- não executar WRITE quando a revalidação final falhar.

## 9. Diagnóstico rápido

Se o Fake Mode falhar:

```bash
php -l examples/clinica-piloto/fake-smoke.php
php examples/clinica-piloto/fake-smoke.php
bash bin/test-v08-clinical-acceptance.sh
composer check
```

Se Real Mode falhar, valide separadamente conexão de banco, procedures,
credenciais da IA e API de escrita. O Fake Mode deve continuar funcionando
independentemente dessas integrações.
