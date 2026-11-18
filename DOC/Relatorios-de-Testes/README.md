# Relatórios de testes da equipe

Esta pasta reúne os relatórios individuais de testes do SAA. Cada integrante deve criar um arquivo próprio e registrar o que executou, o resultado observado e as evidências necessárias para reproduzir falhas.

## Nome dos arquivos

Use `AAAA-MM-DD_nome-sobrenome.md` (exemplo: `2026-11-15_ana-silva.md`). Se fizer mais de um relatório no mesmo dia, acrescente um assunto ao nome, como `2026-11-15_ana-silva_cadastros.md`. Não substitua relatórios de outros integrantes.

## Estrutura sugerida para cada relatório

```markdown
# Relatório de teste — [nome do integrante]

- Data:
- Versão testada (branch e commit):
- Ambiente (versão do MongoDB, sistema operacional, navegador e, no teste de notificação, modelo do celular):
- Funcionalidade testada:
- Objetivo e pré-condições:

| ID | Passos executados | Resultado esperado | Resultado observado | Estado |
| --- | --- | --- | --- | --- |
| T01 | | | | Aprovado/Reprovado/Bloqueado |

## Falhas encontradas

- ID do teste:
- Como reproduzir:
- Mensagem de erro ou evidência:
- Issue relacionada (se houver):

## Conclusão

- Total de testes:
- Aprovados:
- Reprovados:
- Bloqueados:
- Pendências:
```

Use dados fictícios nos testes e nas imagens. Não inclua senhas, tokens de justificativa, e-mails reais de responsáveis ou dados pessoais de alunos nos relatórios. Registre o commit testado para permitir que outra pessoa repita a verificação.
