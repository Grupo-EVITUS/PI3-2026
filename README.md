# Sistema de Controle de Ausências e Justificativas (SAA)

Aplicação web em **PHP, HTML e CSS com banco MongoDB** para a escola registrar a chamada, avisar os responsáveis quando um aluno falta e acompanhar as justificativas.

O responsável não precisa de conta. Ele recebe o aviso como **notificação no celular Android** (o SAA funciona como app instalável pelo Chrome) ou, se não tiver ativado as notificações, por **e-mail**. Nos dois casos, o aviso traz um link individual para enviar a justificativa.

## Como funciona

1. A AOE ou a Coordenação faz a **chamada** da turma: marca cada aluno ativo como presente ou ausente.
2. Para cada ausente, o sistema registra a **ausência** e cria um **aviso** para cada responsável vinculado ao aluno.
3. Os **workers** entregam os avisos: o de push manda a notificação ao celular; o de e-mail envia pelo SMTP.
4. O responsável abre o link e escolhe o **motivo** (doença, consulta médica, problema familiar, transporte ou outro).
5. A equipe acompanha tudo em **Ocorrências**: o que está pendente, o que foi respondido e o que falhou no envio, com opção de reenviar.

A chamada, as ausências, os avisos e a auditoria são gravados juntos numa **transação**: ou entra tudo, ou nada.

## Requisitos

