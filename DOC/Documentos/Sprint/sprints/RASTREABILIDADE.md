# Rastreabilidade — documentação, issues e commits

**Fonte auditada:** branch `main` até `a0e7781` (28/10/2026), issues consultadas em 04/11/2026. **Implementação localizada ≠ teste executado ≠ aceite da issue.**

## Commits

| Commit | Data | Evidência |
|---|---|---|
| [`273a2cf`](https://github.com/Grupo-EVITUS/PI3-2026/commit/273a2cf1a49da724159ed279383a74312376829d) | 27/10 | PHP, SQLite, migrações, sessões, CSRF |
| [`c858e30`](https://github.com/Grupo-EVITUS/PI3-2026/commit/c858e30427444226e645df0a9777760a94804e60) | 27/10 | Telas, cadastros, ausências, justificativas, consulta, perfis |
| [`260a850`](https://github.com/Grupo-EVITUS/PI3-2026/commit/260a85086e8fa102b4942b7c7b26c39ca87a043c) | 27/10 | Fila de e-mails, SMTP configurável, testes escritos |
| [`a0e7781`](https://github.com/Grupo-EVITUS/PI3-2026/commit/a0e7781652ca76800add049e791f200fb3996c6a) | 28/10 | Inserção dos documentos de sprint |

## Issues principais e evidências

| Issue | Título correto | Código observado na `main` | Limite |
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

Todas as issues acima estavam **abertas** em 04/11/2026. As issues de descoberta #3, #4, #5, #7, #8, #9 e #10 estão fechadas, mas suas datas de encerramento são posteriores às datas de review declaradas.

## Regras

1. Nunca usar `#31` para seed, `#43` para gráficos ou `#46` para protótipo.
2. Não criar links para `#51–#66` sem criar tais issues no GitHub; usar IDs internos de sprint.
3. Associar um commit à data real, sem afirmar aprovação retroativa.
4. Só marcar como testado após registrar comando executado, saída e ambiente.
5. Só marcar como concluído/homologado com critérios de aceitação e aceite documentado.
