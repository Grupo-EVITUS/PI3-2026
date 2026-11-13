# Diagramas novos — Sistema de Ausência de Alunos (SAA)

**Repositório de referência:** [Grupo-EVITUS/PI3-2026](https://github.com/Grupo-EVITUS/PI3-2026)  
**Versão de referência:** branch `main`, commit `a0e7781` (28/10/2026).  
**Arquitetura documentada:** PHP 8.1+, SQLite, PDO, HTML/CSS, worker SMTP/log.

## O que contém

| # | Diagrama | Finalidade | Fonte editável | Imagem vetorial |
|---|---|---|---|---|
| 1 | Casos de uso | Papéis e funcionalidades | [`01_casos_de_uso.mmd`](fontes_mermaid/01_casos_de_uso.mmd) | [SVG](diagramas_visuais/01_casos_de_uso.svg) / [PNG](diagramas_visuais/01_casos_de_uso.png) |
| 2 | Atividades / fluxo | Ausência → fila → justificativa | [`02_atividades_fluxo.mmd`](fontes_mermaid/02_atividades_fluxo.mmd) | [SVG](diagramas_visuais/02_atividades_fluxo.svg) / [PNG](diagramas_visuais/02_atividades_fluxo.png) |
| 3 | Classes do domínio | Objetos de negócio conceituais | [`03_classes_dominio.mmd`](fontes_mermaid/03_classes_dominio.mmd) | [SVG](diagramas_visuais/03_classes_dominio.svg) / [PNG](diagramas_visuais/03_classes_dominio.png) |
| 4 | DER do SQLite | Tabelas, campos e relações | [`04_der_sqlite.mmd`](fontes_mermaid/04_der_sqlite.mmd) | [SVG](diagramas_visuais/04_der_sqlite.svg) / [PNG](diagramas_visuais/04_der_sqlite.png) |
| 5 | Sequência | Interações PHP, serviço, SQLite, worker e responsável | [`05_sequencia_registro_justificativa.mmd`](fontes_mermaid/05_sequencia_registro_justificativa.mmd) | [SVG](diagramas_visuais/05_sequencia_registro_justificativa.svg) / [PNG](diagramas_visuais/05_sequencia_registro_justificativa.png) |
| 6 | Estados | Ciclos separados da ausência, comunicação e fila | [`06_estados_ausencia_comunicacao.mmd`](fontes_mermaid/06_estados_ausencia_comunicacao.mmd) | [SVG](diagramas_visuais/06_estados_ausencia_comunicacao.svg) / [PNG](diagramas_visuais/06_estados_ausencia_comunicacao.png) |

## Pré-visualização

### Casos de uso
![Casos de uso](diagramas_visuais/01_casos_de_uso.svg)

### Atividades / fluxo
![Atividades / fluxo](diagramas_visuais/02_atividades_fluxo.svg)

### Classes do domínio
![Classes do domínio](diagramas_visuais/03_classes_dominio.svg)

### DER do SQLite
![DER do SQLite](diagramas_visuais/04_der_sqlite.svg)

### Sequência
![Sequência](diagramas_visuais/05_sequencia_registro_justificativa.svg)

### Estados
![Estados](diagramas_visuais/06_estados_ausencia_comunicacao.svg)

## Premissas e limitações técnicas

1. O diagrama de **classes** representa entidades de domínio modeladas a partir das tabelas SQLite; elas **não são classes PHP definidas no código**. No PHP atual, as classes de aplicação são `Auth`, `Database` e `Services`, além de funções auxiliares.
2. O DER foi extraído conceitualmente de `migrations/001_initial.sql`: dez tabelas. O vínculo `students.class_id` aponta para a **turma atual**; a versão PHP não possui tabela separada de matrículas históricas (`enrollments`).
3. Uma ausência corresponde a **aluno + dia**, com restrição `UNIQUE (student_id, absence_date)`. Não é um registro de presença/aula.
4. O responsável recebe **link público individual**, sem login próprio. A aplicação valida o token por hash. `expires_at` existe na tabela, mas os links **não expiram por padrão na v1**; a primeira justificativa encerra a possibilidade de novas respostas à mesma ocorrência.
5. A comunicação utiliza `communications` e `mail_queue`. O processamento de envio ocorre via worker separado. **Modo padrão `MAIL_MODE=log` não envia e-mail real**. A aceitação pelo SMTP não comprova leitura nem entrega na caixa de entrada.
6. `PENDENTE` / `RESPONDIDA` são estados de **ausência**; `PENDENTE` / `ENVIADO` / `FALHA` são estados de **comunicação**; `AGUARDANDO` / `PROCESSANDO` / `CONCLUIDO` / `FALHA` são estados do item na **fila**.
7. A versão PHP possui perfis `AOE`, `COORDENACAO`, `DIRETOR`, diferentes dos materiais legados Flask/MongoDB. Diretor tem permissão de consulta, não escrita; Coordenação gerencia cadastros.
8. Diagramas mostram o fluxo funcional de forma simplificada; não substituem a execução de testes, auditoria de segurança, nem homologação de SMTP.

## Como editar e publicar no GitHub

- **Mermaid:** edite os `.mmd` em `fontes_mermaid/` e copie seu conteúdo dentro de um bloco `mermaid` de Markdown para renderizar no GitHub.
- **Graphviz:** os `.dot` em `fontes_graphviz/` são fontes dos desenhos produzidos (o diagrama 05 usa Mermaid + desenho vetorial de linhas de vida).
- **Visual:** `.svg` é vetor escalável e `.png` é rasterizado para apresentação. O arquivo `index.html` abre os seis visuais em um navegador.
- Sugestão: coloque esta pasta em `Diagramas/Versao_PHP_SQLite/`, preservando os JPG/JPEG já existentes para rastreabilidade histórica.

## Base consultada

- [`migrations/001_initial.sql`](https://github.com/Grupo-EVITUS/PI3-2026/blob/main/migrations/001_initial.sql)
- [`src/Services.php`](https://github.com/Grupo-EVITUS/PI3-2026/blob/main/src/Services.php)
- [`src/Auth.php`](https://github.com/Grupo-EVITUS/PI3-2026/blob/main/src/Auth.php)
- [`src/MailQueue.php`](https://github.com/Grupo-EVITUS/PI3-2026/blob/main/src/MailQueue.php)
- [`public/index.php`](https://github.com/Grupo-EVITUS/PI3-2026/blob/main/public/index.php)
- [`README.md`](https://github.com/Grupo-EVITUS/PI3-2026/blob/main/README.md)
