# Arquitetura do SAA: chamada, avisos e justificativas no MongoDB

**Atualizado em:** 18/11/2026 (branch `refactor/remover-sqlite`)
**Issue:** #46. A chamada completa está implementada; gráficos e relatórios por período continuam pendentes.

Este documento explica como o SAA funciona por dentro. A instalação e a configuração estão no [README](../../README.md). As coleções, validações e índices estão na [referência do banco](MONGODB_FOUNDATION.md).

## Visão geral

O SAA é uma aplicação PHP sem framework, com o MongoDB 7 como **único banco**. O SQLite e o importador de dados SQLite foram removidos em novembro de 2026; ver [Situação do plano original](#situação-do-plano-original).

| Camada | Arquivos | Papel |
|---|---|---|
| Entrada HTTP | `router.php`, `public/index.php` | Arquivos estáticos, cabeçalhos de segurança (CSP, `X-Frame-Options`), CSRF em todo POST e página de erro genérica. |
| Rotas | `src/mongo_staff_web.php` | Decide a tela, confere o perfil da sessão e se o usuário continua ativo no banco. |
| Regras de negócio | `src/Database/Mongo*.php` | Uma classe por assunto: chamada, cadastros, justificativa, ocorrências, filas e inscrições de push. |
| Banco | MongoDB 7 em replica set | Validações `$jsonSchema` e índices em todas as coleções; transações em várias coleções. |
| Workers | `bin/process_mail_queue_mongodb.php`, `bin/process_push_queue_mongodb.php` | Entregam os avisos fora da requisição web. |

Nenhuma requisição web envia e-mail ou notificação. Ela só grava o aviso na fila, e os workers fazem a entrega.

## Chamada (`MongoAttendance::record`)

Uma única transação faz tudo abaixo; se qualquer passo falhar, nada é gravado.

1. Confere se quem faz a chamada é AOE ou Coordenação ativa.
2. Confere se a turma está ativa e se ainda não existe chamada para ela nessa data.
3. Lê os alunos ativos da turma e exige a situação (presente ou ausente) de **todos**, sem sobras nem faltas, no máximo 1000.
4. Grava a chamada em `attendance_sessions`, com cópia do nome, turno e ano da turma naquele dia.
5. Para cada ausente, grava a ausência (`PENDENTE`) e cria um aviso para cada responsável ativo vinculado ao aluno (`MongoAbsenceNotice`).
6. Registra a chamada e cada ausência na auditoria.

**Concorrência.** A transação incrementa um contador (`link_revision`) na turma, em cada aluno e em cada responsável que ela usa. Se outra pessoa transferir um aluno, arquivar um responsável ou fizer a mesma chamada ao mesmo tempo, o MongoDB detecta o conflito: uma das operações vence e a outra é refeita ou recusada. Os índices únicos (turma + data na chamada, aluno + data na ausência) são a última barreira contra chamadas duplicadas.

## Avisos aos responsáveis

`MongoAbsenceNotice::queue` é usado pela chamada e pelo reenvio. Para cada responsável, ele cria um documento em `communications` com um link novo (o banco guarda só o hash SHA-256) e escolhe o canal:

- **com aparelho ativo** em `push_subscriptions`: o aviso vai para `push_queue`, com o e-mail pronto guardado como reserva (`fallback_email`);
- **sem aparelho:** o aviso vai direto para `mail_queue`.

### Fila de e-mail (`MongoMailQueue`)

- O worker **reserva** um item por vez: marca como `PROCESSANDO`, com um `claim_id` único. Uma reserva parada há mais de 5 minutos, por exemplo porque o worker caiu, volta a ficar disponível.
- O envio SMTP acontece **fora** da transação. Depois, uma transação confirma o resultado na fila e no aviso, mas só se a reserva ainda for daquele worker.
- Em caso de falha, o item é tentado de novo após 1, 2 e 4 minutos (60·2^(n−1) segundos). Na 3ª falha, a fila fica `FALHA` e o aviso também, o que libera o botão de reenvio.
- Itens em `QUARENTENA` nunca são processados.
- Quando o item termina, o texto do e-mail, que contém o link, é apagado.

### Fila de push (`MongoPushQueue`)

A reserva e as novas tentativas funcionam como na fila de e-mail. O que muda é como o resultado é tratado:

| Resultado | Efeito |
|---|---|
| 2xx em ao menos um aparelho | Aviso `ENVIADO` pelo canal `PUSH`; agenda o lembrete. |
| 404/410 | Aparelho desativado (`EXPIRADA_NO_SERVICO`). |
| 429, 5xx ou falha de rede | Nova tentativa, até 3. |
| Nenhum aparelho recebeu | Na mesma transação, o e-mail de reserva entra na `mail_queue` e o aviso passa para o canal `EMAIL` (`ENCAMINHADO_EMAIL`). |

**Lembrete.** Um push aceito não garante leitura. Se a ausência continuar `PENDENTE` depois de `PUSH_EMAIL_REMINDER_HOURS` horas, o worker de push cria um novo aviso por e-mail, com link próprio e marcado com `reminder_of_id`. A trava em `reminder_due_at`, dentro da transação, garante um único lembrete. Ele é dispensado se a ausência já foi respondida ou se o responsável foi arquivado.

**Entrega ao menos uma vez.** Nas duas filas, se a conexão cair depois de o servidor SMTP ou de push aceitar a mensagem e antes da confirmação no banco, a mensagem pode ser enviada de novo. O `tag` da notificação (`saa-{id do aviso}`) faz o Android substituir a notificação repetida em vez de mostrar duas.

## Justificativa (`MongoJustification`)

- O link `/justificar/{token}` só abre se o hash bater com um aviso **já entregue** (`ENVIADO`) e se `expires_at`, quando preenchido, ainda não tiver passado.
- O formulário mostra o aluno, a data e a turma **do dia da chamada**, mesmo que a turma tenha sido renomeada ou o aluno transferido depois.
- O envio grava a justificativa e muda a ausência para `RESPONDIDA` numa transação. Um índice único por ausência garante que só a **primeira** resposta vale; os outros links passam a mostrar a resposta já registrada.
- "Outro" exige explicação, com no máximo 1000 caracteres.

## Ocorrências e reenvio (`MongoOccurrences`)

- **Lista** (`/ocorrencias`): as 500 ausências mais recentes, com filtro Pendentes ou Respondidas. Mostra a turma do dia, o motivo e quantos avisos falharam. As consultas aos alunos, chamadas, justificativas e contagens são feitas em lote, uma por coleção, e não uma por linha.
- **Detalhe** (`/ausencias/{id}`): cada aviso com canal (notificação ou e-mail), situação, detalhe da falha e marcação de reenvio ou lembrete; e a justificativa, se houver.
- **Reenvio** (`/comunicacoes/reenvio`), numa transação:
  - só para AOE ou Coordenação ativa e só para aviso `FALHA`;
  - marca `retried_at` no aviso original, de modo que dois cliques simultâneos disputam o mesmo documento e só um vence;
  - recusa ausência já justificada e responsável arquivado;
  - cria o novo aviso por `MongoAbsenceNotice`, com `retry_of_id`, e registra `COMUNICACAO_REENVIO_SOLICITADO` na auditoria.

## Modelo da chamada (`attendance_sessions`)

Um documento por turma e data, com índice único `{class_id, attendance_date}`:

- `class_id`, `attendance_date` (texto `AAAA-MM-DD`), `completed_by`, `completed_at`;
- cópia da turma no dia: `class_name`, `class_shift`, `academic_year`;
- `entries[]`: `student_id`, `student_name`, `registration_no` e `status` (`PRESENTE` ou `AUSENTE`);
- `roster_hash`: SHA-256 da lista de alunos e situações;
- `status`: `CONCLUIDA`. O valor `PROCESSANDO` está previsto no esquema, mas não é usado, porque a chamada inteira é gravada numa transação só.

A ausência (`absences`) aponta para a chamada por `attendance_session_id`, com índice único parcial por chamada + aluno.

## Limites e segurança

- Toda entrada vinda do navegador é validada como texto antes de virar filtro do MongoDB. IDs passam por `Bson::objectId`, e estruturas enviadas pelo cliente nunca viram operadores, o que impede injeção NoSQL.
- Links de justificativa e convites de push são guardados só como hash. A auditoria registra ações e IDs, não links nem conteúdo de mensagens.
- O conteúdo com link é apagado das filas depois da entrega.
- A auditoria (`operation_logs`) é só de acréscimo pelo código. Em produção, também restrinja as permissões do usuário MongoDB a `insert` e `find` nessa coleção.
- A chamada limita a turma a 1000 alunos, para manter o documento bem abaixo do limite de 16 MiB do MongoDB.
- O MongoDB precisa ser replica set, porque um servidor standalone não oferece transações em várias coleções. A configuração de desenvolvimento usa um nó só (`rs0`), o que valida as transações mas não oferece alta disponibilidade.

## Situação do plano original

O plano original deste documento previa seis etapas para sair do SQLite:

| Etapa | Situação |
|---|---|
| 1. Inventário e isolamento | Concluída. |
| 2. Base MongoDB: esquema, índices, primeira conta, health check | Concluída. |
| 3. Port do sistema: login, cadastros, ocorrências, justificativa, filas | Concluída. Em 16/11/2026, o MongoDB virou o único modo e o SQLite foi removido do código. |
| 4. Migração de dados SQLite e troca ensaiada | **Cancelada** por decisão do grupo em 16/11/2026: o projeto usa só MongoDB e o importador foi removido. |
| 5. Chamada completa | Concluída. |
| 6. Gráficos, relatórios por período e pareceres | **Pendente.** |

## Evoluções possíveis

- **Matrícula histórica:** turma com data de início e fim, para calcular a lista de alunos de uma data passada. Hoje a chamada usa os alunos ativos no momento.
- **Calendário letivo configurável:** necessário para os relatórios por semana, mês, semestre e ano da etapa 6.
- **Correção de chamada já concluída:** como ação explícita e registrada na auditoria. Hoje, uma chamada não pode ser refeita.
- **Busca e filtros:** por aluno, turma e período nas ocorrências.
- **Validade dos links:** prazo de expiração; o campo `expires_at` já existe e já é respeitado.
