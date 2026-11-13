# Mapa de Sprints — SAA / PI3-2026

## Sequência e situação documental

| Sprint | Período | Objetivo | Situação sustentada pelo repositório |
|---|---|---|---|
| 1 | 13–19/09/2026 | Descoberta e requisitos | Review histórica com entregas declaradas; issues principais encerradas entre 25/09 e 02/10 |
| 2 | 20–26/09/2026 | Requisitos não funcionais, modelagem e protótipo | Especificações presentes; `#20`, `#18`, `#21` ainda abertas na consulta de 04/11 |
| 3 | 27/09–03/10/2026 | Base técnica, cadastros, frequência | Planejamento histórico Flask/MongoDB; MVP PHP foi versionado em 27/10 |
| 4 | 04–10/10/2026 | Dashboard, justificativas, ocorrências e perfis | Parte das funções está no MVP PHP desde 27/10; dashboard/gráficos não localizados |
| 5 | 02–08/11/2026 | Integração, testes e homologação | Planejada/em andamento em 04/11; não há evidência de homologação concluída |
| 6 | 09–16/11/2026 | Documentação e entrega | Futura em 04/11; período adotado conforme review existente (outro planejamento citava 14/11) |

As datas de Sprints 1–4 se referem às **janelas planejadas**, não necessariamente ao momento da execução/merge. O commit de 27/10 não deve ser retroativamente declarado como entregue em setembro.

## Backlog de cada sprint

**Sprint 1:** [`#3`](https://github.com/Grupo-EVITUS/PI3-2026/issues/3), [`#4`](https://github.com/Grupo-EVITUS/PI3-2026/issues/4), [`#5`](https://github.com/Grupo-EVITUS/PI3-2026/issues/5), [`#7`](https://github.com/Grupo-EVITUS/PI3-2026/issues/7), [`#8`](https://github.com/Grupo-EVITUS/PI3-2026/issues/8), [`#9`](https://github.com/Grupo-EVITUS/PI3-2026/issues/9).

**Sprint 2:** [`#10`](https://github.com/Grupo-EVITUS/PI3-2026/issues/10), [`#20`](https://github.com/Grupo-EVITUS/PI3-2026/issues/20), [`#18`](https://github.com/Grupo-EVITUS/PI3-2026/issues/18), [`#21`](https://github.com/Grupo-EVITUS/PI3-2026/issues/21).

**Sprint 3:** os rótulos antigos `#31`–`#39` não representam as tarefas descritas. Os itens deste plano usam IDs internos `S3-01`–`S3-09`; relacionar as issues reais conforme [rastreabilidade](sprints/RASTREABILIDADE.md).

**Sprint 4:** [`#46`](https://github.com/Grupo-EVITUS/PI3-2026/issues/46) dashboard; [`#47`](https://github.com/Grupo-EVITUS/PI3-2026/issues/47) registro de justificativas; [`#48`](https://github.com/Grupo-EVITUS/PI3-2026/issues/48) gráficos; [`#49`](https://github.com/Grupo-EVITUS/PI3-2026/issues/49) consulta de ocorrências; [`#50`](https://github.com/Grupo-EVITUS/PI3-2026/issues/50) perfis; [`#21`](https://github.com/Grupo-EVITUS/PI3-2026/issues/21) protótipo de login (reaproveitado da Sprint 2).

**Sprint 5:** IDs de controle `S5-01`–`S5-06`. Issues relacionadas existentes: [`#30`](https://github.com/Grupo-EVITUS/PI3-2026/issues/30), [`#31`](https://github.com/Grupo-EVITUS/PI3-2026/issues/31), [`#33`](https://github.com/Grupo-EVITUS/PI3-2026/issues/33), [`#34`](https://github.com/Grupo-EVITUS/PI3-2026/issues/34), [`#35`](https://github.com/Grupo-EVITUS/PI3-2026/issues/35).

**Sprint 6:** IDs de controle `S6-01`–`S6-06`; ainda não há issues `#61`–`#66` no repositório auditado.

## Evidências de implementação encontradas

- [`273a2cf`](https://github.com/Grupo-EVITUS/PI3-2026/commit/273a2cf1a49da724159ed279383a74312376829d), 27/10: base PHP, SQLite, sessão e CSRF.
- [`c858e30`](https://github.com/Grupo-EVITUS/PI3-2026/commit/c858e30427444226e645df0a9777760a94804e60), 27/10: cadastros, ausências, justificativas, consultas e telas.
- [`260a850`](https://github.com/Grupo-EVITUS/PI3-2026/commit/260a85086e8fa102b4942b7c7b26c39ca87a043c), 27/10: fila de e-mails, SMTP configurável e testes escritos.
- [`a0e7781`](https://github.com/Grupo-EVITUS/PI3-2026/commit/a0e7781652ca76800add049e791f200fb3996c6a), 28/10: inclusão dos documentos de sprints.

Consulte as [pendências](sprints/PENDENCIAS.md) antes de marcar uma tarefa como concluída.
