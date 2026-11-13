# Sprint 4 — Funcionalidades gerenciais e perfis

**Período planejado:** 04/10/2026 a 10/10/2026  
**Objetivo:** ampliar o controle gerencial, justificativas, gráficos, consulta de ocorrências e permissões.  
**Nota:** o escopo original pressupunha Flask/MongoDB. A `main` atual contém um MVP PHP/SQLite, versionado em 27/10.

## Backlog corrigido conforme as issues REAIS

| Issue | Entrega | Responsável(is) na issue | Evidência da `main` em 28/10 |
|---|---|---|---|
| [#46](https://github.com/Grupo-EVITUS/PI3-2026/issues/46) | Dashboard gerencial | Vagnerr11, pedroEmanuelPor | Não localizado dashboard gerencial PHP |
| [#47](https://github.com/Grupo-EVITUS/PI3-2026/issues/47) | Registro de justificativas | Vagnerr11 | `Services::submitJustification` + tabela `justifications` |
| [#48](https://github.com/Grupo-EVITUS/PI3-2026/issues/48) | Gráficos do dashboard | Leonardo360-arch | Não localizados gráficos PHP |
| [#49](https://github.com/Grupo-EVITUS/PI3-2026/issues/49) | Consulta de ocorrências | Lincoln0777 | `/ocorrencias` com filtro por status; faltam filtros avançados |
| [#50](https://github.com/Grupo-EVITUS/PI3-2026/issues/50) | Perfis de usuário | Lincoln0777 | `AOE`, `COORDENACAO`, `DIRETOR` em PHP |
| [#21](https://github.com/Grupo-EVITUS/PI3-2026/issues/21) | Protótipo de login (item originado na Sprint 2) | Vagnerr11, Lincoln0777 | Tela PHP funcional versionada em 27/10; protótipo histórico não localizado |

**Atenção:** as issues acima estavam abertas em 04/11/2026. Código localizado não autoriza marcar automaticamente como concluído.

## Escopo e critérios de aceitação

### Dashboard e gráficos — #46 e #48

Indicadores de alunos, responsáveis, ausências, justificativas, pendentes, respondidas e ausências por turma; filtros e gráficos com dados do banco. **Ainda não localizados no PHP**, portanto não indicar como entregues.

### Justificativas — #47

Formulário por token, seleção de motivo, persistência e mudança de status. Implementação PHP encontrada; exige teste de envio, tratamento da primeira resposta e decisão explícita sobre expiração.

### Consulta de ocorrências — #49

A implementação PHP lista registros e filtra `PENDENTE`/`RESPONDIDA`. Busca por aluno, turma, data/período e outros filtros exigidos na issue ainda precisa ser desenvolvida ou renegociada.

### Perfis — #50

Na versão PHP: AOE registra ausências, consulta e reenvia; Coordenação inclui gerenciamento de cadastros e usuários; Diretor consulta sem modificar. A nomenclatura anterior `COORDINATION/ADMIN` não corresponde aos perfis PHP.

### Login — #21

Existe `templates/login.php`, com autenticação e sessão. Validar responsividade e critérios da issue, especialmente eventual recuperação de senha (issue [#32](https://github.com/Grupo-EVITUS/PI3-2026/issues/32), ainda aberta).

## Critérios técnicos obrigatórios

- Sem acesso não autenticado às rotas internas.
- Responsável vê somente a ocorrência vinculada ao token.
- Ausência não duplica por aluno/data.
- Visualizações agregadas não duplicam aluno por ter múltiplos responsáveis.
- API SMTP/log e status de comunicação não devem ser descritos como confirmação de abertura do e-mail.
- Regras de LGPD, retenção e HTTPS devem ser revisadas antes de uso com dados reais.

## Testes propostos

| Caso | Resultado esperado | Evidência atual |
|---|---|---|
| CT-S4-01 | Registro de justificativa encerra ocorrência | Código/teste escrito; execução não comprovada |
| CT-S4-02 | Perfis restringem operações de escrita | Código PHP; teste de permissão por perfil não documentado |
| CT-S4-03 | Consulta por aluno/turma/período/status | Somente status localizado no PHP |
| CT-S4-04 | Dashboard com indicadores confiáveis | Pendente no PHP |
| CT-S4-05 | Gráficos mensais e anuais | Pendente no PHP |
| CT-S4-06 | Login acessível/responsivo | Tela e CSS existentes; ensaio visual não documentado |

## Definition of Done

Issue concluída somente após código, teste executado, demonstração e aceite; registrar o commit **com a data verdadeira**. Planejamento de outubro e implementação de 27/10 são fatos distintos.
