# Fundação MongoDB — subetapa 2b.2

Implementa contratos das dez coleções legadas, índices e aplicação explícita reexecutável.
O runtime web permanece SQLite até o port integral da etapa 3. Não há dual-write.

## Execução
Após instalar as dependências e configurar MONGODB_URI e MONGODB_DB:
```sh
php bin/init_mongodb.php
php tests/Integration/Database/MongoSchemaTest.php
```
O teste usa banco aleatório próprio e o remove ao terminar. Nunca remove a base configurada.
O inicializador não cria usuário, senha padrão, dados escolares ou e-mails.
Não é executado automaticamente na inicialização web.

## Contrato de migração
- ObjectId em IDs/referências. Mapeamento dos IDs SQLite é trabalho da etapa 4.
- Instantes são BSON date; absence_date é dia civil string AAAA-MM-DD.
  O validator verifica formato; Bson::isDay e o serviço devem validar o calendário.
- active é booleano, convertido explicitamente de 0/1 pelo importador.
- users.email_key é a dobra ASCII de email, como SQLite NOCASE.
  Schema::emailKey calcula a chave; o validator confere a igualdade no servidor.
  O índice único não depende de collation Unicode diferente do SQLite.
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

## Evidências e limites
O workflow inclui lint e teste com MongoDB 7 standalone: documentos válidos por coleção,
rejeição de inválidos, updates inválidos, índices únicos, normalização de e-mail,
matrículas nulas, responsáveis com e-mail compartilhado, bootstrap repetido sem perda de dados
e recusa de dados incompatíveis preexistentes.
A execução e seu resultado devem ser conferidos no SHA candidato.
Esta entrega não encerra a etapa 2: seed administrativo seguro e health check da aplicação
continuam pendentes, assim como os gates de paridade e migração.
