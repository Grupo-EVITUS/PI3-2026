# Pendências identificadas na auditoria documental

**Referência:** `main` até 28/10/2026; issues consultadas em 04/11/2026. Não é um relatório de testes executados.

## Críticas

- **Alinhar arquitetura oficial:** os documentos históricos citam Flask/MongoDB e Docker, enquanto o README raiz e o código executável descrevem PHP/SQLite. Registrar a decisão do grupo; não afirmar que houve migração aprovada sem ata/evidência.
- **Dashboard:** issues [#46](https://github.com/Grupo-EVITUS/PI3-2026/issues/46) e [#48](https://github.com/Grupo-EVITUS/PI3-2026/issues/48) não têm implementação correspondente no PHP auditado. Confirmar se fazem parte da entrega ou serão evoluções.
- **Critérios de dados:** issue [#38](https://github.com/Grupo-EVITUS/PI3-2026/issues/38) pede nascimento do aluno, [#39](https://github.com/Grupo-EVITUS/PI3-2026/issues/39) pede telefone do responsável; banco PHP não apresenta esses campos.
- **Issues abertas apesar de código parcial:** validar critérios e evidências antes de encerrar issues #25, #27, #36, #38–#50.
- **Homologação:** não foi localizada uma revisão de testes executados; o arquivo `tests/run.php` é código de teste, não evidência de execução.

## Funcionais e segurança

- **Envio de e-mail:** `MAIL_MODE=log` não envia e-mail; testar SMTP real em homologação, evitando salvar credenciais no Git.
- **Tokens:** links de justificativa não expiram na versão PHP v1; decidir prazo de validade e testar. A primeira justificativa encerra a ocorrência.
- **Consulta:** a listagem PHP tem filtro por status, mas ainda faltam filtros por aluno, turma e período exigidos na issue #49.
- **Recuperação de senha:** issue [#32](https://github.com/Grupo-EVITUS/PI3-2026/issues/32) aberta; fluxo não localizado no PHP.
- **Histórico escolar e privacidade:** não há entidade de matrículas históricas no SQLite; validar LGPD, acesso e retenção antes de usar dados reais.

## Documentação e processo

- **Sprint 1:** datas de fechamento de issues ocorreram após a review declarada; anexar evidências de apresentação, sem mudar a data histórica arbitrariamente.
- **Sprint 2:** revisar diagrama JPG disponível em `Diagramas` e substituir referências antigas a `.mmd`, `.png` e templates Flask inexistentes na raiz da `main`.
- **Sprints 3 e 4:** ID e título das issues estavam trocados; documentos corrigidos incluem rastreabilidade.
- **Sprints 5 e 6:** números #51–#66 não existem; utilizar IDs internos ou criar issues com novos números concedidos pelo GitHub.
- **Sprint 6:** confirmar data final oficial, pois um plano dizia 14/11 e outro 16/11.

## Próxima conferência antes da entrega

Executar `php tests/run.php`, testes manuais de perfis e SMTP (se configurado); guardar os resultados; revisar issues; completar reviews com fatos observados e links de commits. Nenhuma dessas ações foi executada durante a auditoria de documentação.
