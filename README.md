# Sistema de Controle de Ausências e Justificativas

MVP web em **PHP, HTML e CSS** para registrar ausências escolares, comunicar os responsáveis por e-mail e acompanhar justificativas. O sistema tem telas específicas para a equipe, um formulário público por link individual e persistência relacional.

## Requisitos

- PHP 8.1 ou superior
- Extensões PHP `PDO` e `pdo_sqlite`

O MVP não depende de Composer, Node.js nem de um serviço de banco externo. O SQLite é criado automaticamente em `var/saa.sqlite3`; as migrações numeradas em `migrations/` são aplicadas e registradas em `schema_migrations` na inicialização.

`002_absence_class_snapshot.sql` preserva a turma associada a novas ausências, mesmo após a transferência do aluno. Em bancos existentes, a migração preenche registros antigos com a turma atual do aluno; transferências anteriores não podem ser recuperadas sem histórico de origem.

## Executar localmente

No PowerShell, na raiz do repositório, abra dois terminais:

**Terminal 1 — aplicação web**

```powershell
php -S 127.0.0.1:8000 router.php
```

**Terminal 2 — fila de e-mails**

```powershell
php bin/process_mail_queue.php --watch
```

Acesse <http://127.0.0.1:8000>. Com `MAIL_MODE=log` (padrão), o worker registra os e-mails no log do PHP para desenvolvimento; não envia mensagens reais. Cada e-mail inclui o link individual da justificativa.

### Usuários de demonstração

| Perfil | E-mail | Senha |
|---|---|---|
| AOE | `aoe@escola.local` | `AOE12345!` |
| Coordenação | `coordenacao@escola.local` | `Coord12345!` |
| Diretor | `diretor@escola.local` | `Diretor12345!` |

O seed de demonstração é ativado por padrão somente quando o banco ainda não contém usuários. Para uma instalação real, defina `SEED_DEMO=false`. Para criar a primeira conta de coordenação sem seed, use uma variável de ambiente segura para a senha:

```powershell
$env:SEED_DEMO = "false"
$env:INITIAL_USER_PASSWORD = "uma-senha-forte"
php bin/create_user.php COORDENACAO "Nome da Coordenação" "coord@escola.org"
Remove-Item Env:INITIAL_USER_PASSWORD
```

## Envio de e-mail

O registro da ausência grava a ocorrência e as mensagens na fila local, sem aguardar o servidor de e-mail. O worker consome a fila e atualiza cada comunicação para `ENVIADO` ou `FALHA`; falhas podem ser reenviadas na tela de detalhes.

Para produção, configure o servidor SMTP e execute o worker como serviço supervisionado:

```powershell
$env:MAIL_MODE = "smtp"
$env:SMTP_HOST = "smtp.exemplo.org"
$env:SMTP_PORT = "587"
$env:SMTP_USERNAME = "usuario"
$env:SMTP_PASSWORD = "senha"
$env:SMTP_ENCRYPTION = "starttls" # starttls, ssl ou none
$env:MAIL_FROM = "nao-responda@escola.org"
$env:PUBLIC_BASE_URL = "https://presenca.escola.org"
php bin/process_mail_queue.php --watch
```

Não salve segredos em arquivos versionados. Use o gerenciador de segredos do ambiente de hospedagem, HTTPS e `SESSION_COOKIE_SECURE=true` em produção.

### Fluxo MongoDB em homologação

O modo `SAA_WEB_MODE=mongo_staff` usa Composer, a extensão PHP `mongodb` e MongoDB 7 em replica set. A chamada Mongo só aparece quando `SAA_MONGO_ATTENDANCE_PREVIEW=true` é definido explicitamente. Após configurar `MONGODB_URI`, `MONGODB_DB`, `PUBLIC_BASE_URL` e SMTP, aplique o schema com `php bin/init_mongodb.php`, crie a conta inicial conforme `bin/create_initial_admin_mongodb.php` e execute a aplicação e o worker separadamente:

```powershell
$env:SAA_WEB_MODE = "mongo_staff"
$env:SAA_MONGO_ATTENDANCE_PREVIEW = "true"
$env:MAIL_MODE = "smtp"
php -S 127.0.0.1:8000 router.php
# Em outro terminal com as mesmas variáveis e SMTP configurado:
php bin/process_mail_queue_mongodb.php --watch
```

O worker reivindica cada e-mail, tenta novamente falhas transitórias até três vezes com intervalo e ignora itens em `QUARENTENA`. Depois do SMTP, o responsável pode justificar pelo link individual. A entrega é **ao menos uma vez** se houver queda entre a aceitação do SMTP e a confirmação no banco. A importação de dados SQLite, a reconciliação e a troca operacional ainda não foram concluídas; mantenha a prévia desligada para uso escolar real até esses passos.

### Dados fictícios para homologação MongoDB

Depois de configurar `MONGODB_URI` e `MONGODB_DB`, crie uma base **nova e isolada** de demonstração com um comando explícito:

