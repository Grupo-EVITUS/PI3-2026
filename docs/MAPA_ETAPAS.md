# Mapa das etapas — SAA em PHP + MongoDB

O desenvolvimento em PHP + MongoDB é entregue **por etapas**, cada uma correspondendo a um *milestone* do GitHub do
projeto. Cada etapa termina com código testado, documentação atualizada e commit(s) próprios na branch
`feat/php-mongodb`.

## Por que seguir os milestones do GitHub

O repositório tem duas numerações de Sprint:

- os **milestones e issues do GitHub** (`Sprint 4 – Arquitetura e Protótipos` … `Sprint 7 – Homologação` e
  `Entrega Final PI3`), com critérios de aceite em cada issue;
- os documentos em [`Sprint/`](../Sprint/) (S1 a S6), escritos na fase Flask.

Os números de issue citados em `Sprint/sprints/S3` a `S6` **não batem** com os do GitHub (ex.: lá "#41 Dashboard
Gerencial"; no GitHub o Dashboard Gerencial é a **#46** e a #41 é "Relacionamento Aluno-Turma-Responsável"). Por isso
o GitHub é a fonte de verdade das entregas; a tabela abaixo mostra a correspondência aproximada com os documentos.

| Etapa | Milestone do GitHub (prazo) | Equivale nos docs `Sprint/` | Situação |
|:-:|---|---|---|
| 1 | Sprint 4 – Arquitetura e Protótipos (18/10/2026) | S2 — especificação técnica e prototipação | ✅ concluída |
| 2 | Sprint 5 – MVP Funcional (08/11/2026) | S3 — implementação e validação da base | próxima |
| 3 | Sprint 6 – Desenvolvimento Completo (15/11/2026) | S4 — funcionalidades gerenciais e perfis | planejada |
| 4 | Sprint 7 – Homologação | S5 — integração e homologação | planejada |
| 5 | Entrega Final PI3 (28/11/2026) | S6 — finalização e entrega | planejada |

## Etapa 1 — Sprint 4: Arquitetura e Protótipos

| Issue | Entrega | Onde está |
|---|---|---|
| #16 Planejamento do design do sistema | paleta (Sala do Futuro), tokens, componentes e estrutura do frontend | [DESIGN_SISTEMA.md](DESIGN_SISTEMA.md), `public/assets/`, `views/` |
| #18 Diagrama de classes | domínio + arquitetura (Mermaid) | [DIAGRAMA_DE_CLASSES.md](DIAGRAMA_DE_CLASSES.md) |
| #20 Requisitos da modelagem de dados | 22 requisitos MD ligados a testes, 12 coleções com validators e índices | [MODELAGEM_MONGODB.md](MODELAGEM_MONGODB.md), `src/Database/Schema.php` |
| #21 Protótipo da tela de login | login funcional (e-mail, senha, mostrar senha, erro genérico, limite de tentativas) | `views/auth/login.php`, `AuthController` |
| #22 Protótipo do frontend – tela inicial | tela inicial com saudação, perfil, atalhos e status do MongoDB | `views/home/index.php`, `HomeController` |

Relatório: [etapas/ETAPA_1_SPRINT_4.md](etapas/ETAPA_1_SPRINT_4.md).

## Etapa 2 — Sprint 5: MVP Funcional

| Issue | Critérios de aceite (resumo) |
|---|---|
| #25 Implementar Banco de Dados | modelagem, script NoSQL (MongoDB), relacionamentos, testes básicos |
| #26 Implementar Backend | estrutura, rotas principais, integração com o banco, testes |
| #27 Implementar Tela de Login | campos, botão entrar, "esqueceu a senha", validação, integração |
| #28 Implementar Tela Inicial | layout, navegação, dados do backend, responsividade |
| #29 Implementar API de Envio de E-mails | API, envio funcionando, tratamento de erros, banco, testes |
| #36 Cadastro de Usuários | formulário, validação, gravação, mensagem de sucesso, erros |
| #38 Cadastro de Alunos | nome, matrícula, data de nascimento, turma, validação, gravação |
| #39 Cadastro de Responsáveis | nome, telefone, e-mail, vínculo com o aluno, gravação |
| #40 Cadastro de Turmas | cadastro, identificação, associação de alunos, alteração, gravação |
| #41 Relacionamento Aluno-Turma-Responsável | vínculos aluno–turma e aluno–responsável, consulta dos vínculos |

Também: dados fictícios (seed), API JSON dos cadastros, webhook de e-mail e execução com Docker Compose.

## Etapa 3 — Sprint 6: Desenvolvimento Completo

| Issue | Critérios de aceite (resumo) |
|---|---|
| #30 Integrar Frontend e Backend | telas consumindo a API, dados persistidos, fluxo completo |
| #31 Controle de Acesso | perfis, permissões e restrições funcionando |
| #32 Recuperação de Senha | solicitação, token, nova senha, fluxo testado |
| #37 Consulta de Usuários | listagem, busca, dados corretos |
| #42 Registro de Ausências | seleção do aluno, data, gravação, ocorrência Pendente, histórico |
| #43 Envio Automático de E-mail | envio automático, dados do aluno, link de justificativa, registro, falhas |
| #44 Formulário de Justificativa | link do e-mail, formulário, campo obrigatório, envio, armazenamento |
| #45 Controle de Status da Ocorrência | Pendente → Respondida automática, histórico |
| #46 Dashboard Gerencial | totais de alunos, responsáveis, ausências, justificativas, pendentes/respondidas, por turma |
| #47 Registro de Justificativas | gravação ligada à ocorrência, data de envio, consulta, integridade |
| #48 Gráficos do Dashboard | por turma, pendentes × respondidas, por período, atualização automática |
| #49 Consulta de Ocorrências | por aluno, turma e status; pendências |
| #50 Perfis de Usuário | Administrador, Coordenador, Secretária, Responsável; associação e consulta |

## Etapa 4 — Sprint 7: Homologação

#33 Executar testes funcionais (casos, evidências, relatório) · #34 Corrigir bugs · #35 Homologação final.

## Etapa 5 — Entrega Final PI3

Documentação final, README e instruções de execução, organização do repositório e material de apresentação.
