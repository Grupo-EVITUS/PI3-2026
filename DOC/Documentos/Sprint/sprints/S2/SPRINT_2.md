# Sprint 2 — Especificação técnica e prototipação

**Período:** 20/09/2026 a 26/09/2026  
**Objetivo histórico:** especificar requisitos não funcionais, modelo de dados, diagrama de classes e protótipo de login.  
**Nota de arquitetura:** a proposta original era **Flask/MongoDB**. O MVP executável da `main`, versionado em 27/10, utiliza **PHP/SQLite**. Não atribuir entregas do código de 27/10 à janela de setembro sem evidência adicional.

## 1. Backlog real do GitHub

| Issue | Entrega | Situação consultada em 04/11 |
|---|---|---|
| [#10](https://github.com/Grupo-EVITUS/PI3-2026/issues/10) | Levantar requisitos não funcionais | Fechada em 03/10 |
| [#20](https://github.com/Grupo-EVITUS/PI3-2026/issues/20) | Planejar requisitos da modelagem | Aberta |
| [#18](https://github.com/Grupo-EVITUS/PI3-2026/issues/18) | Diagrama de classes | Aberta |
| [#21](https://github.com/Grupo-EVITUS/PI3-2026/issues/21) | Protótipo da tela de login | Aberta |

## 2. Requisitos não funcionais definidos

| Código | Requisito | Como verificar na versão atual |
|---|---|---|
| RNF-01 | Senhas com hash seguro | `password_hash`/`password_verify` em `src/Services.php` e `public/index.php` |
| RNF-02 | Sessão e perfis no acesso interno | `src/Auth.php`, rotas PHP |
| RNF-03 | Token público aleatório com integridade | `communications.token_hash`; o link PHP **não recebe expiração** na v1 |
| RNF-04 | Acesso restrito por ocorrência | `Services::publicJustification` consulta somente ocorrência do token |
| RNF-05 | Sem ausência duplicada por aluno/data | índice `UNIQUE(student_id, absence_date)` |
| RNF-06 | Auditoria de operações | tabela `operation_logs` |
| RNF-07 | Interface responsiva | `public/assets/app.css`; revisão visual ainda necessária |
| RNF-08 | Execução reproduzível | README principal: servidor PHP integrado + worker; Docker não configurado na `main` |
| RNF-09 | Proteção CSRF | `Auth::validateCsrf()` nos POST |
| RNF-10 | Privacidade/LGPD | Validar política de retenção, controles de acesso e exposição de dados antes de uso real |

Requisitos históricos para agregações MongoDB, webhook de abertura de e-mail, Flask, Docker Compose e API JSON devem permanecer identificados como **planejamento anterior, não conformidade comprovada do MVP PHP**.

## 3. Modelo de dados e evolução

O plano histórico MongoDB usava `students`, `enrollments`, `attendance_records`, `guardians`, `justifications`, `justification_links`, `email_notifications`, `audit_events` e `attendance_policies`. Na versão executável, o esquema efetivo está em [`migrations/001_initial.sql`](../../../migrations/001_initial.sql), com tabelas relacionais `users`, `classes`, `students`, `guardians`, `student_guardians`, `absences`, `communications`, `mail_queue`, `justifications` e `operation_logs`.

Diferenças importantes: o aluno aponta diretamente para uma turma no MVP PHP, sem coleção/tabela histórica `enrollments`; só são registradas **ausências diárias**, não presenças `PRESENT`/`ABSENT`; o dashboard de frequência não está implementado.

## 4. Diagrama e protótipo

- Diagrama de classes existente: [`Diagramas/Diagram De Classe/Diagrama De Classe.jpg`](../../../Diagramas/Diagram%20De%20Classe/Diagrama%20De%20Classe.jpg). **Rever aderência** ao esquema PHP antes do aceite da issue #18.
- Os arquivos antigos `diagrama_classes_saa.mmd`, `diagrama_classes_saa.png`, `views/templates/aoe_login.html` e `views/static/css/saa.css` **não existem neste caminho na `main`**. Portanto, não devem ser citados como entregas disponíveis nesta pasta.
- Tela de login executável atual: [`templates/login.php`](../../../templates/login.php), com rotas em [`public/index.php`](../../../public/index.php), adicionadas em 27/10. Não comprova protótipo entregue em setembro.

## 5. Casos de aceitação pendentes de evidência

- Entrada inválida e correta no login PHP, validações e controle por perfil.
- Comparação do diagrama atualizado com o banco em produção do MVP.
- Expiração de tokens: **não suportada como regra padrão na v1**; demanda mudança de requisito ou implementação.
- Teste de apresentação em desktop/mobile e validação de segurança.

## 6. Definition of Done

Considerar cada item concluído apenas após entrega documentada, evidência de teste/review e atualização da respectiva issue. Atualmente há especificação parcial e código posterior; não há documentação suficiente para declarar as quatro issues concluídas em setembro.
