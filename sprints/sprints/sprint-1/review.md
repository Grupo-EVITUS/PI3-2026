# Sprint Review

## Identificação

Projeto: SAA (Sistema de Ausência de Alunos)  
Sprint: 01  
Período: 13/09/2026 a 19/09/2026  
Data da Sprint Review: 19/09/2026  

## 1. Sprint Goal

Objetivo da Sprint: identificar o público-alvo, os stakeholders, as principais dores e necessidades dos usuários, mapear a jornada do usuário e levantar os requisitos funcionais iniciais do sistema.

> Foi identificado que o sistema será utilizado para auxiliar a atividade da AOE da escola, facilitando o registro e a comunicação das faltas dos alunos aos seus responsáveis. O principal problema identificado é que os professores comunicam as faltas manualmente, perdendo tempo, enquanto os responsáveis podem não saber que o aluno não entrou em sala. O SAA busca organizar esse processo: a AOE registra a ausência e o sistema prepara a comunicação ao responsável.

**Stakeholders identificados:** professores, coordenadores, diretor, pais/responsáveis e responsável pelo registro das faltas (AOE).

**Personas (usuários diretos):** AOE, pai/mãe/responsável pelo aluno e coordenação escolar. A professora participa do processo, mas não é usuária direta: informa os alunos ausentes pelo grupo interno da escola.

---

## 2. Resultado da Sprint

### Itens concluídos

| Item | Descrição | Status | DoD atendida? |
|---|---|---|---|
| SAA-3 / #3 | Pesquisar o contexto do problema | Concluído | Sim |
| SAA-4 / #4 | Identificar as principais dores dos usuários | Concluído | Sim |
| SAA-5 / #5 | Identificar stakeholders | Concluído | Sim |
| SAA-7 / #7 | Mapear a jornada do usuário | Concluído | Sim |
| SAA-8 / #8 | Levantar necessidades dos usuários | Concluído | Sim |
| SAA-9 / #9 | Levantar requisitos funcionais | Concluído | Sim |

### Itens não concluídos

| Item | Motivo | Próxima ação |
|---|---|---|
| Nenhum | Todos os itens planejados para a Sprint 1 foram concluídos. | Iniciar a Sprint 2 com os itens #10, #20, #18 e #21. |

---

## 3. Incremento apresentado

Funcionalidades/artefatos demonstrados:

- Identificação dos stakeholders envolvidos no processo de comunicação das faltas.
- Levantamento do contexto do problema e das principais dores dos usuários.
- Mapeamento da jornada do usuário.
- Levantamento das necessidades dos usuários.
- Levantamento dos requisitos funcionais iniciais `RF01` a `RF31`.
- Levantamento das histórias de usuário `US01` a `US16`.

Link da aplicação: Não se aplica nesta Sprint, pois a Sprint 1 esteve concentrada nas atividades de levantamento e definição do projeto.

Link do repositório: https://github.com/Grupo-EVITUS/PI3-2026

---

## 4. Feedback dos Stakeholders

Não houve feedback formal individual nesta Sprint. Foram registradas as necessidades levantadas:

| Feedback | Origem | Impacto |
|---|---|---|
| Reduzir o trabalho manual de comunicação das faltas | Professores | Alto |
| Saber se o aluno está presente na escola | Pais/responsáveis | Alto |
| Registrar as ausências de forma organizada e automatizar o envio | AOE | Alto |
| Centralizar e acompanhar ocorrências e justificativas | Coordenação | Alto |

---

## 5. Novas necessidades identificadas

- Uso do sistema pela AOE para cadastrar as faltas e disparar as mensagens.
- Envio automático de e-mail ao responsável após o registro da ausência.
- Link no e-mail para formulário de justificativa, com motivos predefinidos e opção “Outro”.
- Controle de status da ocorrência: `Pendente` e `Respondida`.
- Registro da comunicação realizada.
- Acompanhamento pela coordenação por aluno, turma e data.
- Controle da quantidade de mensagens enviadas.

---

## 6. Alterações no Product Backlog

| Item | Alteração | Prioridade anterior | Nova prioridade |
|---|---|---|---|
| Registro de ausência | Definido como etapa principal do sistema | Não definida | Alta |
| Localização de turma e aluno | Necessária para agilizar o registro | Não definida | Alta |
| Comunicação automática por e-mail | Inclusão do envio automático | Não definida | Alta |
| Formulário de justificativa | Inclusão de link no e-mail | Não definida | Alta |
| Registro da justificativa e status | Armazenamento da resposta | Não definida | Alta |
| Acompanhamento pela coordenação | Visualização de ocorrências e pendências | Não definida | Alta |
| Pesquisa avançada e histórico por aluno | Prevista para versão futura | Não definida | Média |

---

## 7. Decisões

- O SAA terá como foco o controle de ausências e a comunicação com os responsáveis.
- A professora não será usuária direta e não haverá integração com WhatsApp.
- A AOE registrará as ausências no sistema.
- A comunicação será feita por e-mail com link para justificativa.
- O sistema registrará a justificativa e seu status.
- A coordenação acompanhará as ocorrências.

---

## 8. Próximos passos

- Levantar os requisitos não funcionais (`#10`).
- Planejar os requisitos da modelagem de dados (`#20`).
- Desenvolver o diagrama de classes (`#18`).
- Criar o protótipo da tela de login (`#21`).
