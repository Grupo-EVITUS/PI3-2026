# Sprint 4 — SAA

**Projeto:** SAA (Sistema de Ausência de Alunos)  
**Sprint:** 04  
**Período:** [preencher período da Sprint 4]  
**Prioridade predominante:** Alta  
**Objetivo:** implementar e integrar as funcionalidades gerenciais, de justificativas, gráficos, ocorrências, perfis de usuário e protótipo de login.

## 1. Objetivo da Sprint

O objetivo do Sprint 4 é ampliar o sistema SAA para atender às necessidades de acompanhamento da coordenação e da AOE. A sprint concentra as funcionalidades de dashboard gerencial, registro de justificativas, gráficos do dashboard, consulta de ocorrências, perfis de usuário e protótipo da tela de login.

As entregas devem ser integradas ao modelo Flask/MongoDB, respeitando as regras de autenticação, preservação do histórico, controle de acesso e utilização de dados agregados no dashboard.

## 2. Backlog da Sprint

| Item | Entrega | Prioridade | Responsável(is) | Critério de aceitação | Situação |
|---|---|---|---|---|---|
| SAA-41 / #41 | Implementar Dashboard Gerencial | Alta | Vagnerr11 e pedroEmanuelPor | Coordenação consegue visualizar indicadores, filtros, ranking de turmas e situação geral das ausências. | Planejado |
| SAA-42 / #42 | Implementar Registro de Justificativas | Alta | Vagnerr11 | Responsável envia justificativa pelo link e a equipe interna consulta o status da resposta. | Planejado |
| SAA-43 / #43 | Implementar Gráficos do Dashboard | Alta | Leonardo360-arch | Dashboard apresenta gráficos mensais, anuais e ranking de turmas com mais faltas. | Planejado |
| SAA-44 / #44 | Implementar Consulta de Ocorrências | Alta | Lincoln0777 | Usuário autorizado consegue consultar ocorrências por aluno, turma, período e status. | Planejado |
| SAA-45 / #45 | Implementar Perfis de Usuário | Alta | Lincoln0777 | AOE, coordenação e administrador possuem permissões coerentes com suas funções. | Planejado |
| SAA-46 / #46 | Criar protótipo da tela de login | Baixa | Vagnerr11 e Lincoln0777 | Tela apresenta identidade do SAA, campos de e-mail e senha, validação e estados de erro. | Planejado |

## 3. Detalhamento das entregas

### SAA-41 / #41 — Implementar Dashboard Gerencial

O dashboard gerencial deve apoiar a coordenação na leitura das ausências e justificativas.

**Funcionalidades previstas:**

- total de alunos faltantes;
- total de ocorrências de ausência;
- presença geral;
- ranking de turmas com mais faltas;
- filtro por período;
- filtro por turma;
- filtro por turno;
- acesso a gráficos mensais e anuais;
- acesso às consultas de ocorrências e justificativas.

**Responsáveis:** Vagnerr11 e pedroEmanuelPor.

### SAA-42 / #42 — Implementar Registro de Justificativas

O registro de justificativas deve permitir que o responsável responda por meio de um link temporário, sem login próprio.

**Funcionalidades previstas:**

- abertura do link seguro;
- validação de expiração;
- controle de uso único;
- seleção de motivo;
- campo de explicação para o motivo “Outro”;
- gravação da justificativa vinculada à falta;
- atualização do status para `RESPONDED`;
- consulta interna das justificativas pendentes e respondidas.

**Responsável:** Vagnerr11.

### SAA-43 / #43 — Implementar Gráficos do Dashboard

Os gráficos devem transformar as agregações MongoDB em informações visuais para tomada de decisão.

**Gráficos previstos:**

- ranking horizontal de turmas com mais faltas;
- presença média por turma;
- média mensal de ausências por aluno;
- média anual de ausências por aluno;
- atualização dos gráficos conforme filtros selecionados.

**Responsável:** Leonardo360-arch.

### SAA-44 / #44 — Implementar Consulta de Ocorrências

A consulta de ocorrências deve permitir localizar e analisar os registros de ausência.

**Filtros previstos:**

- aluno;
- turma;
- série;
- turno;
- período inicial e final;
- status da frequência;
- status da justificativa;
- responsável associado.

