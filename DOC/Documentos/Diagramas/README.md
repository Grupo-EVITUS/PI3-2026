# Diagramas — Sistema de Ausência de Alunos (SAA)

**Repositório:** [Grupo-EVITUS/PI3-2026](https://github.com/Grupo-EVITUS/PI3-2026)
**Atualizado em:** 18/11/2026 (branch `refactor/remover-sqlite`)

Esta pasta tem dois conjuntos:

1. **[Diagramas atuais](#diagramas-atuais-php--mongodb)**, em Mermaid, escritos neste arquivo. O GitHub os desenha direto na página, e o texto é a fonte editável. Representam o sistema em PHP + MongoDB.
2. **[Diagramas históricos](#diagramas-históricos-php--sqlite)**, os seis PNG das subpastas. Representam a primeira versão, em PHP + SQLite (commit `a0e7781`, 28/10/2026), que foi substituída.

## Diagramas atuais (PHP + MongoDB)

### Coleções e relacionamentos

O MongoDB não tem chaves estrangeiras: as setas são referências por `ObjectId`, mantidas pelas classes `Mongo*` dentro de transações. Detalhes de cada coleção na [referência do banco](../MONGODB_FOUNDATION.md).

```mermaid
erDiagram
    users ||--o{ attendance_sessions : "faz (completed_by)"
    users ||--o{ absences : "registra (registered_by)"
    users ||--o{ push_invites : "gera (created_by)"
    users ||--o{ operation_logs : "aparece em"
    classes ||--o{ students : "turma atual"
    classes ||--o{ attendance_sessions : "chamada do dia"
    students ||--o{ student_guardians : "vínculo"
    guardians ||--o{ student_guardians : "vínculo"
    students ||--o{ absences : "falta"
    attendance_sessions ||--o{ absences : "gera"
    absences ||--o{ communications : "avisa"
    guardians ||--o{ communications : "recebe"
    communications ||--o| mail_queue : "e-mail"
    communications ||--o| push_queue : "notificação"
    communications |o--o{ communications : "reenvio / lembrete"
    guardians ||--o{ push_subscriptions : "celulares (até 5 ativos)"
    guardians ||--o{ push_invites : "convites"
    absences ||--o| justifications : "uma resposta"
    communications ||--o| justifications : "link usado"

    users {
        ObjectId _id
        string email
        string email_key
        string profile
        bool active
    }
    classes {
        ObjectId _id
        string name
        string grade
        int academic_year
        string shift
        bool active
    }
    students {
        ObjectId _id
        string name
        string registration_no
        ObjectId class_id
        bool active
    }
    guardians {
        ObjectId _id
        string name
        string email
        string relationship
        bool active
    }
    attendance_sessions {
        ObjectId _id
        ObjectId class_id
        string attendance_date
        array entries
        string roster_hash
        string status
    }
    absences {
        ObjectId _id
        ObjectId student_id
        ObjectId class_id
        ObjectId attendance_session_id
        string absence_date
        string status
    }
    communications {
        ObjectId _id
        ObjectId absence_id
        ObjectId guardian_id
        string token_hash
        string channel
        string status
    }
    mail_queue {
        ObjectId _id
        ObjectId communication_id
        string recipient
        string status
        int attempts
    }
    push_queue {
        ObjectId _id
        ObjectId communication_id
        string status
        object fallback_email
        date reminder_due_at
    }
    push_subscriptions {
        ObjectId _id
        ObjectId guardian_id
        string endpoint
        bool active
    }
    push_invites {
        ObjectId _id
        ObjectId guardian_id
        string token_hash
        date expires_at
        date used_at
    }
    justifications {
        ObjectId _id
        ObjectId absence_id
        string reason
        string explanation
    }
    operation_logs {
        ObjectId _id
        ObjectId user_id
        string action
        string entity
        date happened_at
    }
```

### Da chamada à justificativa

```mermaid
sequenceDiagram
    actor AOE as AOE / Coordenação
    participant Web as SAA (web)
    participant DB as MongoDB
    participant WP as Worker de push
    participant WM as Worker de e-mail
    participant Cel as Celular / e-mail do responsável
    actor Resp as Responsável

    AOE->>Web: POST /chamada (todos os alunos da turma)
    Web->>DB: transação: chamada + faltas + avisos + filas + auditoria
    Web-->>AOE: resumo (presentes, ausentes, avisos por canal)
    alt responsável com celular ativo
        WP->>DB: reserva item da push_queue
        WP->>Cel: notificação cifrada (Web Push + VAPID)
        alt algum aparelho aceitou
            WP->>DB: aviso ENVIADO (PUSH)
        else nenhum aparelho aceitou
            WP->>DB: e-mail de reserva vai para a mail_queue
        end
    else sem celular ativo
        WM->>DB: reserva item da mail_queue
        WM->>Cel: e-mail com link (SMTP)
        WM->>DB: aviso ENVIADO ou, após 3 falhas, FALHA
    end
    Resp->>Web: abre /justificar/{link}
    Web->>DB: confere hash do link e aviso ENVIADO
    Resp->>Web: envia motivo
    Web->>DB: transação: justificativa + falta RESPONDIDA + auditoria
```

### Estados

A falta e o aviso:

```mermaid
stateDiagram-v2
    state "Falta (absences)" as falta {
        state "PENDENTE" as falta_pendente
        state "RESPONDIDA" as falta_respondida
        [*] --> falta_pendente: chamada
        falta_pendente --> falta_respondida: primeira justificativa
    }
    state "Aviso (communications)" as aviso {
        state "PENDENTE" as aviso_pendente
        state "ENVIADO" as aviso_enviado
        state "FALHA" as aviso_falha
        [*] --> aviso_pendente: chamada, reenvio ou lembrete
        aviso_pendente --> aviso_enviado: e-mail aceito pelo SMTP ou push aceito por um aparelho
        aviso_pendente --> aviso_falha: 3 falhas no e-mail
        aviso_falha --> [*]: reenvio cria um aviso novo
    }
```

As filas (`mail_queue` e `push_queue`):

```mermaid
stateDiagram-v2
    [*] --> AGUARDANDO
    AGUARDANDO --> PROCESSANDO: worker reserva
    PROCESSANDO --> CONCLUIDO: entregue
    PROCESSANDO --> AGUARDANDO: falha temporária (1, 2, 4 min)
    PROCESSANDO --> AGUARDANDO: reserva parada há mais de 5 min
    PROCESSANDO --> FALHA: 3ª falha (só e-mail)
    PROCESSANDO --> ENCAMINHADO_EMAIL: nenhum aparelho recebeu (só push)
    QUARENTENA --> QUARENTENA: nunca processado (só e-mail)
```

### Premissas representadas

1. Uma falta é **aluno + dia**, nunca por aula. A turma da falta é a do dia da chamada.
2. A chamada inclui **todos** os alunos ativos da turma; cada turma tem uma chamada por dia.
3. `PENDENTE`/`RESPONDIDA` é o estado da **falta** (com ou sem justificativa); `PENDENTE`/`ENVIADO`/`FALHA` é o estado do **aviso**; os estados das **filas** são os do último diagrama.
4. O responsável não tem login: o link (guardado como hash) é a credencial, e a primeira justificativa encerra a falta.
5. "Enviado" significa aceito pelo servidor SMTP ou pelo serviço de push; não comprova leitura. Por isso existe o lembrete por e-mail depois de um push sem resposta.
6. Perfis: **AOE** faz a chamada e acompanha; **Coordenação** também cadastra; **Diretor** só consulta.

## Diagramas históricos (PHP + SQLite)

Os seis PNG abaixo foram feitos para a primeira versão do sistema, em PHP + SQLite (commit `a0e7781`, 28/10/2026). Ficam como registro do projeto. **Não descrevem o sistema atual**, principalmente nos seguintes pontos:

- o **DER** mostra tabelas SQLite; hoje são as coleções do diagrama acima;
- o diagrama de **classes** mostra entidades do domínio, e o texto original citava as classes PHP `Auth`, `Database` e `Services`, das quais `Database` e `Services` não existem mais;
- o registro era por **aluno ausente**; hoje é a **chamada completa** da turma;
- o aviso era só por **e-mail**; hoje há notificação push com e-mail de reserva;
- os estados não incluem reserva vencida, `QUARENTENA`, `ENCAMINHADO_EMAIL` nem lembrete.

| Diagrama | Imagem |
|---|---|
| Casos de uso | [PNG](Diagrama%20de%20Caso%20de%20Uso/01_casos_de_uso.png) |
| Atividades / fluxo | [PNG](Diagrama%20de%20Fluxo/02_atividades_fluxo.png) |
| Classes do domínio | [PNG](Diagram%20De%20Classe/03_classes_dominio.png) |
| DER do SQLite | [PNG](Diagrama%20Entidade-Relacionamento/04_der_sqlite.png) |
| Sequência | [PNG](Diagrama%20de%20Sequ%C3%AAncia/05_sequencia_registro_justificativa.png) |
| Estados | [PNG](Diagrama%20de%20Estados/06_estados_ausencia_comunicacao%20%282%29.png) |

Os PNG não têm fonte editável no repositório. Para um diagrama novo, prefira Mermaid neste arquivo: o texto fica versionado e o GitHub desenha a imagem.
