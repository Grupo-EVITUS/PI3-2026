# Sprint Review 2 — Requisitos, modelagem e protótipo

**Período:** 20/09 a 26/09/2026  
**Data da review:** não documentada  
**Situação auditada em 04/11:** documentação histórica disponível, mas o fechamento dos quatro itens não está integralmente confirmado no GitHub.

## Objetivo e resultados

| Issue | Entrega | Evidência e situação |
|---|---|---|
| [#10](https://github.com/Grupo-EVITUS/PI3-2026/issues/10) | Requisitos não funcionais | Documentados; issue fechada em 03/10 |
| [#20](https://github.com/Grupo-EVITUS/PI3-2026/issues/20) | Modelagem de dados | Especificação MongoDB histórica; issue aberta, implementação atual SQLite |
| [#18](https://github.com/Grupo-EVITUS/PI3-2026/issues/18) | Diagrama de classes | Existe JPG em `Diagramas/`; não comprova aderência ao PHP; issue aberta |
| [#21](https://github.com/Grupo-EVITUS/PI3-2026/issues/21) | Protótipo de login | Tela PHP existe desde 27/10; arquivo de protótipo descrito não encontrado; issue aberta |

## Incremento e decisão técnica

O planejamento histórico adotou Flask/MongoDB, Docker, link temporário de uso único e perfis `AOE`, `COORDINATION` e `ADMIN`. **Na `main` atual**, o código utiliza PHP/SQLite, `AOE`, `COORDENACAO` e `DIRETOR`; links públicos são individuais e a primeira justificativa encerra a ocorrência, mas os tokens não expiram por padrão. Registrar a mudança de arquitetura no histórico em vez de atribuir as duas implementações à mesma entrega.

## Feedback de stakeholders

Sem evidência de feedback formal individual para esta review. Não preencher como validado.

## Pendências

Confirmar modelagem-alvo, revisar o diagrama, validar a tela de login e decidir se a expiração de tokens será requisito obrigatório. Após o aceite, atualizar as issues abertas com evidências.

## Próxima etapa

Implementação da base, cadastros e ausências, com identificação dos commits e datas efetivos.
