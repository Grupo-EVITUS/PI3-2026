# Sprint 2 — SAA

**Projeto:** Sistema de Ausência de Alunos (SAA)  
**Tecnologias:** Python, Flask, MongoDB, PyMongo, Php, Css, Manus IA, OpenIA, GeminiIA 
**Objetivo da sprint:** transformar os requisitos iniciais em decisões técnicas verificáveis e preparar a primeira tela de acesso do sistema.

## 1. Objetivo e escopo

O Sprint 2 organiza quatro entregas: requisitos não funcionais, requisitos para a modelagem de dados, diagrama de classes e protótipo da tela de login. A sprint não altera a regra de negócio principal: a AOE registra a frequência, a coordenação acompanha os indicadores e os responsáveis recebem links para justificar ausências sem criar uma conta.

## 2. Backlog e critérios de aceitação

| Item | Entrega | Critério de aceitação | Situação |
|---|---|---|---|
| #10 | Requisitos não funcionais | Cada requisito possui código, descrição e forma de validação. | Concluído |
| #20 | Requisitos da modelagem de dados | Collections, referências, índices e regras de integridade estão definidos. | Concluído |
| #18 | Diagrama de classes | Classes de domínio, atributos, operações e relacionamentos estão representados. | Concluído |
| #21 | Protótipo de login | A tela apresenta campos de e-mail e senha, erro de autenticação e acesso visual coerente com o SAA. | Concluído |

## 3. Requisitos não funcionais (#10)

| Código | Categoria | Requisito | Critério de verificação |
|---|---|---|---|
| RNF-01 | Segurança | Senhas de usuários internos devem ser armazenadas somente com hash forte. | Inspecionar `users.password_hash`; não deve existir campo de senha em texto puro. |
| RNF-02 | Segurança | O acesso ao dashboard e ao CRUD deve exigir sessão autenticada. | Acessar `/dashboard` sem sessão deve redirecionar para `/login`. |
| RNF-03 | Segurança | Links públicos de justificativa devem usar token com hash, expiração e uso único. | Token expirado ou já utilizado deve ser recusado. |
| RNF-04 | Segurança | Webhooks de e-mail devem exigir segredo de autenticação. | Requisição sem `X-Email-Webhook-Secret` válido deve retornar `401`. |
| RNF-05 | Privacidade | O responsável deve acessar somente a ocorrência associada ao seu link. | Um token não pode abrir dados de outro aluno ou ocorrência. |
| RNF-06 | Disponibilidade | A aplicação deve ser executável localmente via Docker Compose. | `docker compose up --build` deve iniciar Flask e MongoDB. |
| RNF-07 | Desempenho | Consultas do dashboard devem utilizar agregações e índices apropriados. | Verificar índices e executar consultas por período, turma e turno. |
| RNF-08 | Consistência | Não pode existir mais de uma frequência para a mesma matrícula e data. | Índice único em `attendance_records(enrollment_id, date)`. |
| RNF-09 | Auditabilidade | Alterações e eventos de comunicação devem manter data, autor e status. | Consultar timestamps, `created_by` e histórico de notificação. |
| RNF-10 | Usabilidade | A interface deve ser responsiva e apresentar mensagens claras de validação. | Testar em desktop e viewport móvel; campos inválidos devem informar o problema. |
| RNF-11 | Manutenibilidade | O código deve permanecer separado em Model, Controller e View. | Rotas não devem concentrar consultas MongoDB e regras de apresentação. |
| RNF-12 | Portabilidade | A aplicação deve funcionar em ambiente Linux/WSL e Windows com Docker Desktop. | Executar o Compose nos dois ambientes suportados. |
| RNF-13 | Recuperação | Os dados do MongoDB devem ficar em volume persistente no Docker. | Reiniciar containers sem usar `down -v` deve preservar os registros. |
| RNF-14 | Configuração | URI, banco, chave Flask e segredo de webhook devem vir de variáveis de ambiente. | Não deve haver credencial real versionada no repositório. |
| RNF-15 | Compatibilidade | A API deve retornar JSON consistente para operações CRUD. | Validar códigos HTTP e corpo das respostas com `curl` ou testes Flask. |

## 4. Requisitos da modelagem de dados (#20)

