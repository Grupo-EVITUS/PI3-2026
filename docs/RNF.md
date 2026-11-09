# Requisitos não funcionais — SAA

**Origem:** issue #10 e `Sprint/sprints/S2/SPRINT_2.md` (RNF-01 a RNF-15), revisados para PHP + MongoDB, e acrescidos
dos requisitos que a arquitetura adotou (RNF-16 em diante). **Status:** situação ao fim da Etapa 1.

Legenda: ✅ verificado por teste automatizado · 🔎 verificado por inspeção/documento · ⏳ pendente (etapa indicada).
Os testes citados ficam em `tests/` e rodam com `composer test` (no GitHub, a cada push, pelo CI).

| Código | Categoria | Requisito | Como é verificado | Status |
|---|---|---|---|:-:|
| RNF-01 | Segurança | Senhas de usuários internos armazenadas só com hash forte (`password_hash`, bcrypt) | `SchemaTest::testPasswordIsNeverStoredInPlainText` (o banco recusa texto puro); `UserModelTest::testCreateNormalizesTheEmailAndNeverReturnsTheHash` | ✅ |
| RNF-02 | Segurança | Áreas internas exigem sessão autenticada | `LoginFlowTest::testProtectedRouteRedirectsGuestToLoginWithNext` | ✅ |
| RNF-03 | Segurança | Links públicos de justificativa com token aleatório guardado só como hash, expiração e uso único | modelo: `SchemaTest::testJustificationLinkKeepsOnlyTheTokenHashAndTheBodyNeverHasTheRawToken`; fluxo completo na Etapa 3 | ⏳ Etapa 3 |
| RNF-04 | Segurança | Webhook de e-mail exige o segredo `X-Email-Webhook-Secret` (sem ele, 401) | — | ⏳ Etapa 2 |
| RNF-05 | Privacidade | O responsável só acessa a ocorrência ligada ao seu link | — | ⏳ Etapa 3 |
| RNF-06 | Disponibilidade | Executável localmente (servidor embutido do PHP) e via Docker Compose | local: os testes de aceitação sobem o servidor real (`tests/Acceptance`); Docker na Etapa 2 | ⏳ Etapa 2 (Docker) |
| RNF-07 | Desempenho | Consultas frequentes por agregação e índices, sem varrer a coleção | `FrequentQueriesTest::testStudentContextLookupsUseIndexesWithoutCollectionScans`, `FrequentQueriesTest::testDashboardMatchUsesTheDateStatusIndex`, `FrequentQueriesTest::testDeclaredIndexesServeTheMainQueries` | ✅ |
| RNF-08 | Consistência | No máximo uma frequência por aluno e data (o índice por aluno+data é mais restritivo que por matrícula+data) | `SchemaTest::testAttendanceAllowsOneRecordPerStudentPerDay`, `FrequentQueriesTest::testDuplicateAbsenceIsRejectedAndTheFirstRecordIsKept` | ✅ |
| RNF-09 | Auditabilidade | Operações relevantes registram quando, quem, o quê e de onde (`audit_events`) | login/logout/bloqueio/criação de usuário: `AuthServiceTest::testValidLoginStartsTheSessionAuditsAndRotatesTheCsrfToken`, `AuthServiceTest::testWrongPasswordUnknownEmailAndInactiveUserFailWithTheSameMessage`, `AuthServiceTest::testCreateUserValidatesTheInputAndAuditsTheCreation`; cadastros, ausências e e-mails nas Etapas 2–3 | ✅ parcial |
| RNF-10 | Usabilidade | Interface responsiva com mensagens de validação claras | `LoginFlowTest::testBlankFieldsAreValidationErrors`; layout e estados em [DESIGN_SISTEMA.md](DESIGN_SISTEMA.md) | ✅ / 🔎 |
| RNF-11 | Manutenibilidade | Código em camadas: Controller → Service → Model; consultas MongoDB só em `src/Models` | [ARQUITETURA.md](ARQUITETURA.md) e [DIAGRAMA_DE_CLASSES.md](DIAGRAMA_DE_CLASSES.md) | 🔎 |
| RNF-12 | Portabilidade | Funciona em Windows e Linux | testes no Windows (PHP 8.5) e no CI Linux (PHP 8.3, 8.4 e 8.5) | ✅ |
| RNF-13 | Recuperação | Dados do MongoDB persistem entre reinícios (volume Docker) | — | ⏳ Etapa 2 |
| RNF-14 | Configuração | URI do banco, segredos e opções vêm de variáveis de ambiente/`.env`; nada sensível versionado | `ConfigTest` (leitura do ambiente); [`.env.example`](../.env.example) sem valores reais; `.env` no `.gitignore` | ✅ |
| RNF-15 | Compatibilidade | API com JSON consistente (envelope `data`/`error`) e códigos HTTP corretos | `LoginFlowTest::testUnknownApiPathReturnsJsonError`, `LoginFlowTest::testWrongMethodReturns405WithAllowHeader`; CRUD da API na Etapa 2 | ✅ parcial |
| RNF-16 | Segurança | Toda requisição que altera dados exige token CSRF | `LoginFlowTest::testPostWithoutCsrfTokenIsForbidden`, `LoginFlowTest::testLogoutWithoutCsrfTokenIsForbidden`, `CsrfTest::testTokenFromAnotherSessionIsRejected` | ✅ |
| RNF-17 | Segurança | Cabeçalhos de segurança (CSP só `'self'`, nosniff, frame DENY, referrer) e nenhum script/estilo inline | `LoginFlowTest::testSecurityHeadersArePresentOnEveryKindOfResponse`; verificação de política no CI | ✅ |
| RNF-18 | Segurança | Limite de tentativas de login por e-mail + IP (429 com tempo de espera) | `AuthServiceTest::testAttemptsAreLimitedPerEmailAndIpEvenWithTheRightPassword`, `LoginFlowTest::testLoginAttemptsAreLimitedAndReturn429` | ✅ |
| RNF-19 | Segurança | Sessão endurecida: cookie HttpOnly/SameSite, novo id no login, expiração por inatividade e absoluta | `LoginFlowTest::testValidLoginRedirectsToHomeWithHardenedSessionCookie`, `LoginFlowTest::testSessionIdIsRegeneratedOnLogin`, `LoginFlowTest::testFixedSessionIdFromAttackerIsNotAccepted`, `SessionTest::testIdleTimeoutEndsAnAuthenticatedSession`, `SessionTest::testAbsoluteTimeoutEndsTheSessionEvenWhenActive` | ✅ |
| RNF-20 | Segurança | Entradas não podem injetar operadores NoSQL | `RequestTest::testStringGetterIgnoresArraysSoNoSqlOperatorsNeverGetThrough`, `LoginFlowTest::testNoSqlInjectionThroughArrayFieldsDoesNotAuthenticate`, `SchemaTest::testNoSqlOperatorObjectsCannotBeStoredInTextFields` | ✅ |
| RNF-21 | Segurança | Sem redirecionamento aberto após o login (`next` só aceita caminho interno) | `LoginFlowTest::testOpenRedirectThroughNextIsIgnored`, `LoginFlowTest::testNextParameterIsNotReflectedWhenHostile` | ✅ |
| RNF-22 | Segurança | Arquivos sensíveis e *path traversal* nunca são servidos | `StaticFilesTest::testTraversalAndSensitiveFilesAreNeverServed`, `LoginFlowTest::testTraversalAttemptsOnRoutesAreHarmless` | ✅ |
| RNF-23 | Controle de acesso | Permissões por perfil numa matriz única, aplicadas em cada rota | `PermissionsTest` (matriz completa), `RouterTest::testPermissionOptionMustBeKnownAndOnlyForAuthRoutes`, `AuthServiceTest::testDeactivatedUserLosesTheSessionOnTheNextRequest`; teste rota a rota quando as telas existirem (Etapa 3) | ✅ parcial |
| RNF-24 | Integridade | O banco recusa documentos inválidos (validators `$jsonSchema` em todas as coleções) | `SchemaTest::testValidDocumentIsAccepted`, `SchemaTest::testInvalidDocumentIsRejected`, `SchemaTest::testRequiredFieldIsRejectedWhenMissing` | ✅ |
| RNF-25 | Acessibilidade | Contraste WCAG AA, foco visível, rótulos e navegação por teclado | contraste calculado e checklist em [DESIGN_SISTEMA.md](DESIGN_SISTEMA.md) | 🔎 |
