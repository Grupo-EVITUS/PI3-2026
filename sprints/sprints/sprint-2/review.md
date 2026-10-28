# Sprint Review

## Identificação

Projeto: SAA (Sistema de Ausência de Alunos)  
Sprint: 02  
Período: 20/09/2026 a 26/09/2026  
Data da Sprint Review: Não informada  

## 1. Sprint Goal

Objetivo da Sprint: levantar os requisitos não funcionais, planejar os requisitos da modelagem de dados, desenvolver o diagrama de classes e criar o protótipo da tela de login.

> A Sprint 2 é a continuação direta da Sprint 1. Os itens foram definidos na revisão anterior: requisitos não funcionais (#10), modelagem de dados (#20), diagrama de classes (#18) e protótipo da tela de login (#21).

**Escopo técnico:** transformar as necessidades do negócio em regras de segurança, modelagem MongoDB, classes do domínio, arquitetura MVC e uma tela de acesso para os usuários internos.

**Usuários do login:** AOE, coordenação e administrador. Pais e responsáveis não possuem login; recebem um link seguro e temporário para justificar ausências.

---

## 2. Resultado da Sprint

### Itens concluídos

| Item | Descrição | Status | DoD atendida? |
|---|---|---|---|
| SAA-10 / #10 | Levantar os requisitos não funcionais | Concluído | Sim |
| SAA-20 / #20 | Planejar os requisitos da modelagem de dados | Concluído | Sim |
| SAA-18 / #18 | Desenvolver o diagrama de classes | Concluído | Sim |
| SAA-21 / #21 | Criar o protótipo da tela de login | Concluído | Sim |

### Itens não concluídos

| Item | Motivo | Próxima ação |
|---|---|---|
| Nenhum | Os quatro itens previstos foram documentados e preparados para desenvolvimento. | Iniciar a implementação e os testes de integração na Sprint 3. |

---

## 3. Incremento apresentado

Funcionalidades/artefatos demonstrados:

- Requisitos de segurança, privacidade, desempenho, disponibilidade e usabilidade.
- Collections MongoDB, referências, índices e regras de integridade.
- Diagrama UML de classes do domínio e da arquitetura MVC.
- Protótipo da tela de login.
- Regra de acesso para AOE, coordenação e administrador.
- Regra de que os responsáveis não possuem login interno.
- Definição de links temporários para justificativas.

Link da aplicação: Não se aplica como aplicação publicada nesta Sprint.

Link do repositório: https://github.com/Grupo-EVITUS/PI3-2026

---

## 4. Feedback dos Stakeholders

Não houve feedback formal individual registrado nesta Sprint. Os pontos abaixo deverão ser validados na apresentação:

| Feedback esperado | Origem | Impacto |
|---|---|---|
| Restringir o acesso ao ambiente interno | AOE e coordenação | Alto |
| Não exigir conta dos responsáveis | Pais/responsáveis | Alto |
| Preservar o histórico escolar | Coordenação | Alto |
| Proteger os links de justificativa | Administração | Alto |

---

## 5. Novas necessidades identificadas

- Definir permissões detalhadas dos perfis.
- Confirmar o percentual mínimo de frequência.
- Escolher o provedor de e-mail.
- Criar dados fictícios para testes.
- Testar a integração com MongoDB.

---

## 6. Alterações no Product Backlog

| Item | Alteração | Prioridade anterior | Nova prioridade |
|---|---|---|---|
| Requisitos não funcionais | Segurança e qualidade foram detalhadas | Não definida | Alta |
| Modelagem MongoDB | Collections, referências e índices foram definidos | Não definida | Alta |
| Diagrama de classes | Criado para orientar a implementação | Não definida | Alta |
| Protótipo de login | Definido para usuários internos | Não definida | Alta |
| Seed de dados fictícios | Necessário para demonstração | Não definida | Alta |
| Testes de integração | Necessários para validar o banco | Não definida | Alta |
| Integração real de e-mail | Depende do provedor escolhido | Não definida | Média |

---

## 7. Decisões

- MongoDB será o banco principal.
- A frequência será inicialmente diária.
- O histórico não será apagado.
- Usuários internos serão AOE, COORDINATION e ADMIN.
- Responsáveis usarão link temporário, sem login.
- O projeto deverá funcionar com Docker Compose.

---

## 8. Próximos passos

- Criar seed de dados fictícios.
- Validar conexão e índices MongoDB.
- Testar o CRUD da AOE.
- Testar o registro de frequência.
- Validar dashboard e ranking.
- Testar justificativas e webhook de e-mail.
