# Arquitetura do SAA — PHP, HTML, CSS e MongoDB

**Projeto:** SAA — Sistema de Ausência de Alunos (PI3 2026, Grupo EVITUS)
**Stack:** PHP 8.3+ · MongoDB 6+ (testado em 7.0) · HTML/CSS/JS puros (sem framework e sem CDN) · PHPUnit 12

Este documento é o **contrato técnico** do projeto: descreve camadas, pastas, convenções, segurança, modelo de dados,
rotas e testes. Toda etapa de desenvolvimento deve segui-lo; mudanças de contrato são feitas aqui primeiro.

---

## 1. Princípios

1. **MVC com serviços (RNF-11).** *Controllers* só tratam HTTP; regras de negócio ficam em *Services*; **somente os
   *Models* conversam com o MongoDB**; *Views* só exibem dados já prontos. Rotas nunca contêm consultas.
2. **Sem framework, sem CDN.** O núcleo (`src/Core`) é pequeno e legível. CSS/JS são arquivos locais — a política de
   segurança de conteúdo (CSP) só permite `'self'`.
3. **Seguro por padrão (RNF-01..05).** CSRF em toda escrita, sessão endurecida, senhas com `password_hash`, links públicos com
   token aleatório guardado só como hash (expiração + uso único), controle de acesso central por perfil, auditoria.
4. **Histórico preservado.** Cadastros são arquivados (`active=false`), nunca apagados; matrículas e frequências nunca são removidas.
5. **Testável.** Tempo (`Clock`), banco (`Mongo::useDatabase`) e e-mail (`MailerInterface`) são injetáveis/trocáveis.

## 2. Estrutura de pastas

```text
bin/                    scripts de linha de comando (init_db, seed, create_user, process_mail_queue)
config/
  navigation.php        itens do menu lateral (rótulo, caminho, permissão)
  routes/*.php          rotas por área; cada arquivo retorna function (Router $router): void
docs/                   documentação (arquitetura, modelagem, RNF, design, testes, manual, diagramas)
public/                 DocumentRoot — index.php (front controller) e assets/ (css, js, img)
src/
  Controllers/          camada HTTP
  Core/                 mini-framework: Config, Clock, Container, Router, Request, Response, View, Session, Csrf,
                        Validator, App, helpers.php, Exceptions/
  Database/             Mongo (conexão), Collections (nomes), Bson (conversões), Schema (validators + índices)
  Domain/               enums de domínio (Profile, Shift, AttendanceStatus, ...)
  Models/               acesso ao MongoDB — uma classe por coleção
  Security/             Permissions (matriz de acesso)
  Services/             regras de negócio (Services/Mail/ = envio de e-mail)
  Support/              utilitários puros
tests/                  Unit/ Integration/ Acceptance/ Support/
views/                  templates PHP: layouts/, partials/, errors/, auth/, home/, ...
var/                    dados de execução (logs, e-mails em arquivo) — ignorado pelo Git
router.php              script do servidor embutido do PHP (php -S ... router.php)
DOC/                    documentos da equipe: Sprints, diagramas e modelagem (não alterar sem alinhamento)
```

Namespaces seguem PSR-4: `Saa\` → `src/`; `Saa\Tests\` → `tests/`. **Nome do arquivo = nome da classe, respeitando
maiúsculas/minúsculas** (o projeto roda em Windows e Linux/Docker).

## 3. Camadas e regras de dependência

```text
Request ─► Router ─► (Kernel: sessão, CSRF, autenticação, permissão) ─► Controller ─► Service ─► Model ─► MongoDB
                                                              │                        │
                                                              └──► View (templates) ◄──┘ (dados prontos)
