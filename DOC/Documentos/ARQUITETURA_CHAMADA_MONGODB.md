# Chamada completa do SAA em MongoDB

**Status:** desenho de implementação revisado  
**Branch de trabalho:** `feat/saa-mongodb-complete-attendance`  
**Issue:** #46

## Diretriz de integração

A aplicação executável na `main` usa PHP e SQLite; a branch `feat/php-mongodb` contém um rewrite PHP + MongoDB divergente e ainda registra ausências individuais. A PR #55 implementa chamada completa em SQLite. Ambas são referências para port seletivo; nenhuma será mesclada integralmente. A implementação trabalha na branch nova baseada na main atual, preservando os commits recentes de histórico, snapshot de turma e integridade.

A mudança deve substituir o runtime de persistência de forma coerente. Não adicionar escrita/leitura Mongo enquanto auth, cadastros e ocorrências continuam lendo SQLite; dual-store criaria duas fontes de verdade. A chamada completa só será implementada depois do baseline Mongo passar pelos gates abaixo.

## Plano em seis etapas

### 1. Inventário, arquitetura e isolamento
Concluídos no planejamento: comparar branches/PRs, atualizar a issue, criar `feat/saa-mongodb-complete-attendance` a partir da main e registrar riscos de incompatibilidade. A main não é alterada.

### 2. Base MongoDB
Portar dependências, configuração, cliente/conexão, inicialização, validators, índices, seed inicial seguro e health check interno. Fixar versão/topologia Mongo e preparar CI compatível. Gate: bootstrap idempotente, conexão e testes de schema/seed/health check.

### 3. Port do runtime e paridade
Portar autenticação/permissões, cadastros, ocorrências/justificativas, notificações/fila, scripts, telas e testes. Sem SQLite+Mongo simultâneos. Gate: fluxos atuais equivalentes, lint, unit, integração Mongo, aceitação HTTP e CI.

### 4. Migração de dados e cutover ensaiado
Construir backup consistente e importador SQLite→Mongo com dry-run, IDs mapeados de forma retomável, sem envio de e-mail. Reconciliar contagens, referências, estados, datas e fila pendente; testar restauração e rollback. Não inventar valores ausentes nem presenças históricas. Cutover real depende de aprovação operacional.

### 5. Chamada completa em MongoDB
Implementar `attendance_sessions` com índice único por turma/data, roster completo validado pelo servidor, snapshots de turma/período e tratamento explícito de concorrência. Ocorrências e notificações usam outbox/projeções idempotentes com lease e retry. Gate: testes de roster, transferências, duplicidades, falhas parciais, retomada e comunicações.

### 6. Dashboards, relatórios e pareceres
Implementar visão da escola, gráfico individual e gráfico por turma com seletor dentro da seção Turmas; emitir relatórios por semana, mês, semestre, ano e intervalo personalizado, com calendário letivo configurável. Após regressão/CI aprovados, entregar relatórios separados de qualidade e arquitetura de dados, com evidências e limitações.

## Modelo canônico da chamada

### `attendance_sessions`

Um documento por turma e data, com:

- `class_id`, nome e período da turma no dia;
- data, usuário responsável e instante de criação;
- `entries[]`: `student_id`, `enrollment_id`, snapshots mínimos de nome/matrícula e status `PRESENT` ou `ABSENT`;
- hash canônico do roster e dos estados normalizados;
- `PROCESSING` ou `COMPLETED`, tentativas e estado operacional de recuperação.

Índice único: `{ class_id: 1, date: 1 }`. O servidor calcula o roster a partir da matrícula válida na data, com limites de vigência explícitos, e valida completude, turma, período e status allowlist. O snapshot preserva matrícula, turma e período históricos. A inserção de um documento completo é atômica no MongoDB.

Hash igual: retomar uma sessão `PROCESSING` ou devolver o resultado da `COMPLETED`. Hash diferente para a mesma turma/data: conflito, sem sobrescrever; correções exigem ação explícita e auditada.

## Projeções, notificações e recuperação

Manter `attendance_records` para ausências, justificativas e histórico. Cada ausência criada pelo worker referencia a sessão e tem índice idempotente por sessão/aluno; configurar o índice como parcial quando necessário para não invalidar documentos legados. Conservar ocorrências pré-existentes sem recriá-las e sem reenviar e-mail durante importação ou retomada.