**Responsável:** Lincoln0777.

### SAA-45 / #45 — Implementar Perfis de Usuário

O sistema deve controlar o acesso de acordo com o perfil autenticado.

| Perfil | Permissões principais |
|---|---|
| AOE | Cadastrar alunos, turmas, responsáveis, matrículas e frequências; consultar ocorrências. |
| COORDINATION | Visualizar dashboard, consultar ocorrências, acompanhar justificativas e indicadores. |
| ADMIN | Gerenciar usuários, configurações e acesso geral ao sistema. |

**Responsável:** Lincoln0777.

### SAA-46 / #46 — Criar protótipo da tela de login

O protótipo deve representar a entrada dos usuários internos no sistema.

**Elementos obrigatórios:**

- identificação visual do SAA;
- campo de e-mail;
- campo de senha;
- botão de entrada;
- mensagem de credenciais inválidas;
- indicação de ambiente interno;
- comportamento responsivo;
- redirecionamento para o dashboard após o login.

**Responsáveis:** Vagnerr11 e Lincoln0777.

## 4. Critérios gerais de aceitação

1. Usuário não autenticado não acessa dashboard, ocorrências ou funcionalidades administrativas.
2. O responsável acessa apenas o formulário público associado ao seu token.
3. O registro da justificativa permanece associado à ocorrência correta.
4. O dashboard não apresenta o indicador técnico “oportunidades válidas”.
5. O ranking mostra turmas e séries ordenadas pela quantidade de faltas.
6. Os filtros alteram os indicadores e gráficos de forma consistente.
7. A consulta de ocorrências não duplica registros por causa de múltiplos responsáveis.
8. O perfil AOE não recebe permissões administrativas indevidas.
9. O perfil COORDINATION consegue consultar os indicadores gerenciais.
10. O perfil ADMIN consegue gerenciar usuários e configurações autorizadas.
11. A tela de login funciona em desktop e mobile.
12. Os dados permanecem persistidos no MongoDB após reinício dos containers.

## 5. Casos de teste da Sprint

| Caso | Ação | Resultado esperado |
|---|---|---|
| CT-41 | Acessar dashboard como coordenação | Indicadores e gráficos são exibidos. |
| CT-42 | Aplicar filtro mensal | Ranking e gráfico mensal refletem o período selecionado. |
| CT-43 | Aplicar filtro anual | Ranking e gráfico anual refletem o período selecionado. |
| CT-44 | Consultar ocorrência por aluno | Somente ocorrências do aluno são exibidas. |
| CT-45 | Consultar ocorrência por turma | Resultados pertencem à turma selecionada. |
| CT-46 | Enviar justificativa com token válido | Justificativa é gravada e fica `RESPONDED`. |
| CT-47 | Reutilizar link de justificativa | Sistema recusa o token utilizado. |
| CT-48 | Usuário AOE acessar administração | Acesso é recusado quando a rota exigir ADMIN. |
| CT-49 | Usuário ADMIN acessar gerenciamento | Tela ou endpoint administrativo é liberado. |
| CT-50 | Informar senha incorreta | Login não é criado e mensagem genérica aparece. |
| CT-51 | Redimensionar tela de login | Layout continua legível e utilizável. |

## 6. Dependências

- MongoDB com collections e índices disponíveis;
- usuários internos cadastrados;
- dados fictícios para alimentar o dashboard;
- rotas de justificativa e consulta de ocorrências;
- definição das permissões por perfil;
- integração entre os componentes desenvolvidos pelos responsáveis.

## 7. Definition of Done

Uma entrega será considerada concluída quando estiver implementada no código, integrada com o fluxo existente, validada com os casos de teste correspondentes e documentada. O item não deve ser marcado como concluído apenas pela criação da tela: as consultas, regras de acesso e persistência no MongoDB também precisam funcionar.

## 8. Próximos passos

- realizar a integração das branches ou alterações dos responsáveis;
- executar os testes da Sprint 4;
- corrigir conflitos entre dashboard, gráficos e filtros;
- revisar permissões por perfil;
- atualizar o `review_sprint_4.md` com os resultados reais;
- preparar a publicação da versão de homologação.
