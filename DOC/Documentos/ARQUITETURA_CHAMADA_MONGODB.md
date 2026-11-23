**Status:** implementada; MongoDB é o único armazenamento do SAA
**Issue:** #46

**Contrato de persistência:** `attendance_sessions` possui validação de BSON,
índice único por `class_id`/`attendance_date` e índice de consulta por data/status.
`absences.attendance_session_id` aponta para a chamada que originou a ausência.

O serviço `MongoAttendance::record` registra, em uma transação no replica set,
o roster completo, ausências, auditoria, comunicações e e-mails na fila. Ele
rejeita chamadas repetidas e alterações concorrentes de turma/aluno/vínculo.
A interface recebe apenas os IDs marcados como ausentes. No instante da
conclusão, o servidor compara a lista de alunos exibida com os alunos ativos da
turma na transação e registra `PRESENTE` para todos os demais. Uma lista vazia
de ausências é válida para uma turma com alunos; uma lista de turma alterada
é recusada e precisa ser recarregada. Antes da conclusão, nenhum aluno recebe
presença automática. A chamada concluída ainda não possui fluxo de correção.
A gravação não envia SMTP dentro da requisição; o worker separado processa a
fila.

A rota `/chamada` autentica AOE/Coordenação, revalida a conta ativa, exige
CSRF e usa o serviço transacional. O worker `bin/process_mail_queue_mongodb.php`
requer `MAIL_MODE=smtp`; recupera reservas antigas e ignora filas em quarentena.
Após o SMTP, o link individual é validado pela rota pública `/justificar/{token}`
e grava a resposta em transação. O teste HTTP/SMTP com Mailpit é o gate desta
entrega. A entrega SMTP segue semântica ao menos uma vez se houver queda entre
a aceitação pelo servidor e a confirmação no banco.

## Escopo pendente

Ainda não há relatórios por ano e intervalo personalizado
nem calendário letivo configurável.

## Modelo canônico da chamada

### `attendance_sessions`

Um documento por turma e data. O contrato atual usa `class_id`, `attendance_date`,
`completed_by`, `completed_at`, snapshots `class_name`/`class_shift`/`academic_year`,
`entries[]` e `roster_hash`. Os valores de status persistidos são `PRESENTE` e
`AUSENTE` nas entradas e `PROCESSANDO` ou `CONCLUIDA` na sessão.

A evolução planejada da chamada inclui:

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

## Verificações obrigatórias da etapa 3

- Roster total/parcial/excedente e matrícula com início/fim na data.
- Turma somente com presentes, somente com ausentes e estados mistos.
- Duplicidade concorrente e hash idêntico/divergente.
- Falhas entre sessão, projeção de ausência, fila e confirmação; retry não duplica.
- Responsáveis inelegíveis, comunicação falha e reenvio sem alteração da frequência.
- Autorização, CSRF, IDOR, validação de ObjectId, injeção NoSQL, PII/tokens e tamanho BSON.
- Lint, unit, integração MongoDB e aceitação no SHA candidato; registrar a versão e topologia do Mongo usado.

## Limite deste documento

Descreve o desenho da chamada e não é evidência de testes. A issue #46 permanece aberta até a revisão da equipe.
