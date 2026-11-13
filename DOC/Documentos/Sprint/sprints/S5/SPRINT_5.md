# Sprint 5 — Integração e homologação

**Período:** 02/11/2026 a 08/11/2026  
**Situação na data da auditoria (04/11):** em janela de execução, sem evidência de homologação concluída.  
**Objetivo:** integrar e validar o fluxo principal do MVP executável em PHP/SQLite.

## Backlog — IDs de planejamento, não issues `#51–#56`

| ID interno | Tarefa | Issue real relacionada | Aceite |
|---|---|---|---|
| S5-01 | Integrar cadastro, ausência, comunicação e justificativa | [#30](https://github.com/Grupo-EVITUS/PI3-2026/issues/30), [#42](https://github.com/Grupo-EVITUS/PI3-2026/issues/42), [#43](https://github.com/Grupo-EVITUS/PI3-2026/issues/43), [#47](https://github.com/Grupo-EVITUS/PI3-2026/issues/47) | Fluxo completo demonstrado |
| S5-02 | Verificar permissões reais | [#31](https://github.com/Grupo-EVITUS/PI3-2026/issues/31), [#50](https://github.com/Grupo-EVITUS/PI3-2026/issues/50) | AOE/Coordenação/Diretor com acessos corretos |
| S5-03 | Executar testes e guardar evidências | [#33](https://github.com/Grupo-EVITUS/PI3-2026/issues/33) | Resultado do `php tests/run.php` registrado e testes manuais anexados |
| S5-04 | Corrigir falhas identificadas | [#34](https://github.com/Grupo-EVITUS/PI3-2026/issues/34) | Bugs registrados e corrigidos com commit |
| S5-05 | Validar configuração de envio de e-mails | [#29](https://github.com/Grupo-EVITUS/PI3-2026/issues/29), [#43](https://github.com/Grupo-EVITUS/PI3-2026/issues/43) | Diferenciar modo `log` e SMTP real; registrar sucesso/falha |
| S5-06 | Preparar homologação e definir pendências de escopo | [#35](https://github.com/Grupo-EVITUS/PI3-2026/issues/35), [#46](https://github.com/Grupo-EVITUS/PI3-2026/issues/46), [#48](https://github.com/Grupo-EVITUS/PI3-2026/issues/48) | Lista de itens aceitos e pendentes aprovada |

**Importante:** as issues `#51–#56` não existem na consulta realizada. O rótulo `S5-01` não é uma issue GitHub.

## Plano de testes / critérios de aceite

1. Subir aplicação com `php -S 127.0.0.1:8000 router.php` e worker com `php bin/process_mail_queue.php --watch`.
2. Executar `php tests/run.php` e salvar saída e ambiente (versão PHP/extensão SQLite).
3. Registrar ausência em lote; conferir unicidade por aluno/data.
4. Verificar comunicação no modo log e, se houver credenciais de homologação, envio SMTP real.
5. Abrir link individual, enviar justificativa e confirmar `RESPONDIDA`.
6. Confirmar restrições de acesso para AOE, COORDENACAO e DIRETOR.
7. Testar falhas de SMTP, reenvio e auditoria.
8. Decidir formalmente se dashboard/gráficos e filtros avançados são requisitos desta entrega: os itens [#46](https://github.com/Grupo-EVITUS/PI3-2026/issues/46), [#48](https://github.com/Grupo-EVITUS/PI3-2026/issues/48), [#49](https://github.com/Grupo-EVITUS/PI3-2026/issues/49) não estão integralmente atendidos no PHP.

Docker Compose + MongoDB apareciam no plano anterior. Não descrevê-los como validados na versão PHP sem um ambiente/commit específico.

## Definition of Done

Critério de aceite demonstrado, teste executado, evidência e responsável registrados; a homologação permanece pendente até validação formal.
