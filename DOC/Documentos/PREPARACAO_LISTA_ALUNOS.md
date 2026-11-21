# Preparação da lista de alunos

Esta referência organiza a conferência da lista antes do cadastro no SAA. Ela
não contém dados da escola. A aplicação já cadastra turmas, alunos e
responsáveis pela tela da Coordenação; a importação em lote de uma planilha
externa ainda não foi implementada.

## Dados a conferir

| Cadastro | Campos necessários | Conferência |
| --- | --- | --- |
| Turma | Nome, série, ano letivo e turno | A turma precisa existir e estar ativa antes de vincular alunos. |
| Aluno | Nome, turma e matrícula, se houver; data de nascimento (opcional) | Matrícula informada deve ser única. Alunos de mesmo nome exigem identificação adicional para evitar troca de cadastro. |
| Responsável | Nome, e-mail, vínculo e aluno(s) associados; telefone (opcional) | O e-mail precisa ser válido e o vínculo confirmado antes do envio de comunicados. Um aluno pode ter mais de um responsável. |

Se a lista recebida trouxer apenas os nomes dos alunos, será possível revisar
turmas e cadastros, mas **não habilitar o fluxo de e-mail** até confirmar os
responsáveis e seus vínculos. A chamada registra os alunos ativos da turma;
não se deve inventar presença ou ausência durante a carga.

## Sequência para a carga real

1. Conferir a autorização de uso dos dados escolares e manter a lista em local restrito; não publicar a planilha nem dados pessoais no GitHub ou nos relatórios de teste.
2. Revisar turmas, matrículas repetidas, alunos duplicados e vínculos com responsáveis.
3. Ensaiar os cadastros em um ambiente isolado com dados fictícios e registrar divergências.
4. Com os dados reais autorizados, validar as contagens de turmas, alunos e vínculos e somente então habilitar o envio de e-mail no ambiente operacional.

A importação em lote de uma planilha ainda não existe; o formato da lista real
deverá ser confirmado antes de criar essa funcionalidade, se a equipe precisar dela.