```

| Camada | Pode usar | Não pode |
|---|---|---|
| Controller | Request, Response, View, Services, Validator, Permissions | falar com o MongoDB; conter regra de negócio |
| Service | Models, Domain, Security, outros Services, Clock, Config | usar `$_GET/$_POST/$_SESSION`, gerar HTML |
| Model | `MongoDB\Database`, Collections, Bson, Domain | regra de negócio de outro agregado; HTTP |
| View | helpers globais (`e()`, `url()`, `can()`, ...) | consultar Model/Service; lógica além de `if/foreach` |

O **Container** (`Saa\Core\Container`) faz *autowiring* pelo tipo dos parâmetros do construtor; `MongoDB\Database` é
ligado em `App::boot()`. O envio de e-mail é escolhido pela `Saa\Services\Mail\MailerFactory` conforme `MAIL_MODE`
(os testes injetam um `MailerInterface` falso). Controllers/Services/Models recebem dependências no construtor.

## 4. Ciclo de uma requisição

1. `public/index.php` → `Saa\Core\App::run()`: carrega Config, inicia sessão, monta o Container e as rotas
   (`config/routes/*.php`, ordem alfabética).
2. O `Router` casa método + caminho. Sem rota → 404; método errado → 405 com `Allow`.
3. O **Kernel** aplica, nesta ordem: cabeçalhos de segurança → sessão (timeouts) → **CSRF** (métodos que escrevem, exceto
   rotas com `csrf => false`) → **acesso** (`public` | `guest` | `auth` + `permission`).
4. O Controller (resolvido pelo Container) recebe `Request` e devolve `Response` (`html`, `json`, `redirect`).
5. Exceções de domínio viram HTTP: `ValidationException` 422, `AuthenticationException` 401, `ForbiddenException` 403,
   `NotFoundException` 404, `ConflictException` 409, `TooManyRequestsException` 429, demais `Throwable` 500
   (registrado em `error_log`; a página nunca mostra detalhes fora de `APP_ENV=development`).
6. Escritas HTML seguem **PRG**: sucesso → `flash` + redirect 303; erro de validação → re-renderiza o formulário com
   status 422, mensagens por campo e os valores digitados (exceto senhas).

## 5. Contratos do núcleo (`src/Core`)

### 5.1 Já fixados (não alterar a API pública)
`Config` (env/.env), `Clock` (agora/hoje, fixável em testes), `Saa\Database\{Mongo, Collections, Bson, Schema}`,
`Saa\Domain\*` (enums), `Saa\Security\Permissions`.

### 5.2 Router
```php
$router->get('/students/{id:oid}', [StudentController::class, 'show'], ['permission' => Permissions::REGISTRY_VIEW]);
$router->post(...); $router->put(...); $router->patch(...); $router->delete(...);
$router->group(['api' => true, 'prefix' => '/api'], function (Router $r) { ... });
$router->routes(): array;           // todas as rotas (usado nos testes de matriz de permissões)
$router->has(string $path): bool;   // há rota GET para o caminho? (menu/atalhos só mostram o que existe)
```
Opções por rota: `access` = `auth` (padrão) | `guest` (só deslogado) | `public`; `permission` (exigida quando `auth`);
`api` (erros em JSON em vez de HTML/redirect); `csrf` (padrão `true` para POST/PUT/PATCH/DELETE; `false` só no webhook);
`name`. Parâmetros: `{id}` = `[^/]+`; `{id:oid}` = 24 hex; `{token}` = `[A-Za-z0-9_-]{20,100}`.
Handler: `[Controller::class, 'metodo']` com assinatura `public function metodo(Request $request): Response`.

### 5.3 Request / Response
`Request`: `method()`, `path()`, `query(k)`, `input(k)`, `all()`, `param(k)` (parâmetros de rota), `header(k)`, `cookie(k)`, `ip()`
(somente `REMOTE_ADDR`), `isJson()`, `wantsJson()`; JSON do corpo é decodificado. Getters tipados e **seguros contra injeção
de operadores NoSQL**: `string(k)` devolve `?string` e **ignora arrays** (`email[$ne]=x` vira `null`), `int(k)`, `bool(k)`,
`stringList(k)` (lista de strings). **Nunca** coloque `input()` cru em um filtro do MongoDB.
`Response`: `html()`, `json()`, `redirect($to, 303)`, `text()`, `noContent()`, `withHeader()`, `send()`.

### 5.4 View e helpers
`View::render('auth/login', $data, 'layouts/auth')` renderiza `views/auth/login.php` dentro do layout (variáveis do
`$data` ficam disponíveis; o layout recebe `$content` e `$title`). Funções globais (`src/Core/helpers.php`, com `function_exists`):
`e()`, `url($path, $query)`, `absolute_url($path)` (usa `APP_URL`, para e-mails), `asset($path)` (com `?v=mtime`),
`csrf_token()`, `csrf_field()`, `old($campo, $padrão)`, `field_error($campo)`, `flash_messages()`, `current_user()`, `can($permissão)`,
`profile_label($código)`, `format_date($v)` (d/m/Y), `format_datetime($v)` (d/m/Y H:i no fuso escolar), `partial($nome, $dados)`,
`label($tipo, $código)` (rótulos pt-BR dos enums), `badge($status)`, `nav_items()`.
**Todo valor dinâmico em HTML passa por `e()`.** Sem `style=""`/`<script>` inline (CSP).

### 5.5 Session, CSRF, Validator
- `Session`: cookie `SAASESSID` (HttpOnly, SameSite=Lax, `Secure` conforme `SESSION_COOKIE_SECURE`/HTTPS), `use_strict_mode`,
  `regenerate()` no login, expiração por inatividade (`SESSION_IDLE_MINUTES`) e absoluta (`SESSION_ABSOLUTE_HOURS`).
- `Csrf`: token por sessão (`random_bytes`), comparação com `hash_equals`; campo `csrf_token` ou cabeçalho `X-CSRF-Token`.
- `Validator`: `Validator::make($request->all())->required(...)->string(..., max)->email(...)->in(..., valores)->date(...)->objectId(...)`;
  `passes()`, `errors()` (campo → primeira mensagem em pt-BR), `validated()` (valores normalizados). Serviços lançam
  `ValidationException($errors)`.

## 6. Segurança

| Tema | Regra |
|---|---|
| Cabeçalhos | `Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; form-action 'self'; base-uri 'self'; frame-ancestors 'none'`; `X-Content-Type-Options: nosniff`; `X-Frame-Options: DENY`; `Referrer-Policy: same-origin`; `Permissions-Policy` restritivo; `Cache-Control: no-store` em páginas autenticadas e na justificativa; HSTS em produção/HTTPS |
| Senhas | `password_hash(PASSWORD_DEFAULT)`; mínimo de 10 caracteres; hash nunca sai do Model (projeção sem `password_hash`) |
| Login | mensagem genérica ("E-mail ou senha inválidos") com HTTP 401; limite `LOGIN_MAX_ATTEMPTS` por janela `LOGIN_WINDOW_MINUTES` (coleção `login_attempts`, TTL) → 429; `session_regenerate_id` ao entrar |
| Autorização | central no Kernel por rota (`permission`); o perfil é **relido do banco a cada requisição** (desativar/trocar perfil vale na hora); views usam `can()` apenas para esconder UI |
| Tokens públicos | 32 bytes aleatórios (base64url, 43 caracteres); só o **SHA-256** é gravado; comparação por hash; expiração (`JUSTIFICATION_LINK_TTL_DAYS`) e uso único; o token em claro existe apenas no e-mail |
| Webhook | `POST /api/webhooks/email` exige `X-Email-Webhook-Secret` (`hash_equals` com `EMAIL_WEBHOOK_SECRET`); sem segredo ou sem configuração → 401/503 |
| NoSQL | IDs validados por `Bson::objectId()`; entradas tipadas (`Request::string`); busca textual com `Bson::contains()` (regex escapado); campos permitidos em lista branca (sem *mass assignment*) |
| Redirecionamento | parâmetro `next` só aceita caminho interno (começa com `/`, sem `//`, sem esquema/host) |
| Auditoria | `AuditService` grava `audit_events` (quem, quando, ação, entidade, IP) para login, cadastros, ausências, envios, justificativas |
| Segredos | só em variáveis de ambiente/`.env` (ignorado pelo Git); `.env.example` sem valores reais |

## 7. Modelo de dados (resumo)

Detalhes, exemplos, validators e índices: **[MODELAGEM_MONGODB.md](MODELAGEM_MONGODB.md)**. Implementação: `src/Database/Schema.php`
(`php bin/init_db.php`, idempotente).

| Coleção | Guarda | Decisão de modelagem |
|---|---|---|
| `users` | usuários internos (login) | hash da senha, perfil, ativo |
| `students` | alunos + vínculos com responsáveis | `guardians[]` **embutido** (≤ 3 itens); matrícula escolar única |
| `guardians` | contato dos responsáveis | **referenciado** (um responsável pode ter vários filhos); e-mail único |
| `academic_periods` | períodos letivos | `attendance_policy` **embutida** |
| `classes` | turmas do período | referencia `academic_periods` |
| `enrollments` | aluno ↔ turma ao longo do tempo | uma ativa por aluno (índice único parcial); histórico preservado |
| `attendance_records` | frequência diária | 1 por aluno/dia (índice único); `details` e `justification` **embutidos**; *snapshot* da turma; `status_history[]` embutido |
| `email_notifications` | e-mails de ausência | referencia a falta; `justification_link` e `events[]` **embutidos**; token só como hash |
| `audit_events` | auditoria | apoio |
| `password_resets` | redefinição de senha | apoio; hash do token, expira em 1 h, uso único |
| `login_attempts` | limite de tentativas de login | apoio; TTL |
| `message_templates` | texto predefinido do e-mail | apoio; variáveis `{{...}}` |

Regras transversais: IDs são `ObjectId` (string hex nas bordas); datas em `UTCDateTime`; datas de calendário em meia-noite UTC;
enums em UPPER_SNAKE (`Saa\Domain`); campos em inglês `snake_case`; textos de interface em pt-BR.

## 8. Perfis e permissões

| Permissão | Administrador | Coordenador | AOE | Secretária | Responsável |
|---|:-:|:-:|:-:|:-:|:-:|
| `dashboard.view` — painel de indicadores | ✓ | ✓ | ✓ | | |
| `absences.register` — registrar ausências | ✓ | ✓ | ✓ | | |
| `occurrences.view` — consultar ocorrências e justificativas | ✓ | ✓ | ✓ | | |
| `communications.send` — enviar/reenviar comunicações | ✓ | ✓ | ✓ | | |
| `registry.view` — consultar alunos, turmas e responsáveis | ✓ | ✓ | ✓ | ✓ | |
| `registry.edit` — cadastrar e alterar alunos, turmas e responsáveis | ✓ | | ✓ | ✓ | |
| `users.manage` — cadastrar usuários e definir perfis | ✓ | | | | |
| `justification.submit` — justificar pelo link do e-mail | | | | | ✓ |

*Responsável* não tem conta: acessa apenas `/justify/{token}`. Fonte única: `Saa\Security\Permissions`.

## 9. Rotas-alvo (por etapa)

Etapa 1 = Sprint 4 · Etapa 2 = Sprint 5 · Etapa 3 = Sprint 6 · Etapa 4 = Sprint 7 · Etapa 5 = Entrega final
(milestones do GitHub; ver [MAPA_ETAPAS.md](MAPA_ETAPAS.md)).

| Rota | Acesso | Etapa |
|---|---|:-:|
| `GET /health` | público (JSON: aplicação + MongoDB) | 1 |
| `GET/POST /login`, `POST /logout` | visitante / autenticado | 1 |
| `GET /` → `/home`; `GET /home` (tela inicial); `GET /me`; `GET /profiles` | autenticado | 1 |
| `GET/POST /forgot-password` (aviso na Etapa 1–2; fluxo completo na 3) | visitante | 2/3 |
| `GET/POST /students`, `/students/{id}` (+ `/archive`, `/guardians`, `/enrollment`) | `registry.view` / `registry.edit` | 2 |
| `GET/POST /guardians`, `/guardians/{id}` (+ `/archive`) | `registry.view` / `registry.edit` | 2 |
| `GET/POST /classes`, `/classes/{id}` (+ `/students`, `/archive`) | `registry.view` / `registry.edit` | 2 |
| `GET/POST /users`, `/users/{id}` | `users.manage` | 2 (cadastro) · 3 (busca) |
| `POST /api/webhooks/email` | segredo | 2 |
| `/api/students`, `/api/classes`, `/api/guardians`, `/api/enrollments`, `/api/attendance` (CRUD) | `registry.*` | 2 |
| `GET/POST /absences` | `absences.register` | 3 |
| `GET /occurrences`, `/occurrences/{id}`, `POST /occurrences/{id}/notifications/{nid}/resend` | `occurrences.view` / `communications.send` | 3 |
| `GET/POST /justify/{token}` | público (token) | 3 |
| `GET/POST /reset-password/{token}`, `GET/POST /forgot-password` | visitante | 3 |
| `GET /dashboard`, `GET /api/dashboard` | `dashboard.view` | 3 |
| `GET /api/students/{id}/context`, `GET /api/occurrences`, `GET /api/me` | conforme permissão | 2/3 |

## 10. API JSON

Mesma sessão e as mesmas permissões das telas. Escritas (`POST/PUT/PATCH/DELETE`) exigem `X-CSRF-Token` (obtido em `GET /api/me`).
Sucesso: `{"data": ..., "meta": {...}}` (listas: `meta.page`, `meta.per_page`, `meta.total`). Erro:
`{"error": {"code": "validation_error", "message": "...", "fields": {"name": "Informe o nome."}}}`.
Códigos: 200/201/204, 400, 401 (sem sessão), 403, 404, 409 (duplicidade), 422, 429. `DELETE` de cadastros = **arquivar**.
Campos sensíveis (`password_hash`, `token_hash`) nunca aparecem. IDs: `id` (string hex) no lugar de `_id`.

## 11. E-mail e notificações

1. Ao registrar uma ausência, o sistema cria **uma `email_notifications` por responsável** com e-mail `VALID` e
   `receives_absence_notifications=true`, com token novo (só o hash é gravado) e o texto montado a partir de `message_templates`.
   Sem nenhum responsável válido, a ausência é registrada mesmo assim e a tela avisa.
2. `MAIL_DISPATCH=inline` envia logo após o registro; `queue` deixa `PENDING` para `bin/process_mail_queue.php`.
   O processamento reserva a mensagem com `findOneAndUpdate` (campo `locked_at`), tenta até 3 vezes (`attempts`) e grava eventos.
3. `MailerInterface` com `LogMailer` (`MAIL_MODE=log`), `FileMailer` (`file`, grava `.eml` em `MAIL_FILE_DIR`) e
   `SmtpMailer` (`smtp`), escolhidos pela `MailerFactory`. O token do link é **gerado a cada tentativa de envio**: a
   notificação pendente guarda o texto com o marcador `{{justification_link}}` e o hash de um token descartável; só o
   e-mail que sai contém o link real, e o banco guarda apenas o novo hash (o validator recusa corpo com o link).
4. Estados: `PENDING → SENT → DELIVERED → OPENED` ou `FAILED`. **Enviado ≠ lido**: `DELIVERED/OPENED` só chegam por webhook do provedor;
   o acesso ao link gera o evento `LINK_ACCESSED`.
5. Reenvio cria **nova** notificação (novo token) referenciando a original (`resend_of`); a antiga é preservada.
6. Justificativa: o primeiro responsável a responder encerra a ocorrência (`PENDING → RESPONDED`, escrita atômica com filtro
   `justification.status = PENDING`); os demais links passam a mostrar "já respondida".

## 12. Convenções de código

- `declare(strict_types=1);` em todo arquivo PHP; classes `final` por padrão; tipos de parâmetro e retorno sempre declarados.
- Mensagens ao usuário em pt-BR; identificadores de código em inglês.
- Sem `var_dump`/`die` em código de produção; erros → exceções; logs via `error_log`.
- Nenhum SQL/PDO. Nenhuma consulta Mongo fora de `src/Models`.
- Mongo: um array PHP vazio vira *array* BSON — para documento vazio use `new \stdClass`.
- Datas: use `Clock`/`Bson` (nunca `date()`/`time()` diretos em regra de negócio).
- Commits: `etapa N (Sprint X): resumo` + corpo com as issues atendidas e o que foi validado.

## 13. Estratégia de testes

| Suite | Pasta | O que valida | Banco |
|---|---|---|---|
| Unit | `tests/Unit` | classes puras (Config, Bson, Validator, Router, Permissions, Csrf, enums) | não |
| Integration | `tests/Integration` | Models/Services/Schema contra MongoDB real (`MongoTestCase`: banco `saa_test_<hex>` por classe) | sim |
| Acceptance | `tests/Acceptance` | fluxos HTTP ponta a ponta com o servidor embutido do PHP (login, CSRF, permissões, justificativa) | sim |

Comandos: `composer test`, `composer test:unit`, `composer test:integration`, `composer test:acceptance`.
`SAA_REQUIRE_MONGO=1` (CI) faz testes que precisam do MongoDB **falharem** em vez de serem ignorados.
Cada requisito/critério de aceite de issue deve ter pelo menos um teste nomeado de forma rastreável.

## 14. Execução local (resumo)

```bash
composer install
cp .env.example .env            # ajuste MONGODB_URI
php bin/init_db.php             # coleções, validators e índices
php bin/create_user.php --name "Admin" --email admin@escola.local --profile ADMIN
composer serve                  # http://127.0.0.1:8000
```
Requisitos: PHP ≥ 8.3 com `ext-mongodb` ^2 (`mbstring`, `openssl`), Composer, MongoDB 6+ (local, Docker ou Atlas).
O guia completo está no `README.md`.
