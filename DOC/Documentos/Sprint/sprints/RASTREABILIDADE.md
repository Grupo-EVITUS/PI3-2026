# Rastreabilidade — documentação, issues e commits

O recorte histórico registra o código da branch `main` até `a0e7781` (28/10/2026) e as issues consultadas em 04/11/2026. A seção de encerramento registra evidências posteriores, verificadas em 28/11/2026.

## Commits do recorte histórico

| Commit | Data | Evidência |
|---|---|---|
| [`273a2cf`](https://github.com/Grupo-EVITUS/PI3-2026/commit/273a2cf1a49da724159ed279383a74312376829d) | 27/10 | PHP, SQLite, migrações, sessões, CSRF |
| [`c858e30`](https://github.com/Grupo-EVITUS/PI3-2026/commit/c858e30427444226e645df0a9777760a94804e60) | 27/10 | Telas, cadastros, ausências, justificativas, consulta, perfis |
| [`260a850`](https://github.com/Grupo-EVITUS/PI3-2026/commit/260a85086e8fa102b4942b7c7b26c39ca87a043c) | 27/10 | Fila de e-mails, SMTP configurável, testes escritos |
| [`a0e7781`](https://github.com/Grupo-EVITUS/PI3-2026/commit/a0e7781652ca76800add049e791f200fb3996c6a) | 28/10 | Inserção dos documentos de sprint |

## Issues e evidências no recorte histórico

| Issue | Título no recorte | Código observado em 28/10 | Limite observado |
|---|---|---|---|
| [#25](https://github.com/Grupo-EVITUS/PI3-2026/issues/25) | Banco de dados | `migrations/001_initial.sql` e `src/Database.php` | Issue pede MongoDB; PHP usa SQLite |
| [#27](https://github.com/Grupo-EVITUS/PI3-2026/issues/27) | Tela de login | `templates/login.php` e rotas | Não inclui recuperação de senha |
| [#36](https://github.com/Grupo-EVITUS/PI3-2026/issues/36) | Cadastro de usuários | `Services::createStaffUser` | Não homologado |
| [#38](https://github.com/Grupo-EVITUS/PI3-2026/issues/38) | Cadastro de alunos | `Services::saveStudent` | Data de nascimento solicitada na issue não consta no esquema |
| [#39](https://github.com/Grupo-EVITUS/PI3-2026/issues/39) | Cadastro de responsáveis | `Services::saveGuardian` | Telefone solicitado na issue não consta no esquema |
| [#40](https://github.com/Grupo-EVITUS/PI3-2026/issues/40) | Cadastro de turmas | `Services::saveClass` | Testar critérios completos |
| [#41](https://github.com/Grupo-EVITUS/PI3-2026/issues/41) | Vínculo aluno/turma/responsável | `students.class_id` + `student_guardians` | Sem matrícula histórica |
| [#42](https://github.com/Grupo-EVITUS/PI3-2026/issues/42) | Registrar ausências | `Services::recordAbsences` | Sem registro de presença por aula |
| [#43](https://github.com/Grupo-EVITUS/PI3-2026/issues/43) | Envio automático de e-mails | `src/MailQueue.php` e worker | SMTP real não demonstrado |
| [#44](https://github.com/Grupo-EVITUS/PI3-2026/issues/44) | Formulário de justificativa | `templates/justification.php` | Tokens não expiram por padrão |
| [#45](https://github.com/Grupo-EVITUS/PI3-2026/issues/45) | Controle de status | `PENDENTE → RESPONDIDA` | Histórico de mudança precisa validação |
| [#46](https://github.com/Grupo-EVITUS/PI3-2026/issues/46) | Dashboard gerencial | Não localizado no PHP | Pendente |
| [#47](https://github.com/Grupo-EVITUS/PI3-2026/issues/47) | Registro de justificativas | `Services::submitJustification` | Testes escritos, aceite não comprovado |
| [#48](https://github.com/Grupo-EVITUS/PI3-2026/issues/48) | Gráficos do dashboard | Não localizados no PHP | Pendente |
| [#49](https://github.com/Grupo-EVITUS/PI3-2026/issues/49) | Consulta de ocorrências | Rota `/ocorrencias` + filtro status | Faltam filtros por aluno e turma |
| [#50](https://github.com/Grupo-EVITUS/PI3-2026/issues/50) | Perfis de usuário | `AOE`, `COORDENACAO`, `DIRETOR` | Diverge de Flask e da issue original |

Todas as issues da tabela estavam **abertas** na consulta de 04/11/2026. As issues de descoberta #3, #4, #5, #7, #8, #9 e #10 já estavam fechadas, com datas de encerramento posteriores às datas de review declaradas.

## Encerramento em 28/11/2026

| Entrega | Evidência verificada até 28/11 | Issues fechadas na consulta |
|---|---|---|
| MongoDB e chamada completa | O MongoDB passou a ser o único armazenamento. A chamada completa e a frequência por turma fazem parte do código publicado. [Remoção do SQLite](https://github.com/Grupo-EVITUS/PI3-2026/commit/811515ed353fd8116f0ba6aac34f09ad41857be1) · [Chamada e presença por turma](https://github.com/Grupo-EVITUS/PI3-2026/commit/08edb5ae50725bb7c1d45c331b68507027b15f6f) | [#46](https://github.com/Grupo-EVITUS/PI3-2026/issues/46), atualmente intitulada “Migrar o SAA para MongoDB, chamada completa e relatórios” |
| Gráficos de frequência | Gráficos por turma e aluno implementados no PHP com MongoDB. [Commit dos gráficos](https://github.com/Grupo-EVITUS/PI3-2026/commit/fde0bfd8a783b9cd114d869dca1b7fff9373aba0) | [#48](https://github.com/Grupo-EVITUS/PI3-2026/issues/48) |
| Recuperação de senha | Fluxo implementado para AOE e Coordenação. [Commit de recuperação](https://github.com/Grupo-EVITUS/PI3-2026/commit/93c87006274e12b1222e37efa3f66e8799a14a33) | [#32](https://github.com/Grupo-EVITUS/PI3-2026/issues/32) |
| Dados de alunos e responsáveis | Data de nascimento e telefone incluídos nos respectivos cadastros. [Commit dos campos](https://github.com/Grupo-EVITUS/PI3-2026/commit/9404eaa88842353ad2f59a9c4c0b4a1525692a7e) | [#38](https://github.com/Grupo-EVITUS/PI3-2026/issues/38) · [#39](https://github.com/Grupo-EVITUS/PI3-2026/issues/39) |
| Consulta de ocorrências | Filtro por status combinado com filtros por turma, aluno e período. [Commit dos filtros](https://github.com/Grupo-EVITUS/PI3-2026/commit/ad8d700df24281287b01498c0e001ab3d5cb3888) | [#49](https://github.com/Grupo-EVITUS/PI3-2026/issues/49) |
| Docker local e homologação | O Compose sobe a aplicação PHP, o banco MongoDB e os workers. O teste local confirmou e-mail no Mailpit, link de justificativa e o fluxo de push com serviço simulado e chave gerada localmente. A entrega ao Gmail e a exibição da notificação no aparelho seguem pendentes. [Commit do Docker](https://github.com/Grupo-EVITUS/PI3-2026/commit/68c7942dac9845f411762b05ff18c8fc85a7ec91) · [Review 5](S5/review.md) · [Review 6](S6/review.md) | [#34](https://github.com/Grupo-EVITUS/PI3-2026/issues/34) · [#35](https://github.com/Grupo-EVITUS/PI3-2026/issues/35). A #35 não tinha comentários na consulta de 28/11 e o aceite formal segue sem registro na review. |

## Regras

1. Nunca usar `#31` para seed, `#43` para gráficos ou `#46` para protótipo.
2. Usar IDs internos para o backlog das sprints quando a tarefa não estiver ligada a uma issue verificada no GitHub.
3. Associar um commit à data real, sem afirmar aprovação retroativa.
4. Só marcar como testado após registrar comando executado, saída e ambiente.
5. Só marcar como concluído/homologado com critérios de aceitação e aceite documentado.