| Código | Requisito | Decisão técnica |
|---|---|---|
| MD-01 | Identificar um aluno de forma estável. | `students._id` como `ObjectId`; `school_id` como identificador escolar opcional e único. |
| MD-02 | Preservar mudanças de turma. | `enrollments` referencia aluno, turma e período letivo. |
| MD-03 | Registrar frequência diária sem duplicidade. | `attendance_records` referencia matrícula e possui índice único por data. |
| MD-04 | Associar vários responsáveis a um aluno. | Relação N:N por `student_guardians`. |
| MD-05 | Evitar multiplicação nos indicadores. | Dashboard usa `$addToSet` para alunos distintos e agregações por ocorrência. |
| MD-06 | Vincular justificativa à falta correta. | `justifications.attendance_id` referencia `attendance_records._id`. |
| MD-07 | Permitir formulário sem login dos pais. | `justification_links` guarda somente hash do token, expiração e uso. |
| MD-08 | Preservar o conteúdo realmente enviado. | `email_notifications` guarda snapshot do destinatário, assunto e corpo. |
| MD-09 | Controlar eventos do provedor. | Eventos pequenos podem ficar em `email_notifications.events`; auditoria extensa deve usar `audit_events`. |
| MD-10 | Consultar período, turma e turno. | Índices e filtros devem existir em frequência, matrícula e turma. |
| MD-11 | Arquivar sem apagar histórico. | Cadastros usam `active=false`; frequência e matrícula não são apagadas. |
| MD-12 | Configurar regra de presença. | `attendance_policies` guarda percentual por período, quando a escola confirmar a regra. |

## 5. Diagrama de classes (#18)

O diagrama está em [`diagrama_classes_saa.mmd`](diagrama_classes_saa.mmd) e na imagem [`diagrama_classes_saa.png`](diagrama_classes_saa.png).

As principais classes são:

- `Student`: cadastro do aluno;
- `Enrollment`: vínculo histórico com turma e período;
- `ClassRoom`: turma, série e turno;
- `Guardian`: responsável;
- `StudentGuardian`: vínculo aluno-responsável;
- `AttendanceRecord`: registro diário de presença ou ausência;
- `Justification`: resposta do responsável;
- `JustificationLink`: token seguro;
- `EmailNotification`: comunicação e seus eventos;
- `User`: usuário interno;
- `SAARepository`: persistência e agregações MongoDB;
- `DashboardController`: valida filtros e entrega os dados à View.

## 6. Protótipo da tela de login (#21)

O protótipo funcional está implementado no template [`views/templates/aoe_login.html`](views/templates/aoe_login.html), usando o stylesheet [`views/static/css/saa.css`](views/static/css/saa.css).

### Elementos da tela

A tela apresenta a marca textual do SAA, título de boas-vindas, explicação do ambiente interno, campo de e-mail, campo de senha e botão de entrada. Quando a autenticação falha, a aplicação exibe uma mensagem de erro sem revelar se o e-mail ou a senha estava incorreto.

### Perfis que podem entrar

- `AOE`: operação de cadastro e frequência;
- `COORDINATION`: acompanhamento e gestão;
- `ADMIN`: administração do sistema.

Os responsáveis não utilizam essa tela. Eles recebem um link individual em `/justify/<token>`.

### Fluxo de aceitação

1. Usuário abre `/login`.
2. Informa e-mail e senha.
3. Flask valida a conta no MongoDB.
4. Senha é comparada com o hash armazenado.
5. Em caso de sucesso, a sessão guarda o identificador e o perfil.
6. Usuário é direcionado ao dashboard.
7. Em caso de falha, permanece na tela com mensagem de erro.
8. Usuário autenticado pode encerrar a sessão por `/logout`.

## 7. Casos de teste da sprint

| Caso | Ação | Resultado esperado |
|---|---|---|
| CT-01 | Abrir `/login` | Formulário é exibido com e-mail e senha. |
| CT-02 | Enviar campos vazios | Navegador impede o envio por validação obrigatória. |
| CT-03 | Informar credenciais inválidas | Mensagem genérica de erro e HTTP 401. |
| CT-04 | Informar credenciais válidas | Sessão criada e redirecionamento para dashboard. |
| CT-05 | Abrir `/dashboard` sem login | Redirecionamento para `/login`. |
| CT-06 | Cadastrar frequência duplicada | API retorna conflito, preservando a primeira ocorrência. |
| CT-07 | Usar token de justificativa expirado | Formulário é recusado. |
| CT-08 | Reiniciar Docker sem remover volume | Dados do MongoDB permanecem disponíveis. |

## 8. Definition of Done

O Sprint 2 é considerado concluído quando os quatro itens estão documentados, o diagrama pode ser renderizado, a tela de login está acessível pela aplicação e os critérios de aceitação principais podem ser demonstrados com testes manuais ou automatizados.

## 9. Pendências para a próxima sprint

A próxima sprint deve priorizar seed de dados fictícios, testes de integração com MongoDB, CRUD visual de turmas e responsáveis, definição formal do percentual mínimo de frequência e escolha do provedor real de e-mail.
