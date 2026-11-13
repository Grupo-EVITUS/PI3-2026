# Documentação de sprints — Sistema de Ausência de Alunos (SAA)

Esta pasta contém o **planejamento, as revisões e a rastreabilidade** das seis sprints do projeto PI3-2026 do Grupo EVITUS. Ela não contém uma segunda aplicação executável.

## Ponto de referência

- Repositório: https://github.com/Grupo-EVITUS/PI3-2026
- Referência auditada: branch `main`, commit [`a0e7781`](https://github.com/Grupo-EVITUS/PI3-2026/commit/a0e7781652ca76800add049e791f200fb3996c6a) (28/10/2026).
- Implementação executável localizada na `main`: **PHP 8.1+, HTML/CSS e SQLite**, com fila de e-mails e testes escritos em PHP. Consultar o [README principal](../README.md).
- Documentos históricos das Sprints 2 a 4 descrevem uma proposta **Flask + MongoDB**, conservada como histórico de planejamento. Não devem ser utilizados para descrever a arquitetura atualmente executável.
- A existência de código ou teste automatizado não equivale à homologação. Nenhuma execução local foi comprovada nesta revisão documental.

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
