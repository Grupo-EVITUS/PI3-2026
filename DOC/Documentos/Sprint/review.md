# Revisões consolidadas das Sprints 1–6 — SAA

**Conferência documental:** 04/11/2026, branch `main`, commit `a0e7781` (28/10). Esta consolidação distingue evidências históricas, implementação posterior e pendências de teste.

## Visão geral

| Sprint | Situação da documentação | Evidência verificável / ressalva |
|---|---|---|
| [1](sprints/S1/review.md) | Descoberta declarada como concluída | Issues #3, #4, #5, #7, #8 e #9 fechadas após a review de 19/09 |
| [2](sprints/S2/review.md) | Especificação histórica | Issue #10 fechada; #18, #20, #21 abertas; Flask/MongoDB é proposta antiga |
| [3](sprints/S3/review.md) | Implementação histórica planejada | Código equivalente parcialmente versionado somente em 27/10 (PHP/SQLite); sem evidência de review naquela data |
| [4](sprints/S4/review.md) | Funcionalidades gerenciais planejadas | Parte implementada em PHP em 27/10; dashboard e gráficos não encontrados |
| [5](sprints/S5/review.md) | Integração/homologação em andamento em 04/11 | Testes escritos, sem execução/homologação comprovada |
| [6](sprints/S6/review.md) | Futura em 04/11 | Documentos são planejamento, não resultado |

## Mudança arquitetural registrada

Documentos produzidos para a hipótese inicial **Flask + MongoDB + Docker** convivem com a implementação **PHP 8.1 + SQLite + HTML/CSS** dos commits de 27/10. A versão atual não possui, no PHP, dashboard de frequência com gráficos nem webhook de leitura de e-mails. Tokens de justificativa são individuais e guardados em hash, mas sem expiração configurada por padrão na v1.

## Commits principais

1. [`273a2cf`](https://github.com/Grupo-EVITUS/PI3-2026/commit/273a2cf1a49da724159ed279383a74312376829d), 27/10: base e esquema SQLite.
2. [`c858e30`](https://github.com/Grupo-EVITUS/PI3-2026/commit/c858e30427444226e645df0a9777760a94804e60), 27/10: interface e fluxos de ausência/justificativa.
3. [`260a850`](https://github.com/Grupo-EVITUS/PI3-2026/commit/260a85086e8fa102b4942b7c7b26c39ca87a043c), 27/10: fila de e-mail e testes escritos.
4. [`a0e7781`](https://github.com/Grupo-EVITUS/PI3-2026/commit/a0e7781652ca76800add049e791f200fb3996c6a), 28/10: inclusão dos documentos de sprint.

## Correções de rastreabilidade

Os arquivos anteriores da Sprint 3 utilizavam `#31–#39` para tarefas diferentes das issues reais. Os da Sprint 4 utilizavam `#41–#46` para dashboard, justificativas, gráficos, consulta, perfis e login. A correspondência correta deste segundo grupo é **#46, #47, #48, #49, #50, #21**. As referências `#51–#66` das Sprints 5 e 6 não existem; foram substituídas por identificadores internos.

Ver a tabela de rastreabilidade em [RASTREABILIDADE.md](sprints/RASTREABILIDADE.md) e as [pendências](sprints/PENDENCIAS.md). Não confundir um teste escrito com um teste executado ou uma issue aberta com uma entrega homologada.
