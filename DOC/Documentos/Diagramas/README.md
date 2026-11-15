# Diagramas novos — Sistema de Ausência de Alunos (SAA)

**Repositório de referência:** [Grupo-EVITUS/PI3-2026](https://github.com/Grupo-EVITUS/PI3-2026)  
**Versão de referência:** branch `main`, commit `a0e7781` (28/10/2026).  
**Arquitetura documentada:** PHP 8.1+, SQLite, PDO, HTML/CSS, worker SMTP/log.

## O que contém

Os seis diagramas PNG versionados nesta pasta representam a referência PHP/SQLite do commit indicado acima. Os arquivos Mermaid e SVG mencionados no índice anterior não estão presentes no repositório.

| Diagrama | Imagem disponível |
|---|---|
| Casos de uso | [PNG](Diagrama%20de%20Caso%20de%20Uso/01_casos_de_uso.png) |
| Atividades / fluxo | [PNG](Diagrama%20de%20Fluxo/02_atividades_fluxo.png) |
| Classes do domínio | [PNG](Diagram%20De%20Classe/03_classes_dominio.png) |
| DER do SQLite | [PNG](Diagrama%20Entidade-Relacionamento/04_der_sqlite.png) |
| Sequência | [PNG](Diagrama%20de%20Sequ%C3%AAncia/05_sequencia_registro_justificativa.png) |
| Estados | [PNG](Diagrama%20de%20Estados/06_estados_ausencia_comunicacao%20%282%29.png) |

## Pré-visualização

![Casos de uso](Diagrama%20de%20Caso%20de%20Uso/01_casos_de_uso.png)

![Atividades](Diagrama%20de%20Fluxo/02_atividades_fluxo.png)

![Classes](Diagram%20De%20Classe/03_classes_dominio.png)

![DER](Diagrama%20Entidade-Relacionamento/04_der_sqlite.png)

![Sequência](Diagrama%20de%20Sequ%C3%AAncia/05_sequencia_registro_justificativa.png)

![Estados](Diagrama%20de%20Estados/06_estados_ausencia_comunicacao%20%282%29.png)

## Premissas e limitações técnicas

1. O diagrama de **classes** representa entidades de domínio modeladas a partir das tabelas SQLite; elas **não são classes PHP definidas no código**. No PHP atual, as classes de aplicação são `Auth`, `Database` e `Services`, além de funções auxiliares.
2. O DER foi extraído conceitualmente de `migrations/001_initial.sql`: dez tabelas. O vínculo `students.class_id` aponta para a **turma atual**; a versão PHP não possui tabela separada de matrículas históricas (`enrollments`).
3. Uma ausência corresponde a **aluno + dia**, com restrição `UNIQUE (student_id, absence_date)`. Não é um registro de presença/aula.
4. O responsável recebe **link público individual**, sem login próprio. A aplicação valida o token por hash. `expires_at` existe na tabela, mas os links **não expiram por padrão na v1**; a primeira justificativa encerra a possibilidade de novas respostas à mesma ocorrência.
5. A comunicação utiliza `communications` e `mail_queue`. O processamento de envio ocorre via worker separado. **Modo padrão `MAIL_MODE=log` não envia e-mail real**. A aceitação pelo SMTP não comprova leitura nem entrega na caixa de entrada.
6. `PENDENTE` / `RESPONDIDA` são estados de **ausência**; `PENDENTE` / `ENVIADO` / `FALHA` são estados de **comunicação**; `AGUARDANDO` / `PROCESSANDO` / `CONCLUIDO` / `FALHA` são estados do item na **fila**.
7. A versão PHP possui perfis `AOE`, `COORDENACAO`, `DIRETOR`, diferentes dos materiais legados Flask/MongoDB. Diretor tem permissão de consulta, não escrita; Coordenação gerencia cadastros.
8. Diagramas mostram o fluxo funcional de forma simplificada; não substituem a execução de testes, auditoria de segurança, nem homologação de SMTP.

## Edição

Estas imagens não incluem fontes editáveis versionadas. Para alterações nos diagramas, produzir a fonte correspondente e incluí-la junto à imagem revisada, com indicação da versão do sistema representada.

## Base consultada

- [`migrations/001_initial.sql`](https://github.com/Grupo-EVITUS/PI3-2026/blob/main/migrations/001_initial.sql)
- [`src/Services.php`](https://github.com/Grupo-EVITUS/PI3-2026/blob/main/src/Services.php)
- [`src/Auth.php`](https://github.com/Grupo-EVITUS/PI3-2026/blob/main/src/Auth.php)
- [`src/MailQueue.php`](https://github.com/Grupo-EVITUS/PI3-2026/blob/main/src/MailQueue.php)
- [`public/index.php`](https://github.com/Grupo-EVITUS/PI3-2026/blob/main/public/index.php)
- [`README.md`](https://github.com/Grupo-EVITUS/PI3-2026/blob/main/README.md)
