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

## Perfis e funcionalidades

- **AOE:** registrar ausências, consultar ocorrências e reenviar comunicações com falha.
- **Coordenação:** mesmos recursos da AOE, cadastros de turmas, alunos e responsáveis, vínculos entre responsáveis e alunos e criação de usuários internos.
- **Diretor:** consulta de ocorrências e justificativas, sem operações de escrita.
- **Responsável:** sem conta; responde por link individual de alta entropia enviado ao e-mail cadastrado.

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

O comando não sobrescreve arquivos existentes, não grava no MongoDB e não envia e-mails. O manifesto inclui hash da cópia, contagens por tabela, migrações aplicadas, integridade, vínculos, chamada histórica e quantidade de mensagens por estado. `blocked` exige correção antes da importação. Uma cópia `ready_for_dry_run` ainda precisa de importador, reconciliação e ensaio de restauração; o SQLite continua ativo.

- `public/index.php`: rotas HTTP e controles de perfil.
- `src/Services.php`: validações e regras de negócio.
- `src/Database.php` e `migrations/`: persistência, seed, aplicação rastreada de migrações e esquema.
- `src/MailQueue.php` e `bin/process_mail_queue.php`: outbox persistente e worker de envio.
- `templates/` e `public/assets/app.css`: interface HTML/CSS responsiva.

O armazenamento usa SQLite para facilitar execução local e testes; as regras ficam isoladas dos templates e acessam os dados por PDO e consultas parametrizadas. Para produção com múltiplos servidores, recomenda-se migrar o esquema relacional para PostgreSQL e trocar a conexão por configuração de DSN.

## Fora do escopo da v1

Pesquisa avançada por aluno/turma/data, histórico detalhado por aluno, relatórios/gráficos, WhatsApp/SMS, aplicativo mobile, integrações institucionais e análise avançada. A estrutura de ocorrências, justificativas, serviços e logs permite adicionar essas capacidades em versões futuras sem introduzir esses recursos no MVP.

> O arquivo ZIP `saa_flask_mongodb_dashboard_atualizado.zip` e os diagramas existentes são materiais anteriores do repositório; esta versão executável do MVP está implementada em PHP.
