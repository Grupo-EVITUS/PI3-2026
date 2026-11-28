# Pendências de encerramento

**Verificação de encerramento em 28/11/2026.** A [rastreabilidade](RASTREABILIDADE.md) preserva o recorte histórico do código em 28/10 e das issues em 04/11.

## Validação técnica

- **E-mail externo.** O teste local confirmou a entrega no Mailpit e a abertura do link de justificativa. Falta configurar o SMTP externo e comprovar a entrega ao Gmail no ambiente de destino. [Configuração e testes](../../../../README.md)
- **Push no aparelho.** O teste local de integração com serviço simulado passou. Em um teste anterior, o serviço real aceitou o envio, mas a exibição da notificação no aparelho segue sem confirmação. Falta registrar o resultado do teste de ponta a ponta no aparelho. [Fluxo de push](../../../../README.md#notificação-push-no-android-pwa)
- **Testes de aceite.** Para novas verificações, usar os testes do MongoDB descritos no README e no workflow do GitHub. Registrar ambiente, comando, resultado e versão testada. [Testes atuais](../../../../README.md#testes) · [Workflow](../../../../.github/workflows/saa-mongodb-foundation.yml)
- **Uso de dados reais.** Avaliar acesso, retenção e tratamento de dados escolares antes do uso em produção. O link de justificativa continua sem expiração, conforme o comportamento descrito no projeto. [Justificativa](../../../../README.md#justificativa)

## Registro da entrega

- **Review e aceite.** A apresentação ocorreu em 27/11. A issue de homologação estava fechada em 28/11, sem comentários de aceite. Registrar participantes, feedback, versão demonstrada e aceite da equipe ou do professor responsável. [Issue #35](https://github.com/Grupo-EVITUS/PI3-2026/issues/35) · [Review 5](S5/review.md) · [Review 6](S6/review.md)
- **Data final da Sprint 6.** O planejamento registra 14/11 e 16/11 como referências de encerramento. A data oficial ainda precisa ser confirmada pela equipe. [Planejamento da Sprint 6](S6/SPRINT_6.md)
- **Versão de entrega.** Registrar o commit apresentado. A consulta de encerramento em 28/11 não encontrou tag de entrega no GitHub.

## O que mudou desde a auditoria inicial

Em 28/10, o código auditado usava PHP e SQLite. As pendências de implementação em 04/11 incluíam armazenamento MongoDB, gráficos de frequência, filtros de ocorrências, recuperação de senha e os campos de nascimento do aluno e telefone do responsável. Em 28/11, essas funcionalidades constavam na versão PHP com MongoDB e o repositório oferecia execução local com Docker Compose. As evidências de código e os estados das issues estão na [rastreabilidade de encerramento](RASTREABILIDADE.md#encerramento-em-28112026).
