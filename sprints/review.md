# Revisões dos Sprints — SAA

> Documento consolidado das Sprints 1 a 4 do Sistema de Ausência de Alunos. Os itens já realizados estão marcados como concluídos; os itens futuros permanecem como planejados para não registrar uma execução que ainda não foi demonstrada.

## Índice

- [Sprint 1 — Descoberta e requisitos iniciais](#sprint-1--descoberta-e-requisitos-iniciais)
- [Sprint 2 — Especificação técnica e prototipação](#sprint-2--especificação-técnica-e-prototipação)
- [Sprint 3 — Implementação e validação da base](#sprint-3--implementação-e-validação-da-base)
- [Sprint 4 — Funcionalidades gerenciais e perfis](#sprint-4--funcionalidades-gerenciais-e-perfis)
- [Mapa de status](#mapa-de-status)

---

# Sprint 1 — Descoberta e requisitos iniciais

## Identificação

**Projeto:** SAA (Sistema de Ausência de Alunos)  
**Sprint:** 01  
**Período:** 13/09/2026 a 19/09/2026  
**Data da Sprint Review:** 19/09/2026  
**Repositório:** [Grupo-EVITUS/PI3-2026](https://github.com/Grupo-EVITUS/PI3-2026)

## 1. Objetivo de Sprint

O objetivo da Sprint 1 foi identificar o público-alvo, os stakeholders, as principais dores e necessidades dos usuários, mapear a jornada do usuário e levantar os requisitos funcionais iniciais do SAA.

Foi identificado que o sistema auxiliará a AOE no registro e na comunicação das faltas dos alunos aos responsáveis. O problema principal é que os professores comunicam as faltas manualmente, gastando tempo, enquanto os responsáveis nem sempre sabem que o aluno não entrou em sala. O SAA organiza esse processo: a AOE registra a ausência e o sistema prepara a comunicação ao responsável.

Os stakeholders identificados foram professores, coordenação, direção, pais ou responsáveis e AOE. As personas diretas são a AOE, o responsável pelo aluno e a coordenação. A professora participa do processo, mas não será usuária direta: ela informa os alunos ausentes pelo grupo interno da escola.

## 2. Resultado da Sprint

### Itens concluídos

| Item | Descrição | Status | Feedback registrado |
|---|---|---|---|
| SAA-3 / #3 | Pesquisar o contexto do problema | Concluído | Sim |
| SAA-4 / #4 | Identificar as principais dores dos usuários | Concluído | Sim |
| SAA-5 / #5 | Identificar as partes interessadas | Concluído | Sim |
| SAA-7 / #7 | Mapear a jornada do usuário | Concluído | Sim |
| SAA-8 / #8 | Levantar necessidades dos usuários | Concluído | Sim |
| SAA-9 / #9 | Levantar requisitos funcionais | Concluído | Sim |

### Itens não concluídos

Nenhum. Todos os itens planejados para a Sprint 1 foram concluídos.

## 3. Incremento apresentado

Foram apresentados o contexto do problema, stakeholders, jornada do usuário, necessidades, requisitos funcionais `RF01` a `RF31` e histórias de usuário `US01` a `US16`.

**Link da aplicação:** não se aplica à Sprint 1, pois a entrega foi de descoberta e definição do projeto.

## 4. Feedback das partes interessadas

| Opinião ou necessidade | Origem | Impacto |
|---|---|---|
| Reduzir o trabalho manual de comunicação das faltas | Professores | Alto |
| Saber se o aluno está presente na escola | Pais e responsáveis | Alto |
| Registrar ausências e automatizar o envio | AOE | Alto |
| Centralizar ocorrências e justificativas | Coordenação | Alto |

## 5. Novas necessidades identificadas

- Cadastrar faltas pela AOE;
- enviar e-mail ao responsável;
- incluir link para formulário de justificativa;
- oferecer motivos predefinidos e opção “Outro”;
- controlar o status da ocorrência;
- registrar o histórico das comunicações;
- permitir acompanhamento por aluno, turma e período.

## 6. Alterações no Product Backlog

| Item | Motivo | Nova prioridade |
|---|---|---|
| Registro de ausência | Etapa principal do sistema | Alta |
| Localização de turma e aluno | Agiliza o lançamento | Alta |
| Comunicação automática por e-mail | Substitui a comunicação manual | Alta |
| Link de justificativa | Permite resposta do responsável | Alta |
| Registro da justificativa e status | Permite acompanhamento | Alta |
| Acompanhamento pela coordenação | Centraliza pendências e ocorrências | Alta |
| Pesquisa histórica avançada | Prevista para versão futura | Média |

## 7. Decisões

- A professora não será usuária direta e não haverá integração com WhatsApp.
- A AOE receberá as informações e registrará as ausências.
- A comunicação será feita por e-mail com link de justificativa.
- A coordenação acompanhará ocorrências e justificativas.

## 8. Próximos passos

- Requisitos não funcionais (`#10`);
- modelagem de dados (`#20`);
- diagrama de classes (`#18`);
- protótipo da tela de login (`#21`).

---

# Sprint 2 — Especificação técnica e prototipação

## Identificação

**Projeto:** SAA (Sistema de Ausência de Alunos)  
**Sprint:** 02  
**Período:** 20/09/2026 a 26/09/2026  
**Data da Sprint Review:** não informada

## 1. Objetivo de Sprint

O objetivo da Sprint 2 foi transformar as decisões da Sprint 1 em especificações técnicas: requisitos não funcionais, requisitos da modelagem MongoDB, diagrama de classes e protótipo da tela de login.

## 2. Resultado da Sprint

### Itens concluídos/documentados

| Item | Descrição | Status |
|---|---|---|
| SAA-10 / #10 | Levantar os requisitos não funcionais | Concluído |
| SAA-20 / #20 | Planejar os requisitos da modelagem de dados | Concluído |
| SAA-18 / #18 | Desenvolver o diagrama de classes | Concluído |
| SAA-21 / #21 | Criar o protótipo da tela de login | Concluído |

### Artefatos produzidos

- `SPRINT_2.md`;
- `diagrama_classes_saa.mmd` e `diagrama_classes_saa.png`;
- `PROTOTIPO_LOGIN_SPRINT_2.md`;
- `views/templates/aoe_login.html`;
- `views/static/css/saa.css`.

## 3. Incremento apresentado

- requisitos de segurança, privacidade, desempenho, disponibilidade e usabilidade;
- collections MongoDB, referências, índices e regras de integridade;
- classes do domínio e da arquitetura MVC;
- tela de login para AOE, coordenação e administrador;
- regra de que responsáveis não possuem login interno.

## 4. Feedback das partes interessadas

Não houve feedback formal individual registrado nesta sprint. Foram consideradas as necessidades levantadas na Sprint 1.

| Necessidade | Origem | Impacto |
|---|---|---|
| Restringir acesso ao dashboard | AOE e coordenação | Alto |
| Não exigir conta dos responsáveis | Pais e responsáveis | Alto |
| Preservar histórico de frequência | Coordenação | Alto |
| Proteger links de justificativa | Administração | Alto |

## 5. Novas necessidades identificadas

- definir permissões detalhadas por perfil;
- confirmar percentual mínimo de frequência;
- escolher provedor de e-mail;
- criar dados fictícios para testes;
- realizar testes de integração com MongoDB.

## 6. Alterações no Product Backlog

| Item | Nova prioridade |
|---|---|
| Requisitos não funcionais | Alta |
| Modelagem MongoDB | Alta |
| Diagrama de classes | Alta |
| Protótipo de login | Alta |
| Testes de integração | Alta |
| Seed de dados fictícios | Alta |
| Integração real de e-mail | Média |

## 7. Decisões

- MongoDB será o banco principal.
- A frequência será diária, uma ocorrência por matrícula e data.
- O histórico não será apagado.
- Usuários internos serão AOE, COORDINATION e ADMIN.
- Responsáveis usarão link temporário, sem login.
- O projeto deverá funcionar com Docker Compose.

## 8. Próximos passos

- criar seed de dados fictícios;
- validar conexão e índices MongoDB;
- testar CRUD e frequência;
- validar dashboard e ranking;
- testar justificativa e webhook.

---

# Sprint 3 — Implementação e validação da base

## Identificação

**Projeto:** SAA (Sistema de Ausência de Alunos)  
**Sprint:** 03  
**Período:** 27/09/2026 a 03/10/2026  
**Data da Sprint Review:** não informada  
**Status:** Planejada

## 1. Objetivo de Sprint

Implementar e validar uma base de dados fictícia, as operações principais do CRUD da AOE, o registro diário de frequência, o dashboard e o fluxo inicial de justificativa.

## 2. Resultado da Sprint

### Itens planejados

| Item | Descrição | Prioridade | Status |
|---|---|---|---|
| SAA-31 / #31 | Criar seed de dados fictícios | Alta | Planejado |
| SAA-32 / #32 | Validar conexão e índices MongoDB | Alta | Planejado |
| SAA-33 / #33 | Testar CRUD da AOE | Alta | Planejado |
| SAA-34 / #34 | Testar registro diário de frequência | Alta | Planejado |
| SAA-35 / #35 | Validar dashboard e ranking | Alta | Planejado |
| SAA-36 / #36 | Validar contexto de notificação | Alta | Planejado |
| SAA-37 / #37 | Testar link de justificativa | Média | Planejado |
| SAA-38 / #38 | Preparar webhook de e-mail | Média | Planejado |
| SAA-39 / #39 | Documentar execução Docker | Média | Planejado |

## 3. Incremento esperado

- dados fictícios de alunos, turmas, responsáveis, matrículas e frequências;
- CRUD autenticado;
- frequência sem duplicidade por matrícula e data;
- ranking geral, mensal e anual;
- consulta de aluno, faltas e responsáveis;
- link de justificativa;
- documentação da execução Docker.

## 4. Feedback das partes interessadas

A preencher após a demonstração do ambiente executável.

## 5. Novas necessidades identificadas

A preencher após os testes e a Sprint Review.

## 6. Alterações no Product Backlog

A preencher depois da validação da base e dos testes de integração.

## 7. Decisões

- Os dados de teste deverão ser fictícios.
- O envio real de e-mail só será considerado concluído com provedor configurado e webhook validado.
- O ranking deverá evitar duplicidade de alunos causada por múltiplos responsáveis.

## 8. Próximos passos

- integrar funcionalidades gerenciais;
- consultar ocorrências;
- implementar perfis;
- revisar protótipo de login;
- iniciar Sprint 4.

---

# Sprint 4 — Funcionalidades gerenciais e perfis

## Identificação

**Projeto:** SAA (Sistema de Ausência de Alunos)  
**Sprint:** 04  
**Período:** 04/10/2026 a 10/10/2026  
**Data da Sprint Review:** não informada  
**Status:** Planejada

## 1. Objetivo de Sprint

Implementar e integrar o dashboard gerencial, o registro de justificativas, os gráficos, a consulta de ocorrências, os perfis de usuário e o protótipo da tela de login.

## 2. Resultado da Sprint

### Itens planejados

| Item | Descrição | Responsável(is) | Prioridade | Status |
|---|---|---|---|---|
| SAA-41 / #41 | Implementar Dashboard Gerencial | Vagnerr11 e pedroEmanuelPor | Alta | Planejado |
| SAA-42 / #42 | Implementar Registro de Justificativas | Vagnerr11 | Alta | Planejado |
| SAA-43 / #43 | Implementar Gráficos do Dashboard | Leonardo360-arch | Alta | Planejado |
| SAA-44 / #44 | Implementar Consulta de Ocorrências | Lincoln0777 | Alta | Planejado |
| SAA-45 / #45 | Implementar Perfis de Usuário | Lincoln0777 | Alta | Planejado |
| SAA-46 / #46 | Criar protótipo da tela de login | Vagnerr11 e Lincoln0777 | Baixa | Planejado |

## 3. Incremento esperado

- dashboard com indicadores e filtros;
- ranking de turmas e séries com mais faltas;
- gráficos mensais e anuais;
- justificativas por link temporário;
- consulta de ocorrências por aluno, turma, período e status;
- permissões para AOE, COORDINATION e ADMIN;
- protótipo de login integrado ao fluxo interno.

## 4. Feedback das partes interessadas

A preencher após a apresentação da Sprint 4.

## 5. Novas necessidades identificadas

A preencher após a demonstração e os testes com os responsáveis pelas tarefas.

## 6. Alterações no Product Backlog

A preencher após os resultados reais da Sprint 4.

## 7. Decisões

- O dashboard não exibirá “oportunidades válidas” como indicador.
- O ranking deverá identificar sala, turma e série com mais faltas.
- Os gráficos mensal e anual serão controlados por filtros de visualização.
- O acesso às ocorrências será limitado ao perfil autenticado.

## 8. Próximos passos

- integrar branches e alterações dos responsáveis;
- executar testes de aceitação;
- corrigir conflitos entre filtros, ranking e gráficos;
- revisar permissões;
- planejar a Sprint 5.

---

# Mapa de status

| Sprint | Tema | Status |
|---|---|---|
| Sprint 1 | Descoberta e requisitos iniciais | Concluída |
| Sprint 2 | Especificação técnica e prototipação | Concluída/documentada |
| Sprint 3 | Implementação e validação da base | Planejada |
| Sprint 4 | Funcionalidades gerenciais e perfis | Planejada |

## Organização sugerida no GitHub

```text
sprints/
├── review.md
├── sprint-1/
│   └── review.md
├── sprint-2/
│   ├── review.md
│   └── SPRINT_2.md
├── sprint-3/
│   ├── review.md
│   └── SPRINT_3.md
└── sprint-4/
    ├── review.md
    └── SPRINT_4.md
```