Uma rotina recuperável busca sessões `PROCESSING`, reivindica trabalho com lease, contador de tentativas e próximo retry, e faz upserts idempotentes. Chaves únicas para jobs de comunicação: sessão/aluno/responsável. Marcar `COMPLETED` somente após ocorrências e notificações terem sido persistidas na fila. Métricas futuras consultam só sessões `COMPLETED`.

A idempotência do job não torna SMTP exatamente-uma-vez: se a conexão cair após o provedor aceitar o e-mail e antes do ACK, pode ocorrer reenvio. Documentar a semântica como pelo-menos-uma-vez e, quando disponível, usar chave/idempotência do provedor.

Esta abordagem evita depender de transações multi-documento. Se forem escolhidas transações, MongoDB e CI/deploy precisam usar replica set. MongoDB standalone não oferece transações multi-documento.

## Limites e segurança

- Manter o documento abaixo de 16 MiB com margem operacional; não embutir conteúdo de e-mail, tokens ou histórico sem limite no roster.
- Validar o roster no servidor e construir filtros Mongo por allowlist; rejeitar operadores NoSQL e IDs inválidos.
- Manter autorização e escopo da turma, CSRF nas ações web, auditoria de correções e proteção de tokens/PII em respostas e logs.
- A chamada legada individual não pode converter presença em falta após uma sessão completa; qualquer correção posterior deve ser explícita e auditada.

## Compatibilidade dos dados legados a resolver na etapa 2

A estrutura SQLite atual não fornece alguns campos exigidos pelo modelo Mongo documentado na branch de referência. O importador e o runtime não devem preencher lacunas com dados inventados.

| Dado | Situação no SQLite | Tratamento necessário |
|---|---|---|
| Data de nascimento e código escolar | `birth_date` não existe; `registration_no` é opcional | Preservar ausência/desconhecido; permitir configuração posterior e não bloquear a migração por valor fictício. |
| Período letivo e matrícula histórica | Turmas têm `academic_year`, mas não datas de período; alunos guardam somente turma atual | Criar configuração explícita de calendário/matrícula; não usar 1º de janeiro, `created_at` ou turma atual como datas históricas presumidas. |
| Snapshot de turma em faltas antigas | Migração 002 preenche faltas antigas usando a turma atual do aluno | Marcar a proveniência/limitação; só snapshots comprovadamente capturados podem ser tratados como históricos confiáveis. |
| Responsáveis e vínculos | E-mail não é único; relacionamento fica na tabela de vínculo e o modelo de referência embute no máximo 3 vínculos por aluno | Detectar duplicatas/excessos, reconciliar sem descartar contatos e preservar vínculos por aluno. Não impor unicidade até deduplicação. |
| Status de ausência | `PENDENTE`/`RESPONDIDA` descreve a justificativa | Migrar como status de ausência e estado de justificativa separados; falta justificada continua sendo falta nas métricas. |
| Perfil e comunicação pendente | `DIRETOR` é somente leitura; corpo da fila pode conter token em claro | Mapear Diretor para perfil read-only; importar filas sem enviar e-mails e sem registrar tokens em claro nos logs. |

O importador deve oferecer backup consistente, dry-run, mapeamento estável de IDs SQLite→ObjectId, importação em ordem de dependências, retomada, reconciliação de contagens/referências/estados, e teste de restauração. Ocorrências pré-existentes precisam ser reconhecidas por chave antes de criar projeções para não duplicar comunicação.

## Verificações obrigatórias da etapa 3

- Roster total/parcial/excedente e matrícula com início/fim na data.
- Turma somente com presentes, somente com ausentes e estados mistos.
- Duplicidade concorrente e hash idêntico/divergente.
- Falhas entre sessão, projeção de ausência, fila e confirmação; retry não duplica.
- Responsáveis inelegíveis, comunicação falha e reenvio sem alteração da frequência.
- Migração preserva ausência e justificativa antigas sem inferir presença ou disparar e-mail.
- Autorização, CSRF, IDOR, validação de ObjectId, injeção NoSQL, PII/tokens e tamanho BSON.
- Lint, unit, integração MongoDB e aceitação no SHA candidato; registrar a versão e topologia do Mongo usado.

## Limite deste documento

Este é um plano revisado e não representa implementação concluída nem evidência de testes. A issue #46 permanece aberta; gráficos e relatórios por período são trabalho posterior, depois da base Mongo e da chamada completa.
