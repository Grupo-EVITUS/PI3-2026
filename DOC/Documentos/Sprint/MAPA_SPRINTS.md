# Mapa de Sprints — SAA / PI3-2026

Atualizado em **28/11/2026**. Os períodos abaixo são os do planejamento registrado. As evidências posteriores têm a data em que foram registradas no Git ou verificadas.

## Sequência e situação documental

| Sprint | Período | Objetivo | Situação sustentada pelo repositório |
|---|---|---|---|
| 1 | 13–19/09/2026 | Descoberta e requisitos | Review histórica com entregas declaradas; issues principais encerradas entre 25/09 e 02/10 |
| 2 | 20–26/09/2026 | Requisitos não funcionais, modelagem e protótipo | Especificações presentes. A auditoria de 04/11 registrou as issues `#20`, `#18` e `#21` abertas naquela data |
| 3 | 27/09–03/10/2026 | Base técnica, cadastros, frequência | Planejamento histórico Flask/MongoDB. O MVP em PHP/SQLite foi versionado em 27/10 e a migração para MongoDB foi implementada em novembro |
| 4 | 04–10/10/2026 | Dashboard, justificativas, ocorrências e perfis | Parte das funções aparece no MVP de 27/10. O dashboard de frequência e seus gráficos têm evidências em novembro |
| 5 | 02–08/11/2026 | Integração, testes e homologação | A auditoria de 04/11 registrou a sprint em andamento. Os testes locais de 28/11 constam na [review da Sprint 5](sprints/S5/review.md). O aceite da equipe segue sem confirmação |
| 6 | 09–16/11/2026 (provisório) | Documentação e entrega | O plano anterior também registra 14/11 como data final. A apresentação ocorreu em 27/11. A [review da Sprint 6](sprints/S6/review.md) reúne as evidências e mantém a data oficial de encerramento e o aceite pendentes |

O mapa tem um intervalo **sem janela de sprint registrada, de 11/10 a 01/11/2026**, entre as Sprints 4 e 5.

## Backlog de cada sprint

**Sprint 1:** [`#3`](https://github.com/Grupo-EVITUS/PI3-2026/issues/3), [`#4`](https://github.com/Grupo-EVITUS/PI3-2026/issues/4), [`#5`](https://github.com/Grupo-EVITUS/PI3-2026/issues/5), [`#7`](https://github.com/Grupo-EVITUS/PI3-2026/issues/7), [`#8`](https://github.com/Grupo-EVITUS/PI3-2026/issues/8), [`#9`](https://github.com/Grupo-EVITUS/PI3-2026/issues/9).

**Sprint 2:** [`#10`](https://github.com/Grupo-EVITUS/PI3-2026/issues/10), [`#20`](https://github.com/Grupo-EVITUS/PI3-2026/issues/20), [`#18`](https://github.com/Grupo-EVITUS/PI3-2026/issues/18), [`#21`](https://github.com/Grupo-EVITUS/PI3-2026/issues/21).

**Sprint 3:** os rótulos antigos `#31`–`#39` não representam as tarefas descritas. Os itens deste plano usam IDs internos `S3-01`–`S3-09`; relacionar as issues reais conforme [rastreabilidade](sprints/RASTREABILIDADE.md).

**Sprint 4:** dashboard, registro de justificativas, gráficos, consulta de ocorrências, perfis e protótipo de login. A issue historicamente associada ao dashboard hoje tem o título **Migrar o SAA para MongoDB, chamada completa e relatórios**. [#46](https://github.com/Grupo-EVITUS/PI3-2026/issues/46), [#47](https://github.com/Grupo-EVITUS/PI3-2026/issues/47), [#48](https://github.com/Grupo-EVITUS/PI3-2026/issues/48), [#49](https://github.com/Grupo-EVITUS/PI3-2026/issues/49), [#50](https://github.com/Grupo-EVITUS/PI3-2026/issues/50), [#21](https://github.com/Grupo-EVITUS/PI3-2026/issues/21).

**Sprint 5:** IDs de controle `S5-01`–`S5-06`. Issues relacionadas existentes: [`#30`](https://github.com/Grupo-EVITUS/PI3-2026/issues/30), [`#31`](https://github.com/Grupo-EVITUS/PI3-2026/issues/31), [`#33`](https://github.com/Grupo-EVITUS/PI3-2026/issues/33), [`#34`](https://github.com/Grupo-EVITUS/PI3-2026/issues/34), [`#35`](https://github.com/Grupo-EVITUS/PI3-2026/issues/35).

**Sprint 6:** IDs de controle do planejamento `S6-01`–`S6-06`.

## Evidências de implementação encontradas

- [`273a2cf`](https://github.com/Grupo-EVITUS/PI3-2026/commit/273a2cf1a49da724159ed279383a74312376829d), 27/10: base PHP, SQLite, sessão e CSRF.
- [`c858e30`](https://github.com/Grupo-EVITUS/PI3-2026/commit/c858e30427444226e645df0a9777760a94804e60), 27/10: cadastros, ausências, justificativas, consultas e telas.
- [`260a850`](https://github.com/Grupo-EVITUS/PI3-2026/commit/260a85086e8fa102b4942b7c7b26c39ca87a043c), 27/10: fila de e-mails, SMTP configurável e testes escritos.
- [`a0e7781`](https://github.com/Grupo-EVITUS/PI3-2026/commit/a0e7781652ca76800add049e791f200fb3996c6a), 28/10: inclusão dos documentos de sprints.
- Em 28/11, a versão em PHP e MongoDB ganhou ajustes de chamada e um gráfico de pizza de presença por turma. A publicação também incluiu a configuração Docker local, a marca SAA e a remoção do ZIP antigo e da pasta de relatórios de teste. [`08edb5a`](https://github.com/Grupo-EVITUS/PI3-2026/commit/08edb5ae50725bb7c1d45c331b68507027b15f6f), [`68c7942`](https://github.com/Grupo-EVITUS/PI3-2026/commit/68c7942dac9845f411762b05ff18c8fc85a7ec91), [`5dbe123`](https://github.com/Grupo-EVITUS/PI3-2026/commit/5dbe12312e8696af30f2ef8d9c5d40753ad8dbf2).

Os testes locais de 28/11 cobriram a execução em Docker, o fluxo de e-mail no Mailpit com justificativa e o push cifrado com serviço simulado. A entrega no Gmail e a exibição do push no aparelho ainda precisam de confirmação. A issue de homologação foi fechada em 28/11 e está sem comentários. [Revisão consolidada](review.md), [pendências](sprints/PENDENCIAS.md), [issue #35](https://github.com/Grupo-EVITUS/PI3-2026/issues/35).
