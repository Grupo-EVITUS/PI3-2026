# Documentação de sprints do SAA

Esta pasta reúne o **planejamento, as revisões e a rastreabilidade** das seis sprints do projeto PI3-2026 do Grupo EVITUS.

## Ponto de referência

- Repositório: https://github.com/Grupo-EVITUS/PI3-2026
- O planejamento das primeiras sprints previa **Flask e MongoDB**. O MVP auditado no commit [`a0e7781`](https://github.com/Grupo-EVITUS/PI3-2026/commit/a0e7781652ca76800add049e791f200fb3996c6a), de 28/10/2026, usava **PHP e SQLite**.
- A versão verificada em 28/11/2026 usa **PHP, MongoDB e Docker Compose**. As instruções de execução estão no [README principal](../../../README.md).
- As [evidências de integração](sprints/S5/review.md) e a [situação da entrega](sprints/S6/review.md) registram as verificações posteriores ao calendário original. A apresentação ocorreu em 27/11/2026. O feedback e o aceite formal aguardam registro da equipe.

## Onde encontrar

- [Mapa das seis sprints](MAPA_SPRINTS.md)
- [Revisão consolidada](review.md)
- [Índice das sprints](sprints/README.md)
- [Rastreabilidade de commits, issues e funcionalidades](sprints/RASTREABILIDADE.md)
- [Pendências e divergências](sprints/PENDENCIAS.md)

## Regras de documentação

1. Diferencie **planejado**, **documentado**, **código localizado**, **testado** e **homologado**.
2. Cite números `#N` apenas quando corresponderem a uma issue real do GitHub. Use `S3-01`, `S5-01` etc. para IDs internos, sem fingir que são issues.
3. Separe o período da sprint da data de commit que registrou a implementação.
4. Registre testes executados e aceite somente com evidências; não converta planos em entregas concluídas.
5. Não associe as limitações do material anterior (Flask/MongoDB, Docker e tokens expirados) ao MVP PHP sem verificação específica.
