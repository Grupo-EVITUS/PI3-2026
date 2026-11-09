# SAA — Sistema de Ausência de Alunos

Sistema web para a escola **registrar as ausências dos alunos, avisar os responsáveis por e-mail no mesmo dia e
acompanhar as justificativas**. Feito em **PHP, HTML e CSS** com **MongoDB** (sem framework e sem CDN).

Projeto Integrador do 3º semestre (PI3 2026) — Grupo EVITUS.

## Situação

O sistema está sendo entregue por etapas, uma por *milestone* do GitHub
(detalhes em [docs/MAPA_ETAPAS.md](docs/MAPA_ETAPAS.md)):

| Etapa | Milestone | Conteúdo | Situação |
|:-:|---|---|---|
| 1 | Sprint 4 – Arquitetura e Protótipos | arquitetura, modelagem MongoDB, design system, login e tela inicial | ✅ concluída |
| 2 | Sprint 5 – MVP Funcional | cadastros (alunos, responsáveis, turmas, usuários), vínculos, API, e-mail, dados fictícios, Docker | em andamento |
| 3 | Sprint 6 – Desenvolvimento Completo | registro de ausências, envio automático, justificativa, ocorrências, dashboard, perfis, recuperação de senha | planejada |
| 4 | Sprint 7 – Homologação | testes funcionais, correções, homologação | planejada |
| 5 | Entrega Final PI3 | documentação final e apresentação | planejada |

O que já funciona hoje: login (com limite de tentativas), tela inicial, "meu perfil", matriz de perfis e permissões e
a criação do banco (12 coleções com validação e índices).

## Requisitos

- **PHP 8.3 ou superior** com as extensões `mongodb` (2.x), `mbstring` e `openssl` (e `curl` para os testes)
- **Composer** 2
- **MongoDB 6 ou superior** — instalado no computador, em Docker ou no MongoDB Atlas (testado com 7.0)

### Habilitar a extensão `mongodb` no Windows

1. Descubra a versão do seu PHP: `php -v` (ex.: `PHP 8.5.3 (cli) ... NTS ... x64`).
2. Baixe o zip correspondente na página de releases oficial do driver:
   <https://github.com/mongodb/mongo-php-driver/releases> — arquivo
   `php_mongodb-<versão>-<PHP 8.x>-nts-vs17-x86_64.zip` (use `ts` se o seu PHP for *Thread Safe*).
3. Copie a DLL de dentro do zip para a pasta `ext` do PHP com o nome `php_mongodb.dll`.
4. No `php.ini` (veja o caminho com `php --ini`), acrescente a linha `extension=mongodb`.
5. Confira: `php -m | findstr mongodb` deve mostrar `mongodb`.

