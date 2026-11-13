# Fundação MongoDB — subetapa 2b.3

Implementa contratos das dez coleções legadas, índices e aplicação explícita reexecutável.
Inclui criação segura da conta inicial e health check interno de prontidão. O runtime web
permanece SQLite até o port integral da etapa 3. Não há dual-write.

## Execução
Após instalar as dependências e configurar MONGODB_URI e MONGODB_DB:
```sh
php bin/init_mongodb.php
php tests/Integration/Database/MongoSchemaTest.php
php bin/create_initial_admin_mongodb.php
php bin/healthcheck_mongodb.php
php tests/Integration/Database/MongoAdminHealthTest.php
```
O teste usa banco aleatório próprio e o remove ao terminar. Nunca remove a base configurada.
O inicializador não cria usuário, senha padrão, dados escolares ou e-mails. A conta inicial
é criada separadamente, uma única vez, com perfil `COORDENACAO`; o e-mail e a senha vêm de
`INITIAL_ADMIN_NAME`, `INITIAL_ADMIN_EMAIL` e `INITIAL_ADMIN_PASSWORD`, injetados por um
gerenciador seguro de segredos. O comando não aceita credenciais em argumentos e não as imprime.
Após a primeira conta ou qualquer outro usuário existir, o comando recusa nova inicialização.
Um índice único parcial também impede duas criações concorrentes da conta inicial.
Não é executado automaticamente na inicialização web.

O health check é somente leitura, restrito a CLI e confere estado de conexão, coleções,
validators (`strict`/`error`) e definições de índices (chaves, unicidade e filtro parcial).
Também rejeita índices sparse, ocultos ou TTL, que poderiam alterar a semântica ou remover
logs de auditoria. Não retorna URI, nome do banco, documentos nem mensagens do driver.
Um endpoint HTTP de prontidão será avaliado quando o runtime MongoDB for implementado na etapa 3;
não se expõe uma rota pública enquanto a aplicação web ainda usa SQLite.

## Contrato de migração
- ObjectId em IDs/referências. Mapeamento dos IDs SQLite é trabalho da etapa 4.
- Instantes são BSON date; absence_date é dia civil string AAAA-MM-DD.
  O validator verifica formato; Bson::isDay e o serviço devem validar o calendário.
- active é booleano, convertido explicitamente de 0/1 pelo importador.
- users.email_key é a dobra ASCII de email, como SQLite NOCASE.
  Schema::emailKey calcula a chave; o validator confere a igualdade no servidor.
  O índice único não depende de collation Unicode diferente do SQLite.
- users.is_initial_admin é opcional; quando true, um índice único parcial limita a criação
  da conta administrativa inicial a uma única conta, inclusive em execuções concorrentes.
- registration_no ausente/nulo é permitido; unicidade somente para strings.
- guardians.email não é único; vínculos não têm limite artificial de três.
- class_id de ausências legadas pode ser desconhecido. class_snapshot_source permite
  captured, legacy_backfill ou unknown. O port deve exigir turma nos novos registros
  e garantir imutabilidade do snapshot: validators não comparam versão anterior.
- PENDENTE/RESPONDIDA continuam sendo estados de justificativa da ausência, nunca presença.
- QUARENTENA é estado explícito da fila para o importador; nenhum worker Mongo existe nesta entrega.
- Campos adicionais são permitidos para proveniência; nenhum histórico é fabricado.
- Referências entre coleções não são FKs: integridade relacional depende do serviço/importador.
- attendance_sessions está reservada à etapa 5 e não é criada.

## Reexecução e falhas
Validators strict/error e índices nomeados são reaplicados sem apagar documentos.
Antes de collMod, o inicializador rejeita documentos incompatíveis existentes.
Índices conflitantes/duplicidades interrompem o processo; não há deduplicação destrutiva.
DDL não é transacional: uma falha pode deixar coleções anteriores preparadas.
Corrigir a causa e reexecutar. Execuções administrativas devem ser serializadas.
Requer permissões createCollection, collMod, createIndex, listCollections e leitura.

## Etapa 3.1 — autenticação isolada
`MongoUserAuthenticator` valida credenciais da coleção `users` por `email_key` e `active=true`,
confere o hash e retorna somente ObjectId hexadecimal, nome e perfil permitido.
Entradas que não sejam texto, e-mail inválido e valores fora dos limites falham sem montar
filtros Mongo com estruturas fornecidas pelo cliente. Usuário inexistente, inativo ou com
credencial inválida não recebe identidade.

Este componente ainda não está conectado ao login web, não cria sessão nem substitui o SQLite.
As rotas e auditoria atuais dependem de IDs relacionais inteiros; a conexão só ocorrerá numa
fatia coerente que porte também os consumidores da identidade, autorização, sessão e auditoria.
O fluxo de justificativas e envio de e-mail permanece inalterado. O teste de autorização por
rota e rotação de sessão pertence à integração HTTP futura, não a este adaptador isolado.

## Evidências e limites
O workflow inclui lint e teste com MongoDB 7 standalone: documentos válidos por coleção,
rejeição de inválidos, updates inválidos, índices únicos, normalização de e-mail,
matrículas nulas, responsáveis com e-mail compartilhado, bootstrap repetido sem perda de dados
e recusa de dados incompatíveis preexistentes.
A execução e seu resultado devem ser conferidos no SHA candidato. Esta subetapa prepara o seed
administrativo e o health check CLI. A etapa 2 só será encerrada após o CI executar os novos testes;
ainda restam os gates de runtime, paridade e migração.
