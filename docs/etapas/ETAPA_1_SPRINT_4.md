# Etapa 1 — Sprint 4: Arquitetura e Protótipos

**Branch:** `feat/php-mongodb` · **Milestone:** Sprint 4 – Arquitetura e Protótipos · **Situação:** concluída

## Objetivo

Definir e construir a base técnica do SAA em PHP + MongoDB — arquitetura, modelagem do banco, design system e os
protótipos funcionais das telas de login e inicial — de modo que as etapas seguintes só acrescentem funcionalidades.

## Issues atendidas

| Issue | Critério | Evidência |
|---|---|---|
| #16 Planejamento do design do sistema | paleta de cores da Sala do Futuro | tokens em `public/assets/css/saa.css`; tabela de cores e contraste WCAG calculado em [DESIGN_SISTEMA.md](../DESIGN_SISTEMA.md) |
| | planejamento da estrutura do frontend | seção 6 de [DESIGN_SISTEMA.md](../DESIGN_SISTEMA.md); layouts e parciais em `views/` |
| #18 Diagrama de classes | diagrama de classes | [DIAGRAMA_DE_CLASSES.md](../DIAGRAMA_DE_CLASSES.md) (domínio, enums e arquitetura) |
| #20 Requisitos da modelagem de dados | requisitos, coleções, índices, regras de integridade | [MODELAGEM_MONGODB.md](../MODELAGEM_MONGODB.md): 22 requisitos MD, cada um ligado a testes; `src/Database/Schema.php` |
| #21 Protótipo da tela de login | autenticação representada e funcionando | `GET/POST /login`; testes em `tests/Acceptance/LoginFlowTest.php` |
| #22 Protótipo do frontend — tela inicial | tela inicial | `GET /home` (saudação, perfil, atalhos, ambiente MongoDB) |

## O que foi entregue

- **Arquitetura MVC sem framework** ([ARQUITETURA.md](../ARQUITETURA.md)): roteador com controle de acesso por rota,
  container com autowiring, sessão endurecida, CSRF, validador, cabeçalhos de segurança e tratamento de erros HTTP.
- **MongoDB integrado**: 12 coleções com validators `$jsonSchema` (`strict`/`error`) e 33 índices nomeados, criados por
  `php bin/init_db.php` (idempotente).
- **Login** com mensagem genérica, limite de tentativas (429), sessão regenerada, auditoria e usuário relido a cada
  requisição (desativar um usuário encerra a sessão dele).
- **Telas**: login, "esqueceu a senha" (informativa até a Etapa 3), início, meu perfil, perfis e permissões; erro
  403/404/405/429/500 com página própria.
- **Ferramentas**: `bin/create_user.php` (cria usuários pelo terminal), `bin/init_db.php`.
- **CI** no GitHub Actions: PHP 8.3, 8.4 e 8.5 com MongoDB 7, lint, políticas de CSP e todos os testes.
- **Documentação**: arquitetura, modelagem, design system, diagramas, requisitos não funcionais e mapa das etapas.

## Decisões

1. **MongoDB no lugar do SQLite** do MVP anterior (que continua no histórico da `main`, commit `260a850`).
2. **Modelo de dados = as 8 coleções da atividade de modelagem do grupo**, com 4 coleções de apoio e pequenas extensões
   (documentadas na seção 8 de [MODELAGEM_MONGODB.md](../MODELAGEM_MONGODB.md)).
3. **Perfis**: Administrador, Coordenador, AOE, Secretária e Responsável (sem login), conforme a issue #50.
4. **Etapas = milestones do GitHub** (ver [MAPA_ETAPAS.md](../MAPA_ETAPAS.md)).

## Como validar

```bash
composer install
php bin/init_db.php
php bin/create_user.php --name "Administrador" --email admin@escola.local --profile ADMIN
composer serve                 # http://127.0.0.1:8000/login
composer test                  # unitários + integração (MongoDB) + aceitação (HTTP)
```

## Resultado dos testes

Execução local (Windows, PHP 8.5.3, MongoDB 7.0.45): **750 testes, todos passando**, distribuídos entre unitários
(núcleo, enums, permissões), integração com o MongoDB (schema, consultas frequentes, usuários, autenticação) e
aceitação HTTP (login, sessão, permissões, cabeçalhos, arquivos estáticos). Os mesmos testes rodam no CI a cada push.

## Pendências levadas para a Etapa 2

- Docker Compose e volume persistente (RNF-06, RNF-13); webhook de e-mail (RNF-04).
- Cadastros (alunos, responsáveis, turmas, usuários), seed de dados fictícios e API JSON.
- O fluxo real de "esqueceu a senha" é da Etapa 3 (#32); até lá a tela orienta procurar o administrador.
