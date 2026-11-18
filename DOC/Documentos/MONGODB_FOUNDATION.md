# Banco de dados MongoDB do SAA

**Atualizado em:** 18/11/2026 (branch `refactor/remover-sqlite`)

O MongoDB 7 é o único banco do SAA. Este documento descreve as coleções, as regras dos dados e como preparar e conferir o banco. O funcionamento das telas e das filas está na [arquitetura](ARQUITETURA_CHAMADA_MONGODB.md); a instalação, no [README](../../README.md).

## Coleções

Todas têm validação `$jsonSchema` (`validationLevel: strict`, `validationAction: error`) e índices nomeados, definidos em `src/Database/Schema.php`. Os nomes estão em `src/Database/Collections.php`.

| Coleção | Conteúdo | Índices principais |
|---|---|---|
| `users` | Funcionários: nome, e-mail, hash da senha, perfil (`AOE`, `COORDENACAO`, `DIRETOR`), ativo. | E-mail único (`email_key`); uma única conta inicial. |
| `classes` | Turmas: nome, série, ano letivo, turno (`Manhã`, `Tarde`, `Noite`, `Integral`), ativa. | — |
| `students` | Alunos: nome, matrícula opcional, turma atual, ativo. | Matrícula única quando preenchida; turma + ativo. |
| `guardians` | Responsáveis: nome, e-mail, parentesco, ativo. | — |
| `student_guardians` | Vínculo aluno ↔ responsável. | Par único; por responsável. |
| `attendance_sessions` | Chamada de uma turma numa data, com a lista completa de alunos. | Turma + data únicos. |
| `absences` | Falta de um aluno num dia: `PENDENTE` ou `RESPONDIDA`. | Aluno + data únicos; chamada + aluno únicos. |
| `communications` | Aviso a um responsável sobre uma falta: hash do link, canal (`EMAIL`/`PUSH`), situação (`PENDENTE`/`ENVIADO`/`FALHA`). | Hash do link único. |
| `mail_queue` | Fila de e-mails: `AGUARDANDO`, `PROCESSANDO`, `CONCLUIDO`, `FALHA`, `QUARENTENA`. | Um item por aviso; reserva e novas tentativas. |
| `push_subscriptions` | Celulares inscritos para notificação (até 5 ativos por responsável). | Responsável + endereço únicos. |
| `push_invites` | Convites de ativação (uso único, 7 dias). | Hash do convite único. |
| `push_queue` | Fila de notificações: `AGUARDANDO`, `PROCESSANDO`, `CONCLUIDO`, `ENCAMINHADO_EMAIL`. | Um item por aviso; reserva, novas tentativas e lembrete. |
| `justifications` | Resposta do responsável: motivo, explicação e data. | Uma por ausência. |
| `operation_logs` | Auditoria: quem fez o quê, quando. | Por data. |

## Regras dos dados

- **IDs:** todos os IDs e referências são `ObjectId`. Não há chave estrangeira no MongoDB; a integridade entre coleções é garantida pelas classes `Mongo*` dentro de transações.
- **Datas:** instantes são `BSON date` em UTC. O dia de uma falta ou chamada é texto `AAAA-MM-DD`; a validação confere o formato, e `Bson::isDay` confere se a data existe no calendário.
- **Booleanos:** `active` é sempre `true`/`false`; registros arquivados não são apagados.
- **E-mail dos funcionários:** `users.email_key` é o e-mail com as letras A–Z em minúsculas (`Schema::emailKey`). A validação confere essa igualdade no servidor, e o índice único usa essa chave. Assim, `Ana@Escola.org` e `ana@escola.org` são o mesmo usuário, sem depender de regras de idioma.
- **Conta inicial:** `users.is_initial_admin` é opcional. Quando é `true`, um índice único parcial impede que duas contas iniciais sejam criadas, mesmo em execuções simultâneas.
- **Matrícula:** `registration_no` pode faltar; a unicidade só vale quando é texto.
- **Responsáveis:** o e-mail do responsável **não** é único (mais de um cadastro pode usar o mesmo e-mail), e não há limite de responsáveis por aluno.
- **Turma da falta:** `absences.class_id` e a chamada guardam a turma do dia. `class_snapshot_source` vale `captured` nos registros novos e `unknown` quando a turma não é conhecida.
- **Situação da falta:** `PENDENTE`/`RESPONDIDA` diz se houve justificativa, não se o aluno esteve presente. Uma falta justificada continua sendo falta.
- **Campos extras:** a validação permite campos além dos listados, para evoluções sem migração. Nenhum dado é inventado para preencher campos ausentes.

## Preparar o banco

Depois de configurar `MONGODB_URI` e `MONGODB_DB`:

