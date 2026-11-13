# Sprint Review 4 — Funcionalidades gerenciais e perfis

**Período planejado:** 04/10 a 10/10/2026  
**Data da review:** não documentada  
**Situação em 04/11:** não há evidência de homologação integral; parte do código PHP foi adicionada em 27/10.

## Objetivo

Dashboard gerencial, gráficos, justificativas, consulta de ocorrências, perfis e login.

## Resultado com issues corrigidas

| Issue | Entrega | Estado documentável |
|---|---|---|
| [#46](https://github.com/Grupo-EVITUS/PI3-2026/issues/46) | Dashboard gerencial | Não localizado no PHP; aberta |
| [#47](https://github.com/Grupo-EVITUS/PI3-2026/issues/47) | Registro de justificativas | Código localizado em 27/10; aberta; sem homologação registrada |
| [#48](https://github.com/Grupo-EVITUS/PI3-2026/issues/48) | Gráficos | Não localizados no PHP; aberta |
| [#49](https://github.com/Grupo-EVITUS/PI3-2026/issues/49) | Consulta de ocorrências | Listagem + status em PHP; filtros adicionais pendentes; aberta |
| [#50](https://github.com/Grupo-EVITUS/PI3-2026/issues/50) | Perfis | `AOE`, `COORDENACAO`, `DIRETOR` no código; aberta |
| [#21](https://github.com/Grupo-EVITUS/PI3-2026/issues/21) | Protótipo login | Login PHP disponível em 27/10; protótipo histórico não localizado; aberta |

O mapeamento anterior `#41`–`#46` era incorreto. Por exemplo, `#41` trata do vínculo aluno–turma–responsável; `#43` trata do envio de e-mails, não dos gráficos.

## Incremento e evidências

- [`c858e30`](https://github.com/Grupo-EVITUS/PI3-2026/commit/c858e30427444226e645df0a9777760a94804e60), 27/10: código de justificativa, consulta e perfis.
- [`273a2cf`](https://github.com/Grupo-EVITUS/PI3-2026/commit/273a2cf1a49da724159ed279383a74312376829d), 27/10: fundamentos de segurança e banco SQLite.

Não há evidência de que essas implementações foram demonstradas ou aprovadas durante a janela da Sprint 4 (04–10/10).

## Feedback, decisões e pendências

Sem feedback formal documentado. Confirmar com equipe se o backend de entrega é PHP/SQLite e registrar novo aceite. Antes de encerrar as issues, implementar dashboard/gráficos ou renegociar escopo, concluir filtros de ocorrências e executar testes por perfil.

## Próximo passo

Integrar funcionalidades, rodar testes e homologar na Sprint 5 sem antecipar uma aprovação não registrada.
