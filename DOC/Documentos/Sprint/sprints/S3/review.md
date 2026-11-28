# Sprint Review 3 — Implementação e validação da base

**Período planejado:** 27/09 a 03/10/2026  
**Data da review:** não documentada  
**Situação auditada em 04/11:** review histórica estava apenas planejada. Código equivalente a parte das tarefas apareceu em commits de 27/10.

## Objetivo

Validar persistência, cadastro, ausência diária, notificações, justificativas e consultas do SAA.

## Resultado verificado no histórico Git

- [`273a2cf`](https://github.com/Grupo-EVITUS/PI3-2026/commit/273a2cf1a49da724159ed279383a74312376829d), **27/10**: esquema SQLite, dados de demonstração, sessões e CSRF.
- [`c858e30`](https://github.com/Grupo-EVITUS/PI3-2026/commit/c858e30427444226e645df0a9777760a94804e60), **27/10**: fluxo de ausências, justificativas, cadastros e interface.
- [`260a850`](https://github.com/Grupo-EVITUS/PI3-2026/commit/260a85086e8fa102b4942b7c7b26c39ca87a043c), **27/10**: fila de e-mails e testes automatizados escritos.

Os três commits são posteriores ao período da sprint e **não comprovam demonstração em 03/10**.

## Itens de trabalho

| Referência | Situação |
|---|---|
| S3-01 a S3-04 (seed, persistência, cadastros, ausências) | Implementação PHP localizada em 27/10; execução e aceite não comprovados |
| S3-05 (dashboard/ranking) | Não localizado no MVP PHP |
| S3-06 e S3-07 (vínculos e justificativas) | Implementação PHP parcial/localizada; token não expira por padrão |
| S3-08 (webhook) | Não localizado; SMTP não equivale a webhook |
| S3-09 (Docker) | Não comprovado no MVP PHP |

## Feedback e aceite

Não consta registro de apresentação, feedback formal ou execução de testes nesta review histórica. Os testes do MVP SQLite ficavam em `tests/run.php`. As evidências posteriores da versão PHP/MongoDB estão na [review da Sprint 5](../S5/review.md).

## Próximos passos

O plano para a Sprint 4 previa consolidar o backlog, desenvolver o dashboard e os gráficos e verificar o fluxo de comunicação com SMTP real. Consulte a [review consolidada](../../review.md) para a situação na data do encerramento.
