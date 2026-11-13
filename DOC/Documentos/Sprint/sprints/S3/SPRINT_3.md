# Sprint 3 — Implementação e validação da base

**Período planejado:** 27/09/2026 a 03/10/2026  
**Objetivo histórico:** transformar a especificação em ambiente executável e registrar cadastros, frequência e comunicação.  
**Observação:** o plano original previa Flask/MongoDB e Docker; o MVP PHP/SQLite só foi versionado em 27/10/2026.

## Backlog (IDs internos, não números de issues GitHub)

| ID interno | Entrega prevista | Situação/evidência atual |
|---|---|---|
| S3-01 | Seed com dados fictícios | Seed PHP em `src/Database.php` (27/10); quantidade diferente do plano inicial |
| S3-02 | Persistência e prevenção de duplicidade | SQLite e índice único em `migrations/001_initial.sql` (27/10); MongoDB não integrado ao PHP |
| S3-03 | CRUD de alunos, turmas e responsáveis | Código PHP em `src/Services.php` e `templates/management.php` (27/10) |
| S3-04 | Registro de ausência | `Services::recordAbsences`, 27/10; não registra presença `PRESENT` |
| S3-05 | Dashboard e ranking | Não localizados no MVP PHP; previsto para evolução |
| S3-06 | Consulta de aluno e responsáveis | Vínculos e listagens PHP presentes; pesquisa histórica avançada ausente |
| S3-07 | Link de justificativa | Token e formulário PHP presentes; expiração automática ausente |
| S3-08 | Webhook de entrega/leitura | Não localizado no MVP PHP; há envio SMTP e registro de falha/sucesso do worker |
| S3-09 | Execução reprodutível | README do MVP documenta PHP e worker; Docker Compose não localizado na `main` |

Os IDs antigos `SAA-31/#31` a `SAA-39/#39` foram removidos porque os números reais das issues representam outras atividades. Exemplo: [#31](https://github.com/Grupo-EVITUS/PI3-2026/issues/31) = controle de acesso; [#32](https://github.com/Grupo-EVITUS/PI3-2026/issues/32) = recuperação de senha; [#33](https://github.com/Grupo-EVITUS/PI3-2026/issues/33) = testes funcionais.

## Modelo de dados previsto versus código

O plano inicial previa 12 alunos fictícios, três turmas, matrículas, registros `PRESENT/ABSENT` e ranking mensal/anual. O seed PHP possui usuários, turmas, alunos e responsáveis fictícios, mas isso não comprova o seed quantitativo ou o dashboard previstos. Verificar o conteúdo de `src/Database.php` antes de declarar esse critério atendido.

## Casos de teste relevantes

| Caso | Critério | Evidência localizada | Situação |
|---|---|---|---|
| CT-S3-01 | Ausência sem duplicidade | Restrição no esquema + teste em `tests/run.php` | Código de teste existente, execução não comprovada |
| CT-S3-02 | Lote de alunos na mesma turma | `Services::recordAbsences` e teste | Código de teste existente, execução não comprovada |
| CT-S3-03 | Responsável vinculado ao aluno | `student_guardians` e teste | Código de teste existente, execução não comprovada |
| CT-S3-04 | Token válido/inválido | `Services::publicJustification` e teste | Código de teste existente, execução não comprovada |
| CT-S3-05 | Dashboard com filtros | Sem código PHP localizado | Pendente |
| CT-S3-06 | Webhook seguro de provedor | Sem endpoint localizado | Pendente |
| CT-S3-07 | Persistência após reinício Docker | Sem configuração Docker PHP na `main` | Não validado |

## Definition of Done

Só declarar concluído com resultado de teste executado, referência de commit e aceite. Código de 27/10 é evidência posterior à janela da Sprint 3; registrar essa diferença no review.