```powershell
php bin/seed_demo_mongodb.php --target-db=saa_demo_equipe --apply
```

O comando exige um nome `saa_demo_*`, diferente de `MONGODB_DB`, e recusa qualquer banco que já tenha coleções. Cria 2 turmas, 5 alunos, 3 responsáveis e 3 vínculos com e-mails `example.test`; dois alunos ficam sem responsável para conferir esse caso. Não cria usuários administradores, chamadas, ausências, filas nem inscrições push; não envia notificações. Uma segunda execução é recusada sem alterar os dados. Crie a conta da Coordenação nesse banco separadamente com `bin/create_initial_admin_mongodb.php`, usando senha definida em variável de ambiente e `MONGODB_DB=saa_demo_equipe` somente no ambiente de homologação. Não use este comando para carregar alunos reais nem publique a lista da escola no GitHub.

### Frequência em homologação MongoDB

No modo `SAA_WEB_MODE=mongo_staff`, Coordenação e Direção podem consultar `/frequencia` por semana, mês ou semestre civil, selecionando data de referência, turma e aluno. O gráfico e a tabela mostram a mesma taxa: presenças divididas por presenças mais ausências em chamadas completas `CONCLUIDA`. A taxa da turma soma os registros de todos os alunos; se houver filtro de aluno, a comparação por turma considera apenas esse aluno. A transferência de turma conserva o snapshot da chamada. Chamadas incompletas e o histórico legado sem roster comprovado não compõem a taxa. Sem chamadas válidas, o resultado é “Sem dados”. Verde: a partir de 90%; amarelo: de 85% a menos de 90%; vermelho: abaixo de 85%. A apresentação trunca para uma casa decimal sem alterar a classificação exata. A revisão manual pela equipe e o cutover operacional permanecem pendentes.

### Notificação push no Android (PWA)

No modo MongoDB, o aviso de ausência chega ao celular Android do responsável como **notificação push**. O **e-mail continua como reserva**. Não há app de loja: o SAA é um PWA. O responsável abre um link no Chrome, toca em **Ativar notificações** e, se quiser, instala o atalho “Presença” na tela inicial. A notificação aparece na barra do Android, e o toque abre o formulário de justificativa.

1. **Chaves VAPID** (uma vez por ambiente; trocar a chave invalida os aparelhos inscritos):

   ```powershell
   php bin/generate_vapid_keys.php
   $env:VAPID_PUBLIC_KEY = "..."; $env:VAPID_PRIVATE_KEY = "..."   # segredo, não versionar
   $env:VAPID_SUBJECT = "mailto:secretaria@escola.org"
   php bin/init_mongodb.php   # cria push_subscriptions, push_invites e push_queue
   ```

2. **Workers**: execute o de push e mantenha o de e-mail para a reserva:

   ```powershell
   php bin/process_push_queue_mongodb.php --watch
   php bin/process_mail_queue_mongodb.php --watch
   ```

3. **Ativação pelo responsável** (sem login), de duas formas:
   - em **Cadastros › Responsáveis**, a Coordenação gera um **convite de notificações**: link de uso único, válido por 7 dias, que pode ser entregue na matrícula;
   - a página de justificativa aberta pelo link de um e-mail também oferece **Ativar notificações neste celular**.

**Regras de entrega.** Na chamada, cada responsável com aparelho ativo recebe o aviso pela fila `push_queue`, e o e-mail fica guardado como reserva. Quem não tem aparelho ativo recebe e-mail, como antes. O worker envia aos até 5 aparelhos do responsável:

| Resposta do serviço de push | O que acontece |
|---|---|
| 2xx em ao menos um aparelho | Comunicação `ENVIADO` (canal `PUSH`). |
| 404/410 | Aparelho desativado (permissão revogada ou app removido). |
| 429/5xx ou rede | Nova tentativa com intervalo crescente, até 3 tentativas. |
| Nenhum aparelho entregue | Aviso movido para `mail_queue` (`ENCAMINHADO_EMAIL`) na mesma transação. |

**Requisitos e segurança.**
- O navegador só permite Web Push em **HTTPS** (ou `localhost`). Para testar num celular real, publique com HTTPS, por exemplo por um túnel, ou use o encaminhamento de portas do `chrome://inspect`.
- A criptografia segue a RFC 8291 (aes128gcm) e a identificação segue a RFC 8292 (VAPID), apenas com `ext-openssl`, sem dependências novas.
- Endpoints de inscrição só são aceitos para serviços de push conhecidos, como `fcm.googleapis.com` (proteção contra SSRF). `PUSH_ALLOWED_ENDPOINT_PREFIXES` existe **somente para testes** com serviço de push local.
- Convites e links são guardados como hash. O conteúdo da notificação (que contém o link) é apagado da fila após o desfecho.
- No Windows, `openssl_pkey_new` exige `OPENSSL_CONF` apontando para `extras\ssl\openssl.cnf` da instalação do PHP.

## Perfis e funcionalidades

