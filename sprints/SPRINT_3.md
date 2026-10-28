# Sprint 3 — SAA

**Projeto:** SAA (Sistema de Ausência de Alunos)  
**Sprint:** 03  
**Período:** 27/09/2026 a 03/10/2026  
**Objetivo:** implementar uma base de dados fictícia, validar a persistência no MongoDB, testar o fluxo principal da AOE e preparar a integração de comunicação.

## 1. Objetivo da Sprint

O objetivo do Sprint 3 é transformar a modelagem definida no Sprint 2 em um ambiente executável e demonstrável. A equipe deverá criar dados fictícios de alunos, turmas, responsáveis, matrículas e frequências; validar as operações principais do CRUD; testar o dashboard com dados reais de teste; e verificar o fluxo de localização de aluno, registro de ausência e preparação da comunicação ao responsável.

A sprint também deverá identificar quais partes do envio de e-mail podem ser demonstradas localmente e quais dependem de um provedor externo. O envio real não deve ser considerado concluído sem configuração, autenticação e teste do provedor escolhido.

## 2. Backlog da Sprint

| Item | Entrega | Prioridade | Critério de aceitação | Situação |
|---|---|---:|---|---|
| SAA-31 / #31 | Criar seed de dados fictícios | Alta | O comando cria alunos, turmas, responsáveis, vínculos, matrículas e frequências sem duplicidade. | Planejado |
| SAA-32 / #32 | Validar conexão e índices MongoDB | Alta | A aplicação cria os índices e responde ao comando de ping. | Planejado |
| SAA-33 / #33 | Testar CRUD da AOE | Alta | Criar, listar, consultar, atualizar e arquivar cadastros funciona com autenticação. | Planejado |
| SAA-34 / #34 | Testar registro diário de frequência | Alta | Uma matrícula não aceita duas ocorrências na mesma data. | Planejado |
| SAA-35 / #35 | Validar dashboard e ranking | Alta | Ranking geral, mensal e anual identifica as turmas com mais ausências. | Planejado |
| SAA-36 / #36 | Validar contexto de notificação | Alta | Ao informar o aluno, a API retorna ausências e responsáveis válidos. | Planejado |
| SAA-37 / #37 | Testar link de justificativa | Média | Token válido permite resposta; token expirado ou usado é recusado. | Planejado |
| SAA-38 / #38 | Preparar webhook de e-mail | Média | Eventos `SENT`, `DELIVERED`, `OPENED` e `FAILED` atualizam a notificação. | Planejado |
| SAA-39 / #39 | Documentar execução Docker | Média | Outra pessoa consegue iniciar o projeto seguindo o README. | Planejado |

## 3. Dados de teste

O seed deverá criar, no mínimo:

- 12 alunos fictícios;
- 3 séries ou turmas diferentes;
- 1 período letivo;
- 12 matrículas;
- 6 responsáveis;
- vínculos entre alunos e responsáveis;
- frequências `PRESENT` e `ABSENT` em mais de um mês;
- pelo menos uma turma com mais ausências para aparecer no topo do ranking;
- usuários internos para AOE e coordenação.

Os dados devem ser fictícios e não podem conter informações reais de alunos, responsáveis, telefones ou e-mails pessoais.

## 4. Fluxo principal a validar

1. Iniciar MongoDB e Flask com Docker Compose.
2. Criar ou carregar o usuário AOE.
3. Executar o seed fictício.
4. Fazer login no sistema.
5. Abrir a tela de alunos ou de comunicação.
6. Localizar um aluno.
7. Conferir as faltas retornadas.
8. Conferir os responsáveis vinculados.
9. Registrar uma nova ausência.
10. Abrir o dashboard.
11. Conferir o ranking por turma.
12. Selecionar o filtro mensal.
13. Selecionar o filtro anual.
14. Criar ou simular uma notificação.
15. Abrir o link público de justificativa.
16. Enviar uma justificativa.
17. Conferir o histórico no MongoDB.

## 5. Casos de teste

| Caso | Ação | Resultado esperado |
|---|---|---|
| CT-31 | Executar o seed duas vezes | A segunda execução não cria duplicidades ou informa claramente o conflito. |
| CT-32 | Fazer login com usuário AOE | Sessão criada e dashboard liberado. |
| CT-33 | Acessar CRUD sem login | Requisição redirecionada ou recusada. |
| CT-34 | Criar aluno sem nome | API retorna erro de validação. |
| CT-35 | Criar duas frequências na mesma data | Segunda operação retorna conflito. |
| CT-36 | Consultar contexto de aluno | Retorna aluno, faltas e responsáveis vinculados. |
| CT-37 | Filtrar dashboard por turma | Ranking considera somente a turma selecionada. |
| CT-38 | Filtrar dashboard por mês | Gráfico e ranking usam o período selecionado. |
| CT-39 | Filtrar dashboard por ano | Gráfico e ranking usam o ano selecionado. |
| CT-40 | Abrir token válido | Formulário público é exibido. |
| CT-41 | Reutilizar token usado | Acesso é recusado. |
| CT-42 | Enviar webhook sem segredo | API retorna `401 Unauthorized`. |
| CT-43 | Reiniciar container sem remover volume | Dados continuam disponíveis. |

## 6. Incremento esperado

Ao final da sprint, espera-se demonstrar um ambiente com dados fictícios completos, dashboard preenchido, ranking de faltas por turma, CRUD autenticado e fluxo básico de justificativa. O envio real de e-mail somente será considerado parte do incremento se o provedor estiver configurado e o webhook tiver sido testado.

## 7. Riscos e dependências

- MongoDB precisa estar disponível localmente ou por Docker.
- O envio real depende da escolha de um provedor de e-mail.
- O cadastro de dados reais não faz parte desta sprint.
- A regra oficial de percentual mínimo de presença ainda precisa ser confirmada.
- O resultado dos testes de integração depende do ambiente Docker ou de uma instância MongoDB acessível.

## 8. Definition of Done

A tarefa será considerada concluída quando o código correspondente estiver implementado, o teste previsto for executado, o resultado estiver registrado e a documentação for atualizada. Nenhuma integração externa será marcada como concluída apenas por possuir uma tela ou endpoint; será necessário demonstrar uma resposta real ou uma simulação claramente identificada.

## 9. Saída para o próximo Sprint

Após o Sprint 3, a equipe deverá avaliar a publicação em ambiente de homologação, revisar permissões por perfil, fechar a política de frequência, selecionar o provedor de e-mail e preparar testes de aceitação com as partes interessadas.
