# Aplicação das correções de documentação — Grupo EVITUS / PI3-2026

O pacote altera **apenas a pasta `Sprint/`**. O código PHP, o banco SQLite, os materiais em `Diagramas/` e o ZIP Flask/MongoDB não são modificados.

## Como aplicar com segurança

1. Faça backup ou uma branch de trabalho no seu clone local do repositório.
2. Extraia este ZIP na raiz do clone `PI3-2026`, permitindo substituir os arquivos sob `Sprint/`.
3. No terminal, confira: `git status --short` e `git diff -- Sprint`.
4. Revise os textos, especialmente as datas e o escopo acordado com o grupo.
5. Depois da aprovação da equipe, faça commit em uma branch e abra um Pull Request.

## Justificativa

Corrigidos links quebrados, números de issues trocados, menções a issues inexistentes, divergências da arquitetura e afirmações sobre entregas sem evidência. Mantida distinção entre as datas históricas das sprints e os commits efetivos de 27/10.

**Importante:** o conector GitHub recusou escrita com HTTP 403; por isso nenhuma mudança foi enviada ao repositório remoto. Este pacote contém as versões prontas para revisão e aplicação manual.
