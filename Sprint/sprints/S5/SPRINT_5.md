# Sprint 5 — Planejamento

## Identificação

Projeto: SAA (Sistema de Ausência de Alunos)  
Sprint: 05  
Período: 02/11/2026 a 08/11/2026

## 1. Sprint Goal

Integrar as funcionalidades desenvolvidas nas Sprints anteriores e realizar a homologação do sistema com testes de aceitação.

## 2. Backlog da Sprint

| Item | Entrega | Prioridade | Status |
|---|---|---|---|
| SAA-51 / #51 | Integrar dashboard, ocorrências e justificativas | Alta | Planejado |
| SAA-52 / #52 | Validar permissões por perfil | Alta | Planejado |
| SAA-53 / #53 | Executar testes de aceitação | Alta | Planejado |
| SAA-54 / #54 | Corrigir inconsistências encontradas | Alta | Planejado |
| SAA-55 / #55 | Validar execução completa com Docker | Média | Planejado |
| SAA-56 / #56 | Preparar ambiente de homologação | Média | Planejado |

## 3. Critérios de aceitação

- O fluxo de ausência até justificativa funciona de ponta a ponta.
- Dashboard, ranking e filtros exibem dados consistentes.
- AOE, COORDINATION e ADMIN possuem permissões corretas.
- O sistema funciona com MongoDB e Docker Compose.
- Os testes de aceitação possuem resultados registrados.

## 4. Casos de teste

- Login por perfil.
- Cadastro de ausência.
- Consulta de ocorrência.
- Envio de justificativa.
- Atualização de status.
- Filtros mensal e anual.
- Reinício dos containers sem perda de dados.

## 5. Definition of Done

A tarefa será concluída quando estiver integrada, testada, documentada e validada no ambiente de homologação.
