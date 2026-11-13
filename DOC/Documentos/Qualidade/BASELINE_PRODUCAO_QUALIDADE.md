# Baseline de produção e qualidade — SAA

**Data:** 13/11/2026  
**Branch de revisão remota do código:** `fix/saa-absence-class-snapshot`  
**Branch local de trabalho:** `quality/saa-baseline-integrity`  
**Base revisada:** `main` em `8585c18af8582de1780018a9144d3df74d8730dc`

## Fonte de verdade e escopo

O MVP executável vigente é uma aplicação web em PHP 8.1+, HTML/CSS e SQLite. O banco padrão fica em `var/saa.sqlite3`; os testes são executados por `php tests/run.php`. O arquivo `saa_flask_mongodb_dashboard_atualizado.zip` é material anterior, não a arquitetura ativa de `main`.

Esta baseline cobre as etapas 1 (estado inicial e critérios de qualidade) e 2 (integridade de dados e migrações) do fluxo de produção. Não altera funcionalidades fora desse escopo.

## Estado inicial observado

- A aplicação já registra ausências, gera notificações por e-mail, coleta justificativas por link e oferece telas para equipe escolar.
- As migrações eram executadas como um arquivo inicial sem controle de versão aplicado no banco.
- A ocorrência guardava o aluno, mas não a turma no momento do registro. Consultas históricas inferiam a turma atual do cadastro do aluno; uma transferência mudava retrospectivamente a turma exibida.
- `public/index.php` concentra roteamento, autorização, consultas e composição de páginas. `Services.php` e `Database.php` também reúnem responsabilidades distintas.
- A aplicação usa PDO e consultas parametrizadas. O isolamento por serviços é parcial, com dependência direta de PDO e métodos estáticos.

## Avaliação SOLID e arquitetura

O código existente não pode ser declarado integralmente aderente a SOLID. SRP e DIP têm pontos claros de melhoria pela concentração de responsabilidades e dependências concretas/estáticas. OCP também depende de condicionais e rotas centralizadas. LSP não é um critério efetivamente exercitado porque a implementação não usa hierarquias de tipos no domínio; ISP não tem interfaces de domínio suficientes para avaliação significativa.

Esta etapa registra o diagnóstico sem introduzir uma refatoração ampla que misture mudança arquitetural com correção de persistência. A separação de controladores, casos de uso, interfaces de repositório e adaptadores de banco deve ser planejada em uma etapa própria, com testes de regressão.

## Critérios de aceite das etapas 1 e 2

1. Migrações ordenadas são aplicadas uma vez e registradas em `schema_migrations`.
2. Uma falha durante uma migração reverte sua transação e não registra a versão como aplicada.
3. Cada ausência nova guarda a turma informada no registro.
4. Consultas históricas usam a turma armazenada na ocorrência, sem depender da turma atual do aluno.
5. A migração de banco existente preenche `class_id`; a origem histórica de transferências anteriores não é recuperável e esse limite fica documentado.
6. A turma da ocorrência não pode ser alterada depois de gravada.
7. Reaplicar migrações não duplica alterações.

## Evidências e situação de aprovação

Foram adicionados testes automatizados para transferência do aluno, imutabilidade do snapshot e reaplicação das migrações. Nesta sessão, o ambiente não possui PHP/`pdo_sqlite`, então `php tests/run.php` não pôde ser executado. A validação SQL com SQLite disponível no ambiente é complementar e não substitui a suíte PHP.

**Status:** a documentação da baseline foi integrada pelo PR #52 em `c983603` (13/11/2026). A alteração de código descrita nesta revisão está em `fix/saa-absence-class-snapshot` e segue pendente de aprovação de arquitetura e QA. A suíte PHP ainda precisa ser executada; a evidência SQLite isolada não substitui essa execução.

## Branches e organização

`main` permanece a fonte de verdade. A branch `feat/php-mongodb` contém uma proposta de arquitetura divergente e deve permanecer preservada até decisão explícita de migração. A branch `claude/saa-funcionalidades-sprint5-6` contém alterações associadas ao ZIP legado e também deve ser preservada até triagem. `docs/organiza-documentacao` já não aparecia na lista remota de branches consultada após a integração do PR #51.
