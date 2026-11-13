# Etapa 2 — Sprint 5: MVP Funcional

**Branch:** `feat/php-mongodb` · **Milestone:** Sprint 5 – MVP Funcional · **Situação:** concluída

## Objetivo

Colocar no ar o núcleo funcional do SAA sobre a base da Etapa 1: banco com dados, backend com API, cadastros com os
vínculos entre aluno, turma e responsável, usuários, tela inicial com dados e o envio de e-mails.

## Issues atendidas

| Issue | Critérios de aceite | Onde está | Testes |
|---|---|---|---|
| #25 Banco de dados | modelagem, script MongoDB, relacionamentos, testes | `bin/init_db.php`, `bin/seed.php`, `src/Database/` | `tests/Integration/Database`, `tests/Integration/Seed` |
| #26 Backend | estrutura, rotas principais, integração com o banco, testes | `src/`, `config/routes/`, API em `src/Controllers/Api` | `tests/Acceptance/Api` |
| #27 Tela de login | usuário, senha, entrar, "esqueceu a senha", validação, integração | `views/auth/login.php` | `tests/Acceptance/LoginFlowTest.php` |
| #28 Tela inicial | layout, navegação, dados do backend, responsividade | `views/home/index.php`, `HomeService` | `UsersAndHomeTest::testIssue28…` |
| #29 API de envio de e-mails | API, envio, tratamento de erros, banco, testes | `src/Services/Mail`, `NotificationService`, `/api/attendance`, `/api/notifications`, webhook | `tests/Unit/Mail`, `tests/Integration/Notifications`, `WebhookTest` |
| #36 Cadastro de usuários | formulário, validação, gravação, sucesso, erros | `/users` | `tests/Integration/Users`, `tests/Acceptance/Users` |
| #38 Cadastro de alunos | nome, matrícula, nascimento, turma, validação, gravação | `/students` | `tests/Integration/Registry`, `tests/Acceptance/Registry` |
| #39 Cadastro de responsáveis | nome, telefone, e-mail, vínculo com o aluno | `/guardians`, ficha do aluno | idem |
| #40 Cadastro de turmas | cadastro, identificação, associação de alunos, alteração | `/classes`, `/periods` | idem |
| #41 Relacionamento aluno–turma–responsável | vínculos, consulta dos vínculos, gravação | fichas de aluno, turma e responsável | idem |

A busca de usuários (#37, da Etapa 3) já foi entregue junto com o cadastro.

## Observações importantes

- **"Esqueceu a senha" (#27):** o botão existe e leva a uma página que orienta procurar o administrador, que pode
  definir uma nova senha em `/users`. A redefinição por e-mail é a issue #32 (Etapa 3).
- **Envio de e-mails (#29):** funciona por arquivo `.eml`, log ou SMTP. O envio por SMTP foi testado contra um servidor
  falso local; **não foi feito envio por um provedor real**, que depende de a equipe escolher e configurar um.
- **Registro de falta:** já existe no serviço e na API (`POST /api/attendance`), com as notificações. A tela para a
  AOE registrar ausências é da Etapa 3 (#42).
- **Token do link de justificativa:** é gerado a cada tentativa de envio; o banco guarda só o hash.

## Decisões

1. A matrícula escolar (RA) é gravada em maiúsculas e com espaços simples, para que `ra-001` não duplique `RA-001`.
2. Arquivar um aluno encerra a matrícula ativa (motivo `TRANSFER`); nada é apagado.
3. Períodos, turmas, alunos e responsáveis são editados por Administrador, AOE e Secretária; a Coordenação só consulta.
4. Os dados de demonstração usam nomes inventados e e-mails em domínios reservados (`.test`); o seed se recusa a rodar
   em produção.

## Como validar

```bash
php bin/init_db.php
SEED_PASSWORD="UmaSenhaForte!2026" php bin/seed.php     # escola fictícia
composer serve                                          # entre com admin@escola.test
composer test
docker compose up -d --build                            # alternativa com Docker (http://localhost:8080)
```

## Resultado dos testes

Execução local (Windows, PHP 8.5.3, MongoDB 7.0.45): **933 testes, todos passando**. No GitHub, o CI roda os mesmos
testes em PHP 8.3, 8.4 e 8.5 e um job que constrói e sobe o Docker Compose (saúde, login, fila estável e persistência
dos dados após reinício).

## Pendências levadas para a Etapa 3

Telas de registro de ausências, formulário de justificativa pelo link, consulta de ocorrências, dashboard com
gráficos, recuperação de senha por e-mail e a revisão final das permissões rota a rota.
