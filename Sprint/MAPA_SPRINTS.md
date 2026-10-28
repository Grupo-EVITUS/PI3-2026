# Mapa de Sprints — SAA

Este documento organiza a sequência do projeto a partir da revisão anexada pela equipe.

## Sprint 1 — Descoberta e requisitos iniciais

**Período registrado:** 13/09/2026 a 19/09/2026  
**Review:** 19/09/2026  
**Status:** Concluída

A Sprint 1 identificou o problema, o público-alvo, os stakeholders, as personas, a jornada do usuário, as necessidades e os requisitos funcionais iniciais.

### Itens concluídos

- `SAA-3 / #3` — pesquisar o contexto do problema;
- `SAA-4 / #4` — identificar as principais dores dos usuários;
- `SAA-5 / #5` — identificar as partes interessadas;
- `SAA-7 / #7` — mapear a jornada do usuário;
- `SAA-8 / #8` — levantar necessidades dos usuários;
- `SAA-9 / #9` — levantar requisitos funcionais.

### Próximos itens indicados na Review da Sprint 1

- `#10` — levantar requisitos não funcionais;
- `#20` — planejar requisitos da modelagem de dados;
- `#18` — desenvolver o diagrama de classes;
- `#21` — criar o protótipo da tela de login.

## Sprint 2 — Especificação técnica e prototipação

**Status:** Documentada

A Sprint 2 é a continuação direta da Sprint 1. Ela atende exatamente aos quatro próximos itens indicados na review anexada.

### Itens

- `SAA-10 / #10` — requisitos não funcionais;
- `SAA-20 / #20` — requisitos da modelagem de dados;
- `SAA-18 / #18` — diagrama de classes;
- `SAA-21 / #21` — protótipo da tela de login.

### Documentos

- `SPRINT_2.md`;
- `review.md`;
- `diagrama_classes_saa.mmd`;
- `diagrama_classes_saa.png`;
- `PROTOTIPO_LOGIN_SPRINT_2.md`.

## Sprint 3 — Implementação e validação da base

**Status:** Planejada

A Sprint 3 transforma a especificação da Sprint 2 em ambiente executável e validado.

### Itens principais

- seed de dados fictícios;
- conexão e índices MongoDB;
- CRUD da AOE;
- registro diário de frequência;
- dashboard e ranking;
- contexto de notificação;
- link de justificativa;
- webhook de e-mail;
- execução Docker.

### Documentos

- `SPRINT_3.md`;
- `review_sprint_3.md`.

## Sprint 4 — Funcionalidades gerenciais e perfis

**Status:** Planejada

A Sprint 4 corresponde à lista de tarefas atribuída pela equipe:

| Item | Entrega | Responsável(is) | Prioridade |
|---|---|---|---|
| `#41` | Implementar Dashboard Gerencial | Vagnerr11 e pedroEmanuelPor | Alta |
| `#42` | Implementar Registro de Justificativas | Vagnerr11 | Alta |
| `#43` | Implementar Gráficos do Dashboard | Leonardo360-arch | Alta |
| `#44` | Implementar Consulta de Ocorrências | Lincoln0777 | Alta |
| `#45` | Implementar Perfis de Usuário | Lincoln0777 | Alta |
| `#46` | Criar protótipo da tela de login | Vagnerr11 e Lincoln0777 | Baixa |

### Documentos

- `SPRINT_4.md`;
- `review_sprint_4.md`.

## Sprint 5 — Integração e homologação

**Período:** 02/11/2026 a 08/11/2026  
**Status:** Planejada

A Sprint 5 integra dashboard, ocorrências e justificativas, valida permissões, executa testes de aceitação, corrige inconsistências e prepara o ambiente de homologação.

## Sprint 6 — Finalização e entrega

**Período:** 09/11/2026 a 16/11/2026  
**Status:** Planejada

A Sprint 6 corrige pendências da homologação, finaliza a documentação, organiza o GitHub, prepara a apresentação e realiza a entrega do projeto.

## Conclusão sobre a numeração

A revisão anexada não é uma nova Sprint 1. Ela é a revisão da Sprint 1 e indica os itens que devem ser desenvolvidos na Sprint 2.

Assim, a sequência correta é:

```text
Sprint 1 — Descoberta e requisitos iniciais
        ↓
Sprint 2 — Especificação técnica e prototipação
        ↓
Sprint 3 — Implementação e validação da base
        ↓
Sprint 4 — Funcionalidades gerenciais e perfis
        ↓
Sprint 5 — Integração e homologação
        ↓
Sprint 6 — Finalização e entrega
```

O projeto está organizado em seis Sprints. As Sprints 5 e 6 dependem dos resultados reais da implementação e da homologação das Sprints anteriores.