```sh
php bin/init_mongodb.php                 # cria coleções, validações e índices
php bin/create_initial_admin_mongodb.php # primeira conta da Coordenação (uma única vez)
php bin/healthcheck_mongodb.php          # confere se está tudo pronto
```

**`init_mongodb.php`** pode ser executado de novo a cada atualização do sistema: reaplica validações e índices sem apagar documentos.
- Se algum documento existente não passar na validação nova, ou se um índice único encontrar duplicatas, o comando para e avisa, sem corrigir nada sozinho.
- A criação de coleções e índices não é transacional: uma falha pode deixar parte pronta. Corrija a causa e execute de novo.
- Não rode duas execuções ao mesmo tempo.
- O usuário do MongoDB precisa das permissões `createCollection`, `collMod`, `createIndex`, `listCollections` e leitura.
- O comando não cria usuários, dados de exemplo nem e-mails.

**`create_initial_admin_mongodb.php`** cria a primeira conta, com perfil `COORDENACAO`.
- Nome, e-mail e senha (mínimo de 14 caracteres) vêm de `INITIAL_ADMIN_NAME`, `INITIAL_ADMIN_EMAIL` e `INITIAL_ADMIN_PASSWORD`, nunca de argumentos, e não são impressos.
- O comando recusa a execução se já existir qualquer usuário.
- Ele não roda automaticamente quando o site sobe.

**`healthcheck_mongodb.php`** é somente leitura e só roda no terminal.
- Confere a conexão, se todas as coleções do esquema existem, se as validações estão em `strict`/`error` e se os índices batem com a definição (chaves, unicidade e filtro parcial).
- Rejeita índices `sparse`, ocultos ou com expiração (TTL), que poderiam mudar regras ou apagar auditoria.
- A saída não mostra URI, nome do banco, documentos nem mensagens do driver.

## Transações e replica set

O SAA grava em várias coleções numa mesma transação (por exemplo: chamada, faltas, avisos, filas e auditoria). O MongoDB só oferece isso em **replica set**; um servidor standalone não serve.

Para desenvolvimento e CI, o `docker-compose.saa-mongodb.yml` sobe um replica set de um nó (`rs0`):

```sh
docker compose -f docker-compose.saa-mongodb.yml up -d --wait
```

Essa configuração não tem autenticação, publica a porta só em `127.0.0.1` e anuncia o membro como `127.0.0.1`, o que serve para o PHP rodando na mesma máquina. **Não use em produção.** Em produção, use um replica set com autenticação, TLS e, de preferência, três membros para alta disponibilidade. Aplicações em containers precisam de um nome de host acessível na rede deles.

## Auditoria

`MongoOperationLogWriter` só acrescenta registros em `operation_logs`; o código não oferece edição nem exclusão. Cada registro tem o usuário (ou nenhum, no caso do responsável), a ação, a entidade, o ID dela como texto, um detalhe curto e a data. Quem chama não deve colocar e-mail, link, hash ou texto de mensagem no detalhe.

Essa garantia é do código, não do banco. Em produção, dê ao usuário MongoDB da aplicação apenas `insert` e `find` em `operation_logs`.

## Testes do banco

Cada teste cria um banco com nome aleatório e o apaga ao terminar; a base configurada em `MONGODB_DB` nunca é removida.

| Teste | O que cobre |
|---|---|
| `MongoSmokeTest` | Conexão, seleção do banco, leitura e gravação. |
| `MongoSchemaTest` | Validações, índices únicos, e-mail normalizado, matrícula nula, responsáveis com e-mail repetido, reexecução sem perda de dados e recusa de dados incompatíveis. |
| `MongoAdminHealthTest` | Conta inicial única e health check. |
| `MongoTransactionTest` | Gravação conjunta e desfazer conjunto em duas coleções. |
| `MongoUserAuthenticatorTest` | Login por `email_key`, conta inativa e senha errada. |
| `MongoOperationLogWriterTest` | Auditoria só de acréscimo. |
| `MongoSchoolDirectoryTest` | Cadastros, vínculos, arquivamento e permissões. |
| `MongoAttendanceTest` | Chamada completa, faltas, filas, auditoria e desfazer em caso de erro. |
| `MongoMailQueueTest` | Reserva, novas tentativas e entrega da fila de e-mail. |
| `MongoJustificationTest` | Link, turma do dia e resposta única. |
| `MongoPushTest` | Convites, escolha do canal, entrega, aparelho extinto, e-mail de reserva e lembrete. |
| `MongoOccurrencesTest` | Lista, filtros, detalhe e reenvio. |

Todos estão em `tests/Integration/Database/` e rodam no CI (`.github/workflows/saa-mongodb-foundation.yml`) com MongoDB 7 em replica set.