- PHP 8.1 ou superior, com as extensões `mongodb` (2.5 ou superior), `mbstring` e `openssl`
- [Composer](https://getcomposer.org/)
- MongoDB 7 rodando como **replica set**, mesmo com um único nó, porque as transações exigem isso
- Um servidor SMTP para os e-mails. Para testes locais, o [Mailpit](https://mailpit.axllent.org/) serve.

No Windows, gerar as chaves de notificação (`openssl_pkey_new`) exige a variável `OPENSSL_CONF` apontando para `extras\ssl\openssl.cnf` da instalação do PHP.

## Instalação e execução local

1. **Dependências PHP**

   ```powershell
   composer install
   ```

2. **MongoDB local** (replica set `rs0` de um nó, só para desenvolvimento)

   ```powershell
   docker compose -f docker-compose.saa-mongodb.yml up -d --wait
   ```

3. **Configuração mínima** (veja a [tabela completa](#configuração))

   ```powershell
   $env:MONGODB_URI = "mongodb://127.0.0.1:27017/?replicaSet=rs0"
   $env:MONGODB_DB = "saa"
   $env:PUBLIC_BASE_URL = "http://127.0.0.1:8000"
   ```

4. **Criar coleções, validações e índices.** Pode ser repetido sem apagar dados.

   ```powershell
   php bin/init_mongodb.php
   ```

5. **Primeira conta da Coordenação.** O comando só funciona uma vez, com o banco ainda sem usuários. A senha precisa ter pelo menos 14 caracteres. Depois, a Coordenação cadastra os outros funcionários pela tela.

   ```powershell
   $env:INITIAL_ADMIN_NAME = "Nome da Coordenação"
   $env:INITIAL_ADMIN_EMAIL = "coord@escola.org"
   $env:INITIAL_ADMIN_PASSWORD = "uma-senha-forte-e-longa"
   php bin/create_initial_admin_mongodb.php
   Remove-Item Env:INITIAL_ADMIN_PASSWORD
   ```

6. **Aplicação web**

   ```powershell
   php -S 127.0.0.1:8000 router.php
   ```

   Acesse <http://127.0.0.1:8000> e entre com a conta criada no passo 5.

7. **Workers**, cada um num terminal com as mesmas variáveis:

   ```powershell
   php bin/process_mail_queue_mongodb.php --watch   # e-mails (exige MAIL_MODE=smtp e SMTP_*)
   php bin/process_push_queue_mongodb.php --watch   # notificações push (exige VAPID_*)
   ```

   Sem `--watch`, cada worker processa o que estiver na fila e termina, o que é útil para agendar pelo sistema operacional.

8. **Conferir a instalação** (somente leitura: conexão, coleções, validações e índices)

   ```powershell
   php bin/healthcheck_mongodb.php
   ```

## Configuração

O sistema não lê arquivo `.env`; as variáveis vêm do ambiente do PHP. O arquivo [.env.example](.env.example) lista as principais. **Não salve senhas nem chaves privadas em arquivos versionados.**

| Variável | Obrigatória | Para que serve |
|---|---|---|
| `MONGODB_URI` | sim | Endereço do MongoDB, com `replicaSet=...`. |
| `MONGODB_DB` | sim | Nome do banco. |
| `MONGODB_TIMEOUT_MS` | não | Tempo limite de conexão, de 500 a 60000 ms (padrão 5000). |
| `PUBLIC_BASE_URL` | recomendada | Endereço público usado nos links enviados aos responsáveis. Sem ela, o sistema usa o endereço da requisição. |
| `SESSION_COOKIE_SECURE` | produção | `true` para o cookie de sessão só trafegar por HTTPS. |
| `MAIL_MODE` | worker de e-mail | Precisa ser `smtp` para o worker enviar. |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD` | worker de e-mail | Servidor SMTP (porta padrão 587). |
| `SMTP_ENCRYPTION` | não | `starttls` (padrão), `ssl` ou `none`. |
| `MAIL_FROM` | recomendada | Remetente dos e-mails (padrão `nao-responda@escola.local`). |
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` | para o push | Chaves das notificações, geradas por `php bin/generate_vapid_keys.php`. A privada é segredo. |
| `VAPID_SUBJECT` | para o push | Contato da escola para os serviços de push, por exemplo `mailto:secretaria@escola.org`. |
| `PUSH_TTL_SECONDS` | não | Quanto tempo o serviço de push guarda a notificação se o celular estiver desligado (padrão 86400 = 1 dia). |
| `PUSH_EMAIL_REMINDER_HOURS` | não | Horas até o lembrete por e-mail de um push sem resposta (padrão 24; `0` desliga; máximo 168). |
| `INITIAL_ADMIN_NAME`, `INITIAL_ADMIN_EMAIL`, `INITIAL_ADMIN_PASSWORD` | só no passo 5 | Primeira conta da Coordenação. Remova do ambiente depois. |
| `PUSH_ALLOWED_ENDPOINT_PREFIXES` | **somente testes** | Libera um serviço de push local. Nunca use em produção. |

Em produção: use HTTPS, `SESSION_COOKIE_SECURE=true`, o gerenciador de segredos da hospedagem e os workers como serviços supervisionados.

## Perfis e telas

| Perfil | O que faz |
|---|---|
| **AOE** | Faz a chamada, acompanha as ocorrências e reenvia avisos que falharam. |
| **Coordenação** | Tudo o que a AOE faz, mais os cadastros de turmas, alunos, responsáveis e funcionários, e os convites de notificação. |
| **Diretor** | Consulta as ocorrências e justificativas, sem alterar nada. |
| **Responsável** | Sem conta. Responde pelo link individual recebido por notificação ou e-mail. |

| Tela | Endereço | Quem acessa |
|---|---|---|
| Ocorrências (tela inicial) | `/ocorrencias` | AOE, Coordenação, Diretor |
| Detalhe da ausência | `/ausencias/{id}` | AOE, Coordenação, Diretor |
| Chamada | `/chamada` | AOE, Coordenação |
| Cadastros | `/cadastros` | Coordenação |
| Justificativa | `/justificar/{link}` | Responsável |
| Ativar notificações | `/notificacoes/ativar/{convite}` | Responsável |

As permissões são conferidas no banco a cada acesso: um funcionário desativado perde o acesso na hora, mesmo com a sessão aberta. Todo formulário exige token CSRF.

## Avisos aos responsáveis

### E-mail

O worker de e-mail reserva cada mensagem da fila `mail_queue`, envia pelo SMTP e marca o aviso como `ENVIADO`. Falhas temporárias são tentadas de novo até 3 vezes, com intervalo crescente. Depois disso, o aviso fica como `FALHA` e pode ser **reenviado** na tela da ausência.

A entrega é **ao menos uma vez**: se a conexão cair depois de o servidor SMTP aceitar a mensagem e antes da confirmação no banco, o e-mail pode ser enviado de novo. Depois do envio, o texto da mensagem, que contém o link, é apagado da fila.

### Notificação push no Android (PWA)

Não há app de loja: o SAA é um PWA. O responsável abre um link no Chrome, toca em **Ativar notificações** e, se quiser, instala o atalho "Presença" na tela inicial. A notificação aparece na barra do Android, e o toque abre o formulário de justificativa.

1. **Chaves VAPID**, uma vez por ambiente. Trocar a chave desativa os aparelhos já inscritos.

   ```powershell
   php bin/generate_vapid_keys.php
   ```

2. **Ativação pelo responsável**, sem login, de duas formas:
   - em **Cadastros › Responsáveis**, a Coordenação gera um **convite de notificações**: link de uso único, válido por 7 dias, que pode ser entregue na matrícula;
   - a página de justificativa aberta pelo link de um e-mail também oferece **Ativar notificações neste celular**.

**Regras de entrega.** Na chamada, cada responsável com aparelho ativo recebe o aviso pela fila `push_queue`, e o e-mail fica guardado como reserva. Quem não tem aparelho ativo recebe e-mail. O worker envia para até 5 aparelhos do responsável:

| Resposta do serviço de push | O que acontece |
|---|---|
| 2xx em ao menos um aparelho | Aviso `ENVIADO` (canal `PUSH`). |
| 404/410 | Aparelho desativado (permissão revogada ou app removido). |
| 429/5xx ou falha de rede | Nova tentativa com intervalo crescente, até 3 tentativas. |
| Nenhum aparelho recebeu | O aviso vai para a `mail_queue` como e-mail, na mesma transação. |

**Lembrete por e-mail.** O 2xx do serviço de push indica que a mensagem foi aceita, não que foi lida. Se a ausência continuar sem justificativa `PUSH_EMAIL_REMINDER_HOURS` horas depois da entrega, o worker de push cria um aviso por e-mail com link próprio, marcado como lembrete. Ele é enviado uma única vez e é dispensado se a ausência já foi respondida ou se o responsável foi arquivado. Na tela da ausência aparece como "lembrete (push sem resposta)". Por isso, mantenha também o worker de e-mail rodando.

**Requisitos e segurança.**
- O navegador só permite notificações em **HTTPS** (ou `localhost`). Para testar num celular real, publique com HTTPS, por exemplo por um túnel, ou use o encaminhamento de portas do `chrome://inspect`.
- A criptografia segue a RFC 8291 (aes128gcm) e a identificação segue a RFC 8292 (VAPID), apenas com `ext-openssl`, sem bibliotecas extras.
- Só são aceitos endereços de serviços de push conhecidos, como `fcm.googleapis.com`, para o sistema não poder ser usado como ponte para outros servidores (SSRF).
- Convites e links são guardados como hash. O conteúdo da notificação, que contém o link, é apagado da fila depois da entrega.

### Reenvio de aviso com falha

Na tela da ausência, AOE e Coordenação podem reenviar um aviso marcado como `FALHA`. O reenvio:
- gera um link novo para o mesmo responsável;
- usa a notificação se o responsável tiver ativado o celular desde então;
- vale **uma vez por falha**, mesmo com dois cliques ao mesmo tempo;
- é recusado se a ausência já foi justificada ou se o responsável foi arquivado;
- fica registrado na auditoria.

## Regras adotadas

- **R1:** a AOE faz a chamada; a Coordenação também pode fazer. Cada turma tem uma única chamada por dia, e ela precisa incluir todos os alunos ativos.
- **R2:** a primeira conta da Coordenação é criada pelo comando do passo 5; os demais funcionários, pela tela de Cadastros. Não há cadastro público.
- **R3:** o Diretor vê o mesmo que a Coordenação em Ocorrências, sem poder alterar nada.
- **R4:** responsáveis não têm conta. O link é a credencial, gerado aleatoriamente e guardado como hash. Ele não expira nesta versão; o campo `expires_at` já existe para isso.
- **R5:** com vários responsáveis, a primeira justificativa fecha a ocorrência. Os outros links passam a mostrar a resposta já registrada.
- **R6:** os motivos são doença, consulta médica, problema familiar, problema de transporte e outro. "Outro" exige explicação. A escola deve validar a lista.
- **R7:** a ausência é diária, não por aula ou disciplina. A turma registrada é a do dia da chamada, mesmo que o aluno mude de turma depois.

## Testes

Os testes usam um MongoDB em replica set; cada um cria um banco próprio, com nome aleatório, e o apaga ao terminar. Configure `MONGODB_URI` e `MONGODB_DB` como no passo 3 e rode:

```powershell
composer test:bson                                        # conversões BSON (sem banco)
php tests/Unit/Push/WebPushTest.php                       # criptografia e VAPID (sem banco)
php tests/Unit/Push/WebPushDeliveryTest.php               # entrega HTTP a um serviço de push local
php tests/Integration/Database/MongoSchemaTest.php        # e os demais tests/Integration/Database/Mongo*Test.php
```

Os testes de fluxo completo pelo navegador (`tests/Integration/staff_web.py`, `push_web.php`, `occurrences_web.php` e `mongo_email.py`) precisam do servidor web rodando e, no caso do e-mail, do Mailpit. O workflow [.github/workflows/saa-mongodb-foundation.yml](.github/workflows/saa-mongodb-foundation.yml) mostra a ordem e as variáveis de cada um; ele roda em todo pull request para a `main`.

## Estrutura do código

| Caminho | Conteúdo |
|---|---|
| `public/index.php`, `router.php` | Entrada HTTP, cabeçalhos de segurança e CSRF. |
| `src/mongo_staff_web.php` | Rotas e controle de acesso. |
| `src/Database/Mongo*.php` | Regras de negócio sobre o MongoDB: chamada, cadastros, justificativa, ocorrências e reenvio, filas de e-mail e push, inscrições de aparelhos, autenticação e auditoria. |
| `src/Database/Schema.php` | Validações e índices de cada coleção. |
| `src/Push/WebPush.php` | Criptografia e envio das notificações push. |
| `src/Mailer.php` | Envio SMTP. |
| `src/Auth.php`, `src/Helpers.php` | Sessão, CSRF, perfis e renderização. |
| `templates/`, `public/` | Telas HTML/CSS; em `public/` ficam o manifesto e o service worker do PWA, e em `public/assets/` os ícones, o CSS e o `push.js`. |
| `bin/` | Inicialização do banco, primeira conta, health check, workers e geração de chaves. |
| `DOC/` | Documentação do projeto e das sprints. A [arquitetura](DOC/Documentos/ARQUITETURA_CHAMADA_MONGODB.md) e a [referência do banco](DOC/Documentos/MONGODB_FOUNDATION.md) detalham o funcionamento interno. |

## Fora do escopo desta versão

Pesquisa por aluno, turma ou período; histórico detalhado por aluno; relatórios e gráficos; WhatsApp ou SMS; app de loja; recuperação de senha; e integrações institucionais. Ocorrências, justificativas e auditoria já guardam os dados necessários para essas evoluções.

> O arquivo `saa_flask_mongodb_dashboard_atualizado.zip` é material anterior do grupo (protótipo em Flask) e não faz parte da aplicação.
