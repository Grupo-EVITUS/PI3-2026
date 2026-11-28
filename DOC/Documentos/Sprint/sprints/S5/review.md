# Sprint Review 5 — Integração e homologação

**Período:** 02/11/2026 a 08/11/2026

**Situação em 04/11:** sprint em andamento, com review a confirmar.

**Verificação técnica posterior:** 28/11/2026. A data da review formal permanece a confirmar.

## Objetivo

O planejamento original previa integrar o MVP PHP/SQLite, testar os fluxos e obter homologação. A verificação posterior usou a versão PHP/MongoDB executada no Docker. [README principal](../../../../../README.md)

## Resultado documentável até 04/11

| Item interno | Situação |
|---|---|
| S5-01 Integração | Código de várias etapas presente na `main`, ainda sem teste integrado formal anexado |
| S5-02 Perfis | Controle no código (`AOE`, `COORDENACAO`, `DIRETOR`); falta evidência de testes de autorização |
| S5-03 Testes | `tests/run.php` escrito, sem registro de execução e resultado nesta review |
| S5-04 Bugs | Issue [#34](https://github.com/Grupo-EVITUS/PI3-2026/issues/34) aberta; sem relatório consolidado de correção |
| S5-05 E-mail | Worker e SMTP configurável em código; envio real não comprovado |
| S5-06 Homologação | Issue [#35](https://github.com/Grupo-EVITUS/PI3-2026/issues/35) aberta; sem aceite formal |

## Evidências técnicas de 28/11/2026

- **E-mail e justificativa.** Uma falta fictícia gerou uma mensagem na caixa local do Mailpit. O link da mensagem abriu o formulário e a justificativa foi registrada. A entrega na caixa do Gmail depende da configuração e da validação de um SMTP externo.
- **Push.** O teste de integração com a chave gerada pelo Docker passou pelo convite, pela ativação, pelo registro da chamada, pela entrega criptografada no serviço simulado e pela justificativa. Em teste anterior com o Chrome, o serviço real de push aceitou o envio. A exibição do aviso no aparelho permanece a confirmar. [Teste de integração](../../../../../tests/Integration/push_web.php)
- **Navegação.** Chamada, Ocorrências, Cadastros, Funcionários e Frequência foram abertas no Chrome com larguras de 1366 px e 390 px, com o conteúdo ajustado à largura da janela. A conferência foi feita com a conta de Coordenação. A validação formal das permissões de todos os perfis permanece pendente.
- **GitHub.** As issues de correção de bugs e homologação foram fechadas em 28/11. A issue de homologação define os critérios de aceite e está sem comentários que registrem a aprovação. [Issue #34](https://github.com/Grupo-EVITUS/PI3-2026/issues/34) · [Issue #35](https://github.com/Grupo-EVITUS/PI3-2026/issues/35)

O ambiente local e a geração da chave de push constam na versão publicada em 28/11. As evidências acima são posteriores ao período planejado. [Commit `68c7942`](https://github.com/Grupo-EVITUS/PI3-2026/commit/68c7942dac9845f411762b05ff18c8fc85a7ec91)

## Pendências para a review formal

- Confirmar data, participantes, feedback e aceite da equipe.
- Registrar a versão demonstrada e as evidências de acesso por perfil.
- Validar o recebimento por SMTP externo e a exibição do push no aparelho.
