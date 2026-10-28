# Sprint Review

## Identificação

Projeto: SAA (Sistema de Ausência de Alunos)  
Sprint: 03  
Período: 27/09/2026 a 03/10/2026  
Data da Sprint Review: Não informada  
Status: Planejada

## 1. Sprint Goal

Objetivo da Sprint: implementar e validar uma base executável do SAA com dados fictícios, conexão MongoDB, CRUD da AOE, frequência diária, dashboard, ranking e fluxo inicial de justificativa.

> A Sprint 3 transforma a especificação produzida na Sprint 2 em um ambiente testável. O foco será comprovar a persistência dos dados, a prevenção de duplicidades e o funcionamento das consultas principais.

**Resultado esperado:** permitir que a equipe execute o sistema, faça login, consulte alunos, registre ausências e visualize os indicadores do dashboard.

---

## 2. Resultado da Sprint

### Itens concluídos

| Item | Descrição | Status | DoD atendida? |
|---|---|---|---|
| SAA-31 / #31 | Criar seed de dados fictícios | Planejado | Não se aplica ainda |
| SAA-32 / #32 | Validar conexão e índices MongoDB | Planejado | Não se aplica ainda |
| SAA-33 / #33 | Testar CRUD da AOE | Planejado | Não se aplica ainda |
| SAA-34 / #34 | Testar registro diário de frequência | Planejado | Não se aplica ainda |
| SAA-35 / #35 | Validar dashboard e ranking | Planejado | Não se aplica ainda |
| SAA-36 / #36 | Validar contexto de notificação | Planejado | Não se aplica ainda |
| SAA-37 / #37 | Testar link de justificativa | Planejado | Não se aplica ainda |
| SAA-38 / #38 | Preparar webhook de e-mail | Planejado | Não se aplica ainda |
| SAA-39 / #39 | Documentar execução Docker | Planejado | Não se aplica ainda |

### Itens não concluídos

| Item | Motivo | Próxima ação |
|---|---|---|
| Todos os itens da Sprint 3 | Sprint ainda planejada, aguardando execução e validação. | Executar o ambiente e registrar os resultados dos testes. |

---

## 3. Incremento apresentado

Funcionalidades/artefatos previstos:

- Seed com alunos, turmas, responsáveis, matrículas e frequências fictícias.
- CRUD autenticado da AOE.
- Prevenção de frequência duplicada.
- Dashboard preenchido.
- Ranking geral, mensal e anual.
- Busca automática de aluno, faltas e responsáveis.
- Link de justificativa.
- Documentação de execução Docker.

Link da aplicação: A preencher após a execução do ambiente.

Link do repositório: https://github.com/Grupo-EVITUS/PI3-2026

---

## 4. Feedback dos Stakeholders

A preencher após a demonstração do ambiente executável.

| Feedback | Origem | Impacto |
|---|---|---|
| A preencher | A preencher | A preencher |

---

## 5. Novas necessidades identificadas

A preencher após os testes e a apresentação da Sprint.

---

## 6. Alterações no Product Backlog

| Item | Alteração | Prioridade anterior | Nova prioridade |
|---|---|---|---|
| Seed de dados | Necessário para demonstrar o sistema | Não definida | Alta |
| Testes de integração | Necessários para validar o MongoDB | Não definida | Alta |
| Dashboard e ranking | Necessários para acompanhamento | Não definida | Alta |
| Justificativas | Necessárias para fechar o fluxo de comunicação | Alta | Alta |
| Webhook de e-mail | Depende do provedor escolhido | Média | Média |

---

## 7. Decisões

- Os dados utilizados nos testes serão fictícios.
- O envio real de e-mail só será considerado concluído após configuração do provedor.
- O ranking não poderá duplicar alunos por causa de múltiplos responsáveis.
- O histórico de frequência não será apagado.

---

## 8. Próximos passos

- Integrar funcionalidades gerenciais.
- Implementar consulta de ocorrências.
- Implementar perfis de usuário.
- Revisar a tela de login.
- Iniciar a Sprint 4.