- **AOE:** registrar ausências, consultar ocorrências e reenviar comunicações com falha.
- **Coordenação:** mesmos recursos da AOE, cadastros de turmas, alunos e responsáveis, vínculos entre responsáveis e alunos e criação de usuários internos.
- **Diretor:** consulta de ocorrências e justificativas, sem operações de escrita.
- **Responsável:** sem conta; responde por link individual de alta entropia enviado ao e-mail cadastrado ou, no modo MongoDB, recebido como notificação push no Android.

O MVP inclui cadastro/edição/arquivamento de turmas, alunos e responsáveis; registro de faltas de vários alunos da mesma turma; restrição de duplicidade por aluno/data; fila assíncrona; formulário público; painel filtrado por status; reenvio de falhas; e trilha de auditoria.

Tokens são gerados aleatoriamente e armazenados como hash na tabela de comunicações. O link não expira na v1. Após o envio pela fila, o corpo da mensagem (que contém o token) é removido do registro de fila. A primeira justificativa recebida conclui a ocorrência; outros links passam a exibir somente a resposta já registrada.

## Premissas adotadas

- **P1:** a AOE registra as ausências; a Coordenação também pode registrá-las.
- **P2:** usuários iniciais vêm do seed; a Coordenação pode criar novos usuários. Não há cadastro público de funcionários.
- **P3:** o Diretor tem acesso de leitura equivalente ao painel da Coordenação.
- **P4:** responsáveis não têm conta. O token do e-mail é a credencial; não expira na v1. A coluna `expires_at` já existe para evolução.
- **P5:** se houver vários responsáveis, a primeira justificativa fecha a ocorrência.
- **P6:** motivos iniciais: doença, consulta médica, problema familiar, problema de transporte e outro. A escola deve validar a lista.
- **P7:** ausência é diária, não por aula/disciplina.

## Testes

Execute os testes automatizados sem dependências extras:

```powershell
php tests/run.php
```

Os testes cobrem lote de ausências, bloqueio de duplicidade sem inserção parcial, fila de e-mail, token válido/inválido, motivo “Outro”, primeira resposta, impossibilidade de responder novamente e auditoria.

## Arquitetura e evolução

### Preparação da migração para MongoDB

Antes de importar dados reais, gere uma cópia consistente do arquivo SQLite e um manifesto sem dados pessoais:

```powershell
php bin/prepare_sqlite_migration.php --source=var/saa.sqlite3 --snapshot=var/saa-snapshot.sqlite3 --manifest=var/saa-snapshot.json
```

O comando não sobrescreve arquivos existentes, não grava no MongoDB e não envia e-mails. O manifesto inclui hash da cópia, contagens por tabela, migrações aplicadas, integridade, vínculos, chamada histórica e quantidade de mensagens por estado. `blocked` exige correção antes da importação.

Para a cópia aprovada, valide a conversão e importe somente em um banco MongoDB novo e isolado:

```bash
php bin/import_sqlite_mongodb.php --snapshot=var/saa-snapshot.sqlite3 --manifest=var/saa-snapshot.json --target-db=saa_import_ensaio
php bin/import_sqlite_mongodb.php --snapshot=var/saa-snapshot.sqlite3 --manifest=var/saa-snapshot.json --target-db=saa_import_ensaio --apply
```

O primeiro comando faz dry-run sem gravação. O segundo grava documentos com IDs determinísticos, reconcilia contagens e aceita retomada da mesma cópia. Toda fila histórica entra em `QUARENTENA`, mesmo quando estava aguardando envio; o worker não a processa. Preserve o snapshot e o banco original, proteja os arquivos que contêm dados pessoais e mantenha `MONGODB_DB` apontando para o banco operacional existente. A troca de produção e o ensaio de restauração ainda dependem de validação operacional.

- `public/index.php`: rotas HTTP e controles de perfil.
- `src/Services.php`: validações e regras de negócio.
- `src/Database.php` e `migrations/`: persistência, seed, aplicação rastreada de migrações e esquema.
- `src/MailQueue.php` e `bin/process_mail_queue.php`: outbox persistente e worker de envio.
- `templates/` e `public/assets/app.css`: interface HTML/CSS responsiva.

O armazenamento usa SQLite para facilitar execução local e testes; as regras ficam isoladas dos templates e acessam os dados por PDO e consultas parametrizadas. Para produção com múltiplos servidores, recomenda-se migrar o esquema relacional para PostgreSQL e trocar a conexão por configuração de DSN.

## Fora do escopo da v1

Pesquisa avançada por aluno/turma/data, histórico detalhado por aluno, relatórios/gráficos, WhatsApp/SMS, aplicativo nativo de loja (o modo MongoDB oferece PWA com push), integrações institucionais e análise avançada. A estrutura de ocorrências, justificativas, serviços e logs permite adicionar essas capacidades em versões futuras sem introduzir esses recursos no MVP.

> O arquivo ZIP `saa_flask_mongodb_dashboard_atualizado.zip` e os diagramas existentes são materiais anteriores do repositório; esta versão executável do MVP está implementada em PHP.
