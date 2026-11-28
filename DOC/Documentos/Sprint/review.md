# Revisões consolidadas das Sprints 1–6 — SAA

**Conferência documental inicial em 04/11/2026.** A referência foi o commit `a0e7781` de 28/10. A atualização de **28/11/2026** inclui os registros posteriores à conferência e aos períodos planejados das sprints.

## Visão geral

| Sprint | Situação da documentação | Evidência verificável / ressalva |
|---|---|---|
| [1](sprints/S1/review.md) | Descoberta declarada como concluída | Issues #3, #4, #5, #7, #8 e #9 fechadas após a review de 19/09 |
| [2](sprints/S2/review.md) | Especificação histórica | Na consulta de 04/11, a issue #10 estava fechada e #18, #20 e #21 estavam abertas |
| [3](sprints/S3/review.md) | Implementação histórica planejada | Parte das funcionalidades apareceu no MVP PHP/SQLite de 27/10. A review da janela de setembro e início de outubro permanece sem confirmação |
| [4](sprints/S4/review.md) | Funcionalidades gerenciais planejadas | Parte das funções aparece no MVP de 27/10. O dashboard de frequência e os gráficos têm evidência de implementação em novembro |
| [5](sprints/S5/review.md) | Integração e testes | Em andamento na auditoria de 04/11. A validação local de 28/11 registrou resultados posteriores ao período planejado da sprint |
| [6](sprints/S6/review.md) | Documentação e entrega | Apresentação realizada em 27/11. A versão publicada e testada localmente em 28/11 está registrada abaixo. Feedback e aceite seguem pendentes |

## Histórico e versão atual

A proposta inicial previa Flask e MongoDB. O MVP de 27/10 usava PHP, SQLite e HTML/CSS. A versão publicada em 28/11 usa **PHP com MongoDB** e oferece execução local com **Docker Compose**, incluindo os processos de e-mail e push. O dashboard de frequência apresenta gráficos, incluindo a pizza de presença por turma. [README principal](../../../README.md), [`08edb5a`](https://github.com/Grupo-EVITUS/PI3-2026/commit/08edb5ae50725bb7c1d45c331b68507027b15f6f), [`68c7942`](https://github.com/Grupo-EVITUS/PI3-2026/commit/68c7942dac9845f411762b05ff18c8fc85a7ec91).

## Validação local em 28/11/2026

| Verificação | Resultado |
|---|---|
| Subida do ambiente Docker | Aplicação e serviços locais iniciados |
| E-mail e justificativa | Mensagem recebida no Mailpit e justificativa registrada pelo link |
| Push | O teste de integração passou com entrega cifrada no serviço simulado e justificativa pelo link. Em teste anterior, o serviço real aceitou o envio |
| Entrega ao destinatário | Entrega na caixa do Gmail e exibição do push no aparelho permanecem sem confirmação |

A apresentação ocorreu em 27/11, conforme informado pela equipe. Permanecem por registrar o commit apresentado, o feedback, o aceite e a data oficial de encerramento da Sprint 6. O mapa usa 16/11 provisoriamente e registra a divergência com 14/11 no plano anterior. A issue #35 foi fechada em 28/11 e está sem comentários. [Issue #35](https://github.com/Grupo-EVITUS/PI3-2026/issues/35) · [Mapa das sprints](MAPA_SPRINTS.md)

## Commits principais

1. [`273a2cf`](https://github.com/Grupo-EVITUS/PI3-2026/commit/273a2cf1a49da724159ed279383a74312376829d), 27/10: base e esquema SQLite.
2. [`c858e30`](https://github.com/Grupo-EVITUS/PI3-2026/commit/c858e30427444226e645df0a9777760a94804e60), 27/10: interface e fluxos de ausência/justificativa.
3. [`260a850`](https://github.com/Grupo-EVITUS/PI3-2026/commit/260a85086e8fa102b4942b7c7b26c39ca87a043c), 27/10: fila de e-mail e testes escritos.
4. [`a0e7781`](https://github.com/Grupo-EVITUS/PI3-2026/commit/a0e7781652ca76800add049e791f200fb3996c6a), 28/10: inclusão dos documentos de sprint.
5. [`08edb5a`](https://github.com/Grupo-EVITUS/PI3-2026/commit/08edb5ae50725bb7c1d45c331b68507027b15f6f), 28/11: ajustes de chamada, opção de desfazer falta e gráfico de presença por turma.
6. [`68c7942`](https://github.com/Grupo-EVITUS/PI3-2026/commit/68c7942dac9845f411762b05ff18c8fc85a7ec91), 28/11: execução local com Docker e aplicação da marca SAA.
7. [`5dbe123`](https://github.com/Grupo-EVITUS/PI3-2026/commit/5dbe12312e8696af30f2ef8d9c5d40753ad8dbf2), 28/11: remoção do ZIP antigo e da pasta de relatórios de teste.

## Correções de rastreabilidade

Os arquivos anteriores da Sprint 3 utilizavam `#31–#39` para tarefas diferentes das issues registradas. Na Sprint 4, dashboard, justificativas, gráficos, consulta, perfis e login correspondem historicamente a **#46, #47, #48, #49, #50 e #21**. Em 28/11, o título registrado para a issue #46 era **Migrar o SAA para MongoDB, chamada completa e relatórios**. Os itens de planejamento das Sprints 5 e 6 usam identificadores internos. [Issue #46](https://github.com/Grupo-EVITUS/PI3-2026/issues/46).

Os registros detalhados estão na [rastreabilidade](sprints/RASTREABILIDADE.md) e nas [pendências](sprints/PENDENCIAS.md).
