# Changelog

Todas as mudanças relevantes deste projeto serão registradas neste arquivo.

O formato segue a ideia de **Keep a Changelog** e o versionamento do projeto segue **Semantic Versioning**.

## [0.9.0] - Unreleased

> Release Candidate preparada localmente. A publicação permanece bloqueada até a matriz CI oficial em PHP 8.4 e PHP 8.5 ficar verde no GitHub.

### Added

- classificação formal da superfície de API em Public, Experimental e Internal;
- baseline de compatibilidade da API pública e checker de BC integrado ao `composer check`;
- política pública de estabilidade e SemVer;
- suporte/documentação de distribuição Composer para a RC;
- guia completo de consumidor externo;
- `SECURITY.md` com política de reporte e invariantes de segurança;
- workflow GitHub Actions com matriz PHP 8.4/8.5;
- `composer audit` e smoke de distribuição na CI;
- acceptance externo com Tool, Plugin, ContextStore e AiProvider customizados;
- artefatos e manifestos de release reproduzíveis.

### Changed

- documentação pública reorganizada para apresentar o Guichê como Core genérico;
- instalação/aceite externo passa a validar uma dependência Composer real, sem symlink;
- pacote distribuído exclui arquivos de desenvolvimento, auditorias internas, storage e arquivos de ambiente;
- o clinical scheduling permanece exemplo de domínio e não faz parte da API pública do Core.

### Security

- hard execution gate continua obrigatório imediatamente antes de `ToolInterface::execute()`;
- Tool call gerada pelo provider não equivale a autorização de side effect;
- WRITE permanece sem retry/cache genérico;
- replay idempotente de WRITE continua explicitamente persistido;
- result projection limita dados enviados ao provider;
- observabilidade continua sem persistir payload bruto sensível;
- CI opera com `contents: read`, sem secrets e sem `pull_request_target`;
- Actions externas são fixadas em SHAs completos;
- revisão local não encontrou `.env`, chave privada ou padrão de segredo de alta confiança versionado.

### Compatibility

- constraint do pacote: PHP `^8.4`;
- matriz oficial da RC: PHP 8.4 e PHP 8.5;
- PHP 8.5 real será considerado validado somente quando o job remoto correspondente ficar verde.

### API stability

- Public: 24 símbolos;
- Experimental: 11 símbolos;
- Internal: 11 símbolos;
- SHA-256 da API pública congelada: `39e417149aaee5ac9008de4bb3e477c579826f86ea9902b8755965fc1482368d`.

### Known release blocker

- CI remoto oficial ainda não executado para a branch RC; publicação/tag final não deve ocorrer enquanto PHP 8.4 e PHP 8.5 não estiverem verdes.
