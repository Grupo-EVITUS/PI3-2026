# Sistema de Controle de Ausências e Justificativas (SAA)

Aplicação web em **PHP, HTML e CSS** com **MongoDB** para registrar a chamada escolar, avisar os responsáveis por **notificação push (PWA) ou e-mail** e acompanhar justificativas e frequência. A equipe usa telas com login; o responsável responde por um link individual, sem conta.

## Requisitos

### Execução local com Docker

```powershell
docker compose -f docker-compose.saa-mongodb.yml up -d --build --wait
```

O Compose inicializa o schema, sobe o site em <http://127.0.0.1:8080> e executa os workers de e-mail e push. Na primeira execução, defina `INITIAL_ADMIN_NAME`, `INITIAL_ADMIN_EMAIL` e `INITIAL_ADMIN_PASSWORD` no terminal e crie a conta da Coordenação:

```powershell
docker compose -f docker-compose.saa-mongodb.yml exec -e INITIAL_ADMIN_NAME -e INITIAL_ADMIN_EMAIL -e INITIAL_ADMIN_PASSWORD app php bin/create_initial_admin_mongodb.php
Remove-Item Env:INITIAL_ADMIN_PASSWORD
```

Por padrão, o e-mail fica na caixa local do [Mailpit](http://127.0.0.1:8025). Para entregar numa caixa real, configure `SAA_SMTP_HOST`, `SAA_SMTP_PORT`, `SAA_SMTP_ENCRYPTION`, `SAA_SMTP_USERNAME`, `SAA_SMTP_PASSWORD` e `SAA_MAIL_FROM` no ambiente do Compose e execute o comando de subida novamente. Use como remetente um endereço autorizado pelo provedor. As mensagens do ambiente local têm links para `127.0.0.1`, acessíveis na máquina que roda o projeto.

### Execução sem Docker

- PHP 8.1 ou superior com as extensões `mongodb` (2.5), `mbstring` e `openssl`
- Composer
- MongoDB 7 em **replica set** (a chamada e a fila usam transações; um replica set de um nó basta para desenvolvimento)
- Um servidor SMTP para o envio real de e-mail

```powershell
composer install
```

## Configuração

A aplicação lê variáveis de ambiente (não carrega `.env`; veja `.env.example`):

| Variável | Uso |
|---|---|
| `MONGODB_URI`, `MONGODB_DB` | Conexão e banco (obrigatórias). `MONGODB_TIMEOUT_MS` é opcional (500–60000). |
| `PUBLIC_BASE_URL` | URL pública usada nos links enviados aos responsáveis. |
| `MAIL_MODE` | `smtp` para enviar e-mail; `log` (padrão) só registra no log do PHP em desenvolvimento. O worker de e-mail exige `smtp`. |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_ENCRYPTION` (`starttls`, `ssl` ou `none`), `MAIL_FROM` | Servidor SMTP. |
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` | Notificação push (opcionais; sem elas só há e-mail). |
| `SESSION_COOKIE_SECURE` | `true` em produção (HTTPS). |

Não salve segredos em arquivos versionados. Use o gerenciador de segredos da hospedagem e HTTPS em produção.

## Primeira execução

```powershell
$env:MONGODB_URI = "mongodb://127.0.0.1:27017/?replicaSet=rs0"
$env:MONGODB_DB = "saa"
php bin/init_mongodb.php            # coleções, validators e índices (reexecutável)

# Conta inicial da Coordenação: senha por variável de ambiente, nunca por argumento
$env:INITIAL_ADMIN_NAME = "Nome da Coordenação"
$env:INITIAL_ADMIN_EMAIL = "coord@escola.org"
$env:INITIAL_ADMIN_PASSWORD = "uma-senha-forte"
php bin/create_initial_admin_mongodb.php
Remove-Item Env:INITIAL_ADMIN_PASSWORD

php bin/healthcheck_mongodb.php     # confere coleções, validators e índices
```

`init_mongodb.php` não cria usuários, senhas padrão nem dados escolares. O comando da conta inicial roda uma única vez e recusa novas execuções quando já existe usuário.

Suba a aplicação e os workers em terminais separados, com as mesmas variáveis:

```powershell
php -S 127.0.0.1:8000 router.php           # aplicação web
php bin/process_mail_queue_mongodb.php --watch   # fila de e-mail e de redefinição de senha (MAIL_MODE=smtp)
php bin/process_push_queue_mongodb.php --watch   # fila de push e lembretes por e-mail (se houver VAPID)
```

Acesse <http://127.0.0.1:8000>. Em produção, execute os workers como serviços supervisionados.

## Perfis e funcionalidades

- **AOE:** faz a chamada da turma (`/chamada`), consulta ocorrências e reenvia comunicações com falha.
- **Coordenação:** mesmos recursos da AOE; cadastros de turmas, alunos, responsáveis e vínculos; criação e consulta de usuários internos; convites de notificação; frequência.
- **Diretor:** somente leitura: frequência (`/frequencia`) e ocorrências (`/ocorrencias`).
- **Responsável:** sem conta. Responde por link individual de alta entropia (`/justificar/{token}`), recebido por e-mail ou como notificação push no Android.

### Chamada

Na tela, **todos os alunos começam como presentes** e a AOE marca apenas os **ausentes**, o que poupa trabalho em turmas grandes. O envio continua levando a situação de **todos** os alunos ativos da turma na data. O serviço grava, em uma transação, a chamada (`attendance_sessions`), as ausências, a auditoria, as comunicações e os avisos na fila; rejeita chamada repetida para a mesma turma e data. O envio acontece depois, pelos workers, e não na requisição. Como a presença é o padrão, quem não for marcado como ausente **não gera aviso** ao responsável, e a chamada não pode ser refeita para a mesma turma e data: confira as marcações antes de concluir.

### Cadastros

A Coordenação cadastra e edita turmas, alunos, responsáveis e vínculos em `/cadastros`. Dois campos são **opcionais** para aceitar listas escolares incompletas:

- **Data de nascimento do aluno:** `AAAA-MM-DD`, de calendário válido, entre 1900 e hoje.
- **Telefone do responsável:** DDD + número (celular começa em 9), com ou sem máscara e com `+55` opcional. É guardado só com dígitos e exibido como `(11) 91234-5678`.

Valores inválidos são recusados sem criar nem alterar o cadastro, e deixar o campo em branco o remove. O telefone é apenas um dado de contato: o aviso de ausência continua por notificação push ou e-mail.

### Funcionários

`/funcionarios` (só Coordenação) lista as contas internas, em ordem alfabética (até 200), com nome, e-mail, perfil, situação e data de cadastro. A busca procura o texto no nome ou no e-mail, sem diferenciar maiúsculas, e combina com os filtros de perfil e de situação (ativos ou inativos). O texto da busca é tratado como literal, nunca como expressão regular, e o hash da senha nunca é lido nem exibido. Para criar contas, use o cadastro de funcionário em `/cadastros`.

### Ocorrências

`/ocorrencias` lista as ausências (as 200 mais recentes) com a turma da chamada, o número de comunicações, falhas e a justificativa, com filtros combináveis por **status, turma, aluno e período** (datas inicial e final, inclusive). A turma é a da chamada, então uma transferência não altera ocorrências antigas; turmas e alunos arquivados continuam disponíveis na consulta. `/ausencias/{id}` mostra o detalhe: comunicações por responsável (canal e situação), quem registrou e a justificativa.

Quando uma comunicação falha (`FALHA`), AOE e Coordenação podem **reenviar** pelo detalhe. O reenvio cria uma nova comunicação por e-mail, com **novo link**, na fila; a anterior fica marcada como reenviada e não pode ser reenviada de novo. Não se reenvia ocorrência já respondida nem para responsável arquivado. O reenvio é auditado (`EMAIL_REENVIO_SOLICITADO`).

### Justificativa

O token vai na comunicação, é guardado como hash e o conteúdo da mensagem é apagado da fila após o envio. A primeira justificativa recebida conclui a ocorrência; os demais links passam a exibir só a resposta registrada. O link não expira. Motivos: doença, consulta médica, problema familiar, problema de transporte e outro (obrigatória explicação).

### Recuperação de senha

Na tela de acesso, **Esqueci minha senha** (`/recuperar-senha`) pede o e-mail cadastrado. Só contas **ativas de AOE e Coordenação** recebem o link; o Diretor fica de fora e deve procurar a Coordenação (a lista de perfis está em `MongoPasswordReset::PROFILES`).

- **Resposta sempre igual:** a tela e o tempo de resposta são os mesmos para e-mail cadastrado ou não, para não revelar quem tem conta.
- **Link:** token de 256 bits, válido por **1 hora** e de **uso único**; só o hash fica no banco. Um novo pedido revoga os anteriores, e cada conta pode pedir no máximo **1 vez a cada 5 minutos**.
- **Envio:** o e-mail entra na coleção `password_resets` e sai pelo worker de e-mail (`bin/process_mail_queue_mongodb.php`), com até 3 tentativas. O texto com o link é apagado do banco após o envio, a falha definitiva, o uso ou a revogação.
- **Senha nova:** de 10 a 72 caracteres, com confirmação. Depois de salvar, a pessoa entra com a nova senha (não há login automático).
- **Auditoria:** `SENHA_REDEFINICAO_SOLICITADA`, `SENHA_EMAIL_ENVIADO`, `SENHA_EMAIL_FALHA` e `SENHA_REDEFINIDA`, sem e-mail nem token nos detalhes.
- **Limitação:** sessões já abertas da conta continuam válidas até expirarem; a redefinição não as encerra.

### E-mail

O worker reivindica cada mensagem, tenta de novo falhas transitórias até três vezes com intervalo e ignora itens em `QUARENTENA`. Quando um envio falha, o motivo (por exemplo, SMTP recusando a conexão ou a autenticação) aparece no terminal do worker, sem o destinatário nem o conteúdo da mensagem. A entrega é **ao menos uma vez**: uma queda entre a aceitação do SMTP e a confirmação no banco pode gerar reenvio.

### Frequência

Coordenação e Direção consultam `/frequencia` por semana, mês ou semestre civil, filtrando data de referência, turma e aluno. O gráfico e a tabela mostram a mesma taxa: presenças divididas por presenças mais ausências em chamadas `CONCLUIDA`. A taxa da turma soma os alunos; com filtro de aluno, a comparação por turma considera apenas ele. A transferência de turma conserva o snapshot da chamada. Sem chamadas válidas, o resultado é “Sem dados”. Verde: a partir de 90%; amarelo: de 85% a menos de 90%; vermelho: abaixo de 85%. A apresentação trunca para uma casa decimal sem alterar a classificação exata.

### Notificação push no Android (PWA)

Não há app de loja: o SAA é um PWA. O responsável abre um link no Chrome, toca em **Ativar notificações** e, se quiser, instala o atalho “Presença”. A notificação aparece na barra do Android e o toque abre a justificativa. O e-mail continua como reserva.

1. **Chaves VAPID** (uma vez por ambiente; trocar a chave invalida os aparelhos inscritos):

   ```powershell
   php bin/generate_vapid_keys.php
   $env:VAPID_PUBLIC_KEY = "..."; $env:VAPID_PRIVATE_KEY = "..."   # segredo, não versionar
   $env:VAPID_SUBJECT = "mailto:secretaria@escola.org"
   php bin/init_mongodb.php   # cria push_subscriptions, push_invites e push_queue
   ```

2. **Ativação pelo responsável** (sem login), de duas formas:
   - em **Cadastros › Responsáveis**, a Coordenação gera um **convite**: link de uso único, válido por 7 dias;
   - a página de justificativa aberta pelo link de um e-mail também oferece **Ativar notificações neste celular**.

**Regras de entrega.** Cada responsável com aparelho ativo recebe o aviso pela fila `push_queue`, com o e-mail guardado como reserva; quem não tem aparelho recebe e-mail. O worker envia a até 5 aparelhos do responsável:

| Resposta do serviço de push | O que acontece |
|---|---|
| 2xx em ao menos um aparelho | Comunicação `ENVIADO` (canal `PUSH`). |
| 404/410 | Aparelho desativado (permissão revogada ou app removido). |
| 429/5xx ou rede | Nova tentativa com intervalo crescente, até 3 tentativas. |
| Nenhum aparelho entregue | Aviso movido para `mail_queue` (`ENCAMINHADO_EMAIL`) na mesma transação. |

**Lembrete por e-mail.** O 2xx do serviço de push indica que a mensagem foi aceita, não que foi lida. Se a ausência continuar sem justificativa `PUSH_EMAIL_REMINDER_HOURS` horas depois da entrega (padrão 24; `0` desliga; máximo 168), o mesmo worker de push cria uma nova comunicação por e-mail com link próprio, marcada como lembrete (`reminder_of_id`), e a põe na `mail_queue`. O lembrete é enviado uma única vez e é dispensado se a ausência já foi respondida ou se o responsável foi arquivado. Na tela da ausência ele aparece como “lembrete (push sem resposta)”.

**Requisitos e segurança.**
- O navegador só permite Web Push em **HTTPS** (ou `localhost`). Para testar num celular real, use HTTPS, por exemplo por um túnel, ou o encaminhamento de portas do `chrome://inspect`.
- A criptografia segue a RFC 8291 (aes128gcm) e a identificação a RFC 8292 (VAPID), só com `ext-openssl`.
- Endpoints de inscrição só são aceitos para serviços de push conhecidos, como `fcm.googleapis.com` (proteção contra SSRF). `PUSH_ALLOWED_ENDPOINT_PREFIXES` existe **somente para testes**.
- Convites e links são guardados como hash; o conteúdo da notificação é apagado da fila após o desfecho.
- No Windows, `openssl_pkey_new` exige `OPENSSL_CONF` apontando para `extras\ssl\openssl.cnf` da instalação do PHP.

## Dados fictícios para homologação

Crie uma base **nova e isolada** de demonstração com um comando explícito:

```powershell
php bin/seed_demo_mongodb.php --target-db=saa_demo_equipe --apply
```

O comando exige um nome `saa_demo_*`, diferente de `MONGODB_DB`, e recusa qualquer banco que já tenha coleções. Cria 2 turmas, 5 alunos, 3 responsáveis e 3 vínculos com e-mails `example.test`; dois alunos ficam sem responsável. Não cria usuários, chamadas, ausências, filas nem inscrições push. Crie a conta da Coordenação nesse banco com `bin/create_initial_admin_mongodb.php` e `MONGODB_DB=saa_demo_equipe`. Não use este comando para alunos reais nem publique a lista da escola no GitHub.

## Testes

Os testes usam MongoDB 7 em replica set e criam bancos aleatórios que removem ao terminar:

```powershell
composer test:bson
php tests/Integration/Database/MongoSchemaTest.php
php tests/Integration/Database/MongoAttendanceTest.php
php tests/Unit/Push/WebPushTest.php
```

A lista completa, incluindo os testes HTTP com Mailpit e o do push, está em `.github/workflows/saa-mongodb-foundation.yml`. Para subir apenas o Mongo local: `docker compose -f docker-compose.saa-mongodb.yml up -d --wait mongodb` (somente desenvolvimento, sem autenticação). Relatórios de testes manuais da equipe ficam em `DOC/Relatorios-de-Testes/`.

## Estrutura

- `public/index.php` e `router.php`: entrada HTTP, cabeçalhos de segurança, CSRF e erro 500.
- `src/web.php`: rotas e controle de perfil.
- `src/Database/`: acesso ao MongoDB (schema, serviços de chamada, cadastros, justificativa, filas, frequência).
- `src/Push/WebPush.php`: Web Push (RFC 8291/8292).
- `src/MailQueue.php`: envio por SMTP ou log, usado pelo worker.
- `bin/`: inicialização, conta inicial, health check, seed e workers.
- `templates/` e `public/assets/`: interface HTML/CSS responsiva e PWA.

## Fora do escopo atual

WhatsApp/SMS, aplicativo nativo de loja e importação em lote de planilhas. O SQLite foi removido: o histórico do Git guarda a implementação anterior.

> O arquivo `saa_flask_mongodb_dashboard_atualizado.zip` e os diagramas são materiais anteriores do repositório.
