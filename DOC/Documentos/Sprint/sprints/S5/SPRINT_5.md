# Sprint 5 — Integração e homologação

**Período:** 02/11/2026 a 08/11/2026  
**Situação na data da auditoria (04/11):** em janela de execução, sem evidência de homologação concluída.  
**Objetivo original:** integrar e validar o fluxo principal do MVP PHP/SQLite. A [review da Sprint 5](review.md) registra as verificações posteriores na versão PHP/MongoDB.

## Backlog com IDs de planejamento

| ID interno | Tarefa | Issue real relacionada | Aceite |
|---|---|---|---|
| S5-01 | Integrar cadastro, ausência, comunicação e justificativa | [#30](https://github.com/Grupo-EVITUS/PI3-2026/issues/30), [#42](https://github.com/Grupo-EVITUS/PI3-2026/issues/42), [#43](https://github.com/Grupo-EVITUS/PI3-2026/issues/43), [#47](https://github.com/Grupo-EVITUS/PI3-2026/issues/47) | Fluxo completo demonstrado |
| S5-02 | Verificar permissões reais | [#31](https://github.com/Grupo-EVITUS/PI3-2026/issues/31), [#50](https://github.com/Grupo-EVITUS/PI3-2026/issues/50) | AOE/Coordenação/Diretor com acessos corretos |
| S5-03 | Executar testes e guardar evidências | [#33](https://github.com/Grupo-EVITUS/PI3-2026/issues/33) | Comando, ambiente e resultado dos testes registrados |
| S5-04 | Corrigir falhas identificadas | [#34](https://github.com/Grupo-EVITUS/PI3-2026/issues/34) | Bugs registrados e corrigidos com commit |
| S5-05 | Validar configuração de envio de e-mails | [#29](https://github.com/Grupo-EVITUS/PI3-2026/issues/29), [#43](https://github.com/Grupo-EVITUS/PI3-2026/issues/43) | Diferenciar modo `log` e SMTP real; registrar sucesso/falha |
| S5-06 | Preparar homologação e definir pendências de escopo | [#35](https://github.com/Grupo-EVITUS/PI3-2026/issues/35), [#46](https://github.com/Grupo-EVITUS/PI3-2026/issues/46), [#48](https://github.com/Grupo-EVITUS/PI3-2026/issues/48) | Lista de itens aceitos e pendentes aprovada |

## Plano de testes para a versão de entrega

1. Subir o site e os workers com Docker Compose conforme o [README principal](../../../../../README.md).
2. Executar a [suíte PHP/MongoDB](../../../../../README.md#testes) e registrar os comandos, o ambiente e os resultados.
3. Registrar ausência em lote; conferir unicidade por aluno/data.
4. Verificar o e-mail no Mailpit e a entrega de push no ambiente de teste. Validar SMTP externo e exibição no aparelho quando o ambiente estiver configurado.
5. Abrir link individual, enviar justificativa e confirmar `RESPONDIDA`.
6. Confirmar restrições de acesso para AOE, COORDENACAO e DIRETOR.
7. Testar falhas de SMTP, reenvio e auditoria.
8. Conferir o relatório de frequência, os gráficos e os filtros de ocorrências na versão demonstrada.

Os resultados documentados, com data e ambiente, estão na [review da Sprint 5](review.md).

## Definition of Done

Critério de aceite demonstrado, teste executado, evidência e responsável registrados; a homologação permanece pendente até validação formal.