> **Atenção com o PHP 8.5.3:** a DLL **2.5.6** não carrega nessa versão ("Não foi possível encontrar o procedimento
> especificado"), porque foi compilada para um PHP 8.5.x mais novo. Use a **2.5.0**
> (`php_mongodb-2.5.0-8.5-nts-vs17-x86_64.zip`) ou atualize o PHP.

No Linux: `sudo pecl install mongodb` e `extension=mongodb` no `php.ini` (ou o pacote `php-mongodb` da distribuição).

### MongoDB

Qualquer uma das opções:

- **Instalado no Windows:** MongoDB Community Server (<https://www.mongodb.com/try/download/community>), que roda como
  serviço em `mongodb://127.0.0.1:27017`.
- **Docker:** `docker run -d --name saa-mongo -p 27017:27017 -v saa-mongo:/data/db mongo:7` (o `docker compose` completo
  do projeto chega na Etapa 2).
- **MongoDB Atlas** (gratuito): use a *connection string* `mongodb+srv://...` em `MONGODB_URI`.

## Como executar

```bash
composer install
cp .env.example .env          # no PowerShell: Copy-Item .env.example .env
```

Ajuste `MONGODB_URI` e `MONGODB_DB` no `.env` se precisar. Depois:

```bash
php bin/init_db.php           # cria coleções, validators e índices (pode rodar quantas vezes quiser)
php bin/create_user.php --name "Administrador" --email admin@escola.local --profile ADMIN
composer serve                # http://127.0.0.1:8000
```

O `create_user.php` usa a senha da variável `INITIAL_USER_PASSWORD`; sem ela, gera uma senha forte e mostra **uma
única vez**. Perfis: `ADMIN`, `COORDINATION`, `AOE`, `SECRETARY`.

```powershell
# PowerShell: definindo a senha
$env:INITIAL_USER_PASSWORD = "UmaSenhaForte!2026"
php bin/create_user.php --name "Ana AOE" --email aoe@escola.local --profile AOE
Remove-Item Env:INITIAL_USER_PASSWORD
```

Sem Composer no PATH, o servidor também sobe com `php -S 127.0.0.1:8000 router.php`. Em Apache (XAMPP/Laragon), aponte
o *DocumentRoot* para `public/` (o `public/.htaccess` já encaminha as rotas para o `index.php`).

## Testes

```bash
composer test                 # tudo
composer test:unit            # sem banco
composer test:integration     # contra o MongoDB (usa bancos temporários saa_test_*, apagados ao final)
composer test:acceptance      # fluxos HTTP com o servidor embutido do PHP
```

Os testes de integração e aceitação usam `MONGODB_URI` (padrão `mongodb://127.0.0.1:27017`) e são **ignorados** se o
MongoDB não estiver acessível — com `SAA_REQUIRE_MONGO=1` eles **falham** (é assim no CI). O GitHub Actions roda tudo
a cada push com PHP 8.3, 8.4 e 8.5 e MongoDB 7 ([.github/workflows/ci.yml](.github/workflows/ci.yml)).

## Estrutura

```text
bin/        init_db.php, create_user.php (linha de comando)
config/     rotas (config/routes/*.php) e menu (navigation.php)
docs/       arquitetura, modelagem, design system, diagramas, requisitos e relatórios de etapa
public/     index.php (ponto de entrada) e assets (CSS, JS, ícones)
src/        Core (mini-framework), Controllers, Services, Models, Database, Domain, Security, Support
tests/      Unit, Integration, Acceptance
views/      templates PHP (layouts, parciais e telas)
Sprint/     documentos de Sprint da equipe
```

## Documentação

| Documento | Conteúdo |
|---|---|
| [docs/ARQUITETURA.md](docs/ARQUITETURA.md) | camadas, rotas, segurança, convenções e testes |
| [docs/MODELAGEM_MONGODB.md](docs/MODELAGEM_MONGODB.md) | coleções, validators, índices, decisões e as 3 consultas frequentes |
| [docs/DESIGN_SISTEMA.md](docs/DESIGN_SISTEMA.md) | paleta, contraste, componentes, estrutura do frontend e protótipos |
| [docs/DIAGRAMA_DE_CLASSES.md](docs/DIAGRAMA_DE_CLASSES.md) | diagramas de classes (domínio e arquitetura) |
| [docs/RNF.md](docs/RNF.md) | requisitos não funcionais e como cada um é verificado |
| [docs/MAPA_ETAPAS.md](docs/MAPA_ETAPAS.md) | etapas × milestones e issues do GitHub |
| [docs/etapas/](docs/etapas/) | relatório de cada etapa |

## Premissas de negócio

Herdadas do MVP anterior e mantidas (a escola deve validá-las):

1. A ausência é **diária** (não por aula).
2. A AOE registra as ausências; a coordenação também pode registrar.
3. Responsáveis **não têm conta**: recebem um link individual e temporário por e-mail.
4. Havendo vários responsáveis, a **primeira justificativa** encerra a ocorrência.
5. Motivos: doença, consulta médica, problema familiar, problema de transporte e "outro" (com explicação obrigatória).
6. Falta justificada continua contando como falta nos indicadores.

## Histórico

A primeira versão executável (PHP + SQLite) está no histórico da branch `main` (commit `260a850`). Esta versão
substitui o SQLite pelo MongoDB, seguindo a modelagem feita pelo grupo.
