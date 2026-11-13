# Chamada completa do SAA em MongoDB

**Status:** desenho de implementação para revisão  
**Branch de trabalho:** `feat/saa-mongodb-complete-attendance`  
**Issue:** #46

## Objetivo

Registrar uma chamada por turma e data com situação explícita de cada aluno elegível. A chamada completa é a fonte confiável para frequência; ocorrências e notificações são efeitos derivados.

## Modelo proposto

### `attendance_sessions` — registro canônico

Um documento por turma e data, com:

- `class_id`, nome e período da turma no dia;
- data da chamada, usuário responsável e instante de criação;
- `entries[]` com `student_id`, `enrollment_id`, snapshots mínimos de nome/matrícula e status `PRESENT` ou `ABSENT`;
- `payload_hash` do roster e das respostas normalizadas;
- estado `PROCESSING` ou `COMPLETED`, mais dados de auditoria do processamento.

Índice único: `{ class_id: 1, date: 1 }`. O servidor obtém a matrícula válida para a data e compara o roster completo: nenhum aluno elegível pode faltar e nenhum aluno de outra turma, inativo ou fora do período pode entrar. A gravação inicial de um documento da sessão é atômica no MongoDB.

### `attendance_records` — projeção de ausências

Manter esta coleção para o fluxo existente de justificativas e histórico. Criar um registro apenas para cada aluno `ABSENT`, acrescentando a referência à sessão. Não criar registros de presença fictícios em históricos anteriores.

### Notificações

Criar/enfileirar comunicação somente para ausentes e responsáveis elegíveis. Cada ocorrência e notificação precisa de uma chave idempotente derivada da sessão, aluno e responsável, além dos índices únicos correspondentes. O worker pode retomar sessões em `PROCESSING`: upserts e chaves idempotentes impedem duplicações se o processo cair após uma gravação parcial. Marcar a sessão `COMPLETED` somente depois que as ocorrências e comunicações estiverem persistidas na fila.

Essa abordagem evita depender de transações multi-documento. Se a implementação optar por transações, o MongoDB e o CI deverão usar replica set; MongoDB standalone não oferece transações multi-documento.

## Regras de API e concorrência

1. A interface envia o estado de todos os alunos; o backend recalcula e valida a lista elegível.
2. Sessão duplicada para turma/data retorna conflito sem alterar a sessão anterior.
3. Uma submissão idêntica pode retomar uma sessão interrompida usando `payload_hash`; payload diferente exige conflito e correção explícita auditada.
4. O endpoint legado de ausência individual não pode converter presença em falta nem alterar uma sessão concluída. Para chamadas controladas por sessão, deve rejeitar conflito.
5. A ocorrência não é apagada ou recriada em reenvios de e-mail; reenviar afeta apenas a comunicação.

## Compatibilidade histórica e relatórios futuros

- Sessões antigas inexistentes não serão reconstruídas a partir de ausências: os presentes históricos são desconhecidos.
- Métricas de frequência usarão somente sessões `COMPLETED`.
- Snapshots de turma e matrícula preservam a turma correspondente à data, mesmo após transferência ou renomeação.
- Gráficos e relatórios serão adicionados depois da validação do fluxo de chamada.

## Verificações obrigatórias

- Roster completo, parcial, excedente, com aluno transferido e com matrícula fora da data.
- Chamadas com todos presentes, todos ausentes e combinação de estados.
- Submissões simultâneas ou duplicadas para turma/data.
- Falha após salvar sessão, após projetar ocorrência e após enfileirar comunicação; repetição deve concluir sem duplicatas.
- Responsável ausente/inativo ou sem e-mail elegível.
- Reenvio de e-mail sem alteração da chamada ou das métricas.
- Autorização, CSRF, validação de ObjectId e rejeição de operadores NoSQL em entradas.
- Tamanho máximo e estabilidade do documento da sessão.
- Testes de integração executados contra MongoDB; registrar versão e configuração (standalone ou replica set).

## Limite desta etapa

Este arquivo registra o desenho acordado pelos revisores. Não representa implementação concluída nem evidência de testes. A implementação deve atualizar schema/índices, modelos, serviços, endpoint, interface, worker e testes antes de aprovar a issue #46.
