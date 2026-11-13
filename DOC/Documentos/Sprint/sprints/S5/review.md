# Sprint Review 5 — Integração e homologação

**Período:** 02/11/2026 a 08/11/2026  
**Data da revisão:** a definir após 08/11  
**Status em 04/11:** sprint em andamento / **review não realizada ou não evidenciada**.

## Objetivo

Integrar o MVP PHP/SQLite, testar fluxos e obter homologação.

## Resultado documentável até 04/11

| Item interno | Situação |
|---|---|
| S5-01 Integração | Código de várias etapas presente na `main`, ainda sem teste integrado formal anexado |
| S5-02 Perfis | Controle no código (`AOE`, `COORDENACAO`, `DIRETOR`); falta evidência de testes de autorização |
| S5-03 Testes | `tests/run.php` escrito, sem registro de execução e resultado nesta review |
| S5-04 Bugs | Issue [#34](https://github.com/Grupo-EVITUS/PI3-2026/issues/34) aberta; sem relatório consolidado de correção |
| S5-05 E-mail | Worker e SMTP configurável em código; envio real não comprovado |
| S5-06 Homologação | Issue [#35](https://github.com/Grupo-EVITUS/PI3-2026/issues/35) aberta; sem aceite formal |

## Evidências a registrar na review real

- Data, participantes e versão/commit demonstrados.
- Saída do teste `php tests/run.php` e testes de acesso por perfil.
- Evidência do fluxo ausência → notificação → justificativa → atualização de status.
- Se e-mail real for testado: ambiente de homologação, provedor e resultado (sem credenciais nos anexos).
- Bugs identificados/corrigidos e novas pendências.
- Decisão formal sobre dashboard, gráficos e filtros pendentes.

**Não registrar como concluído** um objetivo apenas porque aparece no plano. Preencher feedback e aceite quando a reunião acontecer.
