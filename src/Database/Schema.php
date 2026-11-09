<?php
declare(strict_types=1);

namespace Saa\Database;

use MongoDB\Database;
use MongoDB\Driver\Exception\CommandException;
use MongoDB\Model\BSONArray;
use MongoDB\Model\BSONDocument;
use MongoDB\Model\IndexInfo;
use RuntimeException;
use Saa\Domain\AttendanceStatus;
use Saa\Domain\EmailStatus;
use Saa\Domain\EndReason;
use Saa\Domain\JustificationReason;
use Saa\Domain\JustificationStatus;
use Saa\Domain\NotificationEventType;
use Saa\Domain\NotificationStatus;
use Saa\Domain\Profile;
use Saa\Domain\Relationship;
use Saa\Domain\Shift;
use Throwable;

/**
 * Modelo físico do MongoDB: validators $jsonSchema (strict + error) e índices das 12 coleções do SAA.
 * A explicação completa, com exemplos, está em docs/MODELAGEM_MONGODB.md.
 *
 * Contrato público (não muda):
 *   Schema::initialize(Database $database): void  — idempotente: cria/atualiza coleções, validators e índices.
 *   Schema::definitions(): array                  — nome da coleção => ['validator' => ..., 'indexes' => [...]].
 *
 * Cada 'validator' é o documento completo da opção "validator" do MongoDB: sempre traz '$jsonSchema' (tipos, campos
 * obrigatórios, enums vindos de Saa\Domain, limites de tamanho) e, quando há regra entre campos, também '$expr'.
 * Cada item de 'indexes' pode ser entregue direto a Collection::createIndexes() (chaves 'name', 'key' e opções).
 *
 * Regras que o validator não consegue expressar (e ficam nos Models/Services): existência das referências (não há
 * chave estrangeira no MongoDB), coerência de turma/período entre matrícula e frequência e a normalização de textos.
 */
final class Schema
{
    public const VALIDATION_LEVEL = 'strict';
    public const VALIDATION_ACTION = 'error';

    /** Quantos responsáveis um aluno pode ter (array embutido e limitado). */
    public const MAX_GUARDIANS_PER_STUDENT = 3;
    /** Tentativas de envio de um e-mail (RF de comunicação: no máximo 3). */
    public const MAX_NOTIFICATION_ATTEMPTS = 3;
    /** Teto de eventos do provedor por e-mail (5 tipos x 3 tentativas = 15, com folga para repetições). */
    public const MAX_NOTIFICATION_EVENTS = 30;
    /** Teto de mudanças de status da justificativa (na prática PENDING -> RESPONDED). */
    public const MAX_STATUS_HISTORY = 10;
    /** login_attempts: as tentativas são descartadas 1 hora depois (a janela de bloqueio é bem menor). */
    public const LOGIN_ATTEMPTS_TTL_SECONDS = 3600;
    /** password_resets: os pedidos são descartados 30 dias depois de criados (o token vale 1 hora). */
    public const PASSWORD_RESETS_TTL_SECONDS = 2592000;

    /** e-mail como digitado: uma arroba, sem espaços. */
    private const EMAIL_PATTERN = '^[^@\s]+@[^@\s]+$';
    /** e-mail normalizado: igual ao anterior, porém sem letras maiúsculas. */
    private const EMAIL_NORMALIZED_PATTERN = '^[^@\sA-Z]+@[^@\sA-Z]+$';
    /** SHA-256 em hexadecimal (token de link e de redefinição de senha: só o hash é gravado). */
    private const SHA256_HEX_PATTERN = '^[a-f0-9]{64}$';
    /** saída de password_hash(): começa com $id$ (bcrypt, argon2...) e não tem espaços; texto puro não passa. */
    private const PASSWORD_HASH_PATTERN = '^\$[A-Za-z0-9]+\$[^\s]+$';
    /** o corpo gravado do e-mail não pode conter o link da justificativa com o token em claro. */
    private const RAW_JUSTIFICATION_LINK_PATTERN = '/justify/[A-Za-z0-9_-]{20,}';

    /** @var array<string, array{validator: array<string, mixed>, indexes: list<array<string, mixed>>}>|null */
    private static ?array $definitions = null;

    /**
     * Cria ou atualiza as 12 coleções, os validators e os índices. Idempotente: pode rodar a cada inicialização.
     * Nunca apaga documentos. Para obter o relatório do que foi feito use Schema::apply().
     */
    public static function initialize(Database $database): void
    {
        self::apply($database);
    }

    /**
     * Como initialize(), mas devolve o relatório por coleção (usado por bin/init_db.php).
     *
     * @return list<array{
     *     collection: string,
     *     action: 'created'|'updated',
     *     indexes_total: int,
     *     indexes_created: list<string>,
     *     indexes_rebuilt: list<string>,
     *     indexes_extra: list<string>
     * }>
     * @throws RuntimeException se o MongoDB recusar a coleção, o validator ou algum índice (ex.: dados duplicados
     *                          impedem um índice único); a exceção original fica em getPrevious()
     */
    public static function apply(Database $database): array
    {
        $definitions = self::definitions();
        $present = [];
        foreach ($database->listCollectionNames(['filter' => ['name' => ['$in' => array_keys($definitions)]]]) as $name) {
            $present[(string)$name] = true;
        }

        $report = [];
        foreach ($definitions as $name => $definition) {
            $options = [
                'validator' => $definition['validator'],
                'validationLevel' => self::VALIDATION_LEVEL,
                'validationAction' => self::VALIDATION_ACTION,
            ];
            $created = false;
            try {
                if (!isset($present[$name])) {
                    try {
                        $database->createCollection($name, $options);
                        $created = true;
                    } catch (CommandException $e) {
                        if ($e->getCode() !== 48) { // 48 = NamespaceExists: outro processo criou no mesmo instante
                            throw $e;
                        }
                    }
                }
                if (!$created) {
                    $database->modifyCollection($name, $options);
                }
            } catch (Throwable $e) {
                throw new RuntimeException("Falha ao criar/atualizar a coleção '{$name}': " . $e->getMessage(), 0, $e);
            }

            $report[] = ['collection' => $name, 'action' => $created ? 'created' : 'updated']
                + self::applyIndexes($database, $name, $definition['indexes'], $created);
        }
        return $report;
    }

    /**
     * @return array<string, array{validator: array<string, mixed>, indexes: list<array<string, mixed>>}>
     */
    public static function definitions(): array
    {
        return self::$definitions ??= [
            Collections::USERS => self::users(),
            Collections::STUDENTS => self::students(),
            Collections::GUARDIANS => self::guardians(),
            Collections::ACADEMIC_PERIODS => self::academicPeriods(),
            Collections::CLASSES => self::classes(),
            Collections::ENROLLMENTS => self::enrollments(),
            Collections::ATTENDANCE_RECORDS => self::attendanceRecords(),
            Collections::EMAIL_NOTIFICATIONS => self::emailNotifications(),
            Collections::AUDIT_EVENTS => self::auditEvents(),
            Collections::PASSWORD_RESETS => self::passwordResets(),
            Collections::LOGIN_ATTEMPTS => self::loginAttempts(),
            Collections::MESSAGE_TEMPLATES => self::messageTemplates(),
        ];
    }

    // ------------------------------------------------------------------------------------------------ coleções

    /** @return array{validator: array<string, mixed>, indexes: list<array<string, mixed>>} */
    private static function users(): array
    {
        $c = Collections::USERS;
        return [
            'validator' => self::validator(
                self::root('Usuário interno (login por e-mail e senha)', [
                    'name', 'email', 'email_normalized', 'password_hash', 'profile', 'active',
                    'password_changed_at', 'created_at', 'updated_at',
                ], [
                    '_id' => self::objectId('Identificador do usuário'),
                    'name' => self::text('Nome completo (3 a 120 caracteres)', 3, 120),
                    'email' => self::text('E-mail como foi informado', 3, 254, self::EMAIL_PATTERN),
                    'email_normalized' => self::text('E-mail de login em minúsculas (único)', 3, 254, self::EMAIL_NORMALIZED_PATTERN),
                    'password_hash' => self::text('Hash da senha (password_hash). Nunca a senha em texto puro', 1, 255, self::PASSWORD_HASH_PATTERN),
                    'profile' => self::choice('Perfil com conta: ADMIN, COORDINATION, AOE ou SECRETARY', Profile::loginValues()),
                    'active' => self::flag('Se pode entrar no sistema'),
                    'last_login_at' => self::date('Último acesso (null até o primeiro login)', true),
                    'password_changed_at' => self::date('Última troca de senha'),
                    'created_at' => self::date('Criação do cadastro'),
                    'updated_at' => self::date('Última alteração'),
                    'created_by' => self::objectId('Usuário que criou o cadastro (null no primeiro administrador)', true),
                ], [
                    // RNF-01: nenhum campo de senha em texto puro pode ser gravado.
                    'not' => ['anyOf' => [
                        ['required' => ['password']],
                        ['required' => ['senha']],
                        ['required' => ['plain_password']],
                    ]],
                ])
            ),
            'indexes' => [
                self::index("uq_{$c}_email_normalized", ['email_normalized' => 1], ['unique' => true]),
                self::index("ix_{$c}_profile_active", ['profile' => 1, 'active' => 1]),
            ],
        ];
    }

    /** @return array{validator: array<string, mixed>, indexes: list<array<string, mixed>>} */
    private static function students(): array
    {
        $c = Collections::STUDENTS;
        $guardianLink = self::struct(
            'Vínculo com um responsável (o contato fica em guardians)',
            ['guardian_id', 'relationship', 'is_primary', 'receives_absence_notifications'],
            [
                'guardian_id' => self::objectId('Referência a guardians._id'),
                'relationship' => self::choice('Parentesco: PAI, MAE ou RESPONSAVEL', Relationship::values()),
                'is_primary' => self::flag('Responsável principal (no máximo um por aluno)'),
                'receives_absence_notifications' => self::flag('Recebe o e-mail de falta'),
            ]
        );
        return [
            'validator' => self::validator(
                self::root('Aluno e seus vínculos com responsáveis', [
                    'name', 'student_code', 'birth_date', 'guardians', 'active', 'created_at', 'updated_at',
                ], [
                    '_id' => self::objectId('Identificador do aluno'),
                    'name' => self::text('Nome completo', 2, 120),
                    'student_code' => self::text('Código do aluno na escola (RA), único', 1, 30),
                    'birth_date' => self::date('Data de nascimento (meia-noite UTC)'),
                    'guardians' => self::listOf(
                        'Responsáveis do aluno: array embutido, no máximo ' . self::MAX_GUARDIANS_PER_STUDENT,
                        $guardianLink,
                        null,
                        self::MAX_GUARDIANS_PER_STUDENT
                    ),
                    'active' => self::flag('Cadastro ativo (arquivar = false; nada é apagado)'),
                    'created_at' => self::date('Criação do cadastro'),
                    'updated_at' => self::date('Última alteração'),
                ]),
                [
                    self::calendarDay('$birth_date'),
                    // no máximo um responsável principal
                    ['$cond' => [['$isArray' => '$guardians'], ['$lte' => [['$size' => ['$filter' => [
                        'input' => '$guardians', 'as' => 'g', 'cond' => ['$eq' => ['$$g.is_primary', true]],
                    ]]], 1]], false]],
                    // o mesmo responsável não pode aparecer duas vezes no mesmo aluno
                    ['$cond' => [['$isArray' => '$guardians'], ['$eq' => [
                        ['$size' => '$guardians'],
                        ['$size' => ['$setUnion' => ['$guardians.guardian_id']]],
                    ]], false]],
                ]
            ),
            'indexes' => [
                self::index("uq_{$c}_student_code", ['student_code' => 1], ['unique' => true]),
                self::index("ix_{$c}_active_name", ['active' => 1, 'name' => 1]),
                self::index("ix_{$c}_guardian", ['guardians.guardian_id' => 1]),
            ],
        ];
    }

    /** @return array{validator: array<string, mixed>, indexes: list<array<string, mixed>>} */
    private static function guardians(): array
    {
        $c = Collections::GUARDIANS;
        return [
            'validator' => self::validator(
                self::root('Contato do responsável (referenciado por students.guardians)', [
                    'name', 'email', 'email_normalized', 'email_status', 'active', 'created_at', 'updated_at',
                ], [
                    '_id' => self::objectId('Identificador do responsável'),
                    'name' => self::text('Nome completo', 2, 120),
                    'email' => self::text('E-mail como foi informado', 3, 254, self::EMAIL_PATTERN),
                    'email_normalized' => self::text('E-mail em minúsculas (único)', 3, 254, self::EMAIL_NORMALIZED_PATTERN),
                    'email_status' => self::choice('VALID, UNVERIFIED ou BOUNCED (só VALID recebe comunicados)', EmailStatus::values()),
                    'phone' => self::text('Telefone (opcional)', 0, 30, null, true),
                    'active' => self::flag('Cadastro ativo (arquivar = false)'),
                    'created_at' => self::date('Criação do cadastro'),
                    'updated_at' => self::date('Última alteração'),
                ])
            ),
            'indexes' => [
                self::index("uq_{$c}_email_normalized", ['email_normalized' => 1], ['unique' => true]),
                self::index("ix_{$c}_active_name", ['active' => 1, 'name' => 1]),
            ],
        ];
    }

    /** @return array{validator: array<string, mixed>, indexes: list<array<string, mixed>>} */
    private static function academicPeriods(): array
    {
        $c = Collections::ACADEMIC_PERIODS;
        return [
            'validator' => self::validator(
                self::root('Período letivo e sua regra de frequência (attendance_policy embutida)', [
                    'name', 'start_date', 'end_date', 'attendance_policy', 'active', 'created_at', 'updated_at',
                ], [
                    '_id' => self::objectId('Identificador do período'),
                    'name' => self::text('Nome do período, por exemplo 2026 (único)', 1, 50),
                    'start_date' => self::date('Início das aulas (meia-noite UTC)'),
                    'end_date' => self::date('Fim das aulas (meia-noite UTC), depois do início'),
                    'attendance_policy' => self::struct(
                        'Regra de frequência do período',
                        ['min_attendance_percent', 'alert_below_percent'],
                        [
                            'min_attendance_percent' => self::percent('Frequência mínima exigida (%)'),
                            'alert_below_percent' => self::percent('Abaixo deste valor o aluno aparece em alerta (%), não menor que o mínimo'),
                        ]
                    ),
                    'active' => self::flag('Período vigente (no máximo um período ativo)'),
                    'created_at' => self::date('Criação do cadastro'),
                    'updated_at' => self::date('Última alteração'),
                ]),
                [
                    self::calendarDay('$start_date'),
                    self::calendarDay('$end_date'),
                    ['$lt' => ['$start_date', '$end_date']],
                    ['$gte' => ['$attendance_policy.alert_below_percent', '$attendance_policy.min_attendance_percent']],
                ]
            ),
            'indexes' => [
                self::index("uq_{$c}_name", ['name' => 1], ['unique' => true]),
                // no máximo UM documento com active=true (documentos inativos não entram no índice)
                self::index("uq_{$c}_single_active", ['active' => 1], [
                    'unique' => true,
                    'partialFilterExpression' => ['active' => true],
                ]),
            ],
        ];
    }

    /** @return array{validator: array<string, mixed>, indexes: list<array<string, mixed>>} */
    private static function classes(): array
    {
        $c = Collections::CLASSES;
        return [
            'validator' => self::validator(
                self::root('Turma de um período letivo', [
                    'academic_period_id', 'code', 'name', 'grade', 'shift', 'active', 'created_at', 'updated_at',
                ], [
                    '_id' => self::objectId('Identificador da turma'),
                    'academic_period_id' => self::objectId('Referência a academic_periods._id'),
                    'code' => self::text('Código curto, por exemplo 6A (único dentro do período)', 1, 20),
                    'name' => self::text('Nome exibido, por exemplo 6º Ano A', 1, 100),
                    'grade' => self::text('Série ou ano, por exemplo 6º ano', 1, 50),
                    'shift' => self::choice('MORNING, AFTERNOON, NIGHT ou FULL_TIME', Shift::values()),
                    'active' => self::flag('Turma em funcionamento'),
                    'created_at' => self::date('Criação do cadastro'),
                    'updated_at' => self::date('Última alteração'),
                ])
            ),
            'indexes' => [
                self::index("uq_{$c}_period_code", ['academic_period_id' => 1, 'code' => 1], ['unique' => true]),
                self::index("ix_{$c}_period_shift", ['academic_period_id' => 1, 'shift' => 1]),
            ],
        ];
    }

    /** @return array{validator: array<string, mixed>, indexes: list<array<string, mixed>>} */
    private static function enrollments(): array
    {
        $c = Collections::ENROLLMENTS;
        return [
            'validator' => self::validator(
                self::root('Matrícula: vínculo do aluno com uma turma ao longo do tempo', [
                    'student_id', 'class_id', 'academic_period_id', 'start_date', 'active',
                ], [
                    '_id' => self::objectId('Identificador da matrícula'),
                    'student_id' => self::objectId('Referência a students._id'),
                    'class_id' => self::objectId('Referência a classes._id'),
                    'academic_period_id' => self::objectId('Referência a academic_periods._id'),
                    'start_date' => self::date('Início do vínculo (meia-noite UTC)'),
                    'end_date' => self::date('Fim do vínculo (null enquanto vigente)', true),
                    'end_reason' => self::choice('CLASS_CHANGE, TRANSFER ou PERIOD_END (null enquanto vigente)', EndReason::values(), true),
                    'active' => self::flag('Matrícula vigente (uma por aluno)'),
                    'created_at' => self::date('Criação do registro (opcional)'),
                    'updated_at' => self::date('Última alteração (opcional)'),
                ], [
                    // vigente: sem fim e sem motivo; encerrada: com fim e com motivo
                    'anyOf' => [
                        ['properties' => [
                            'active' => ['enum' => [true]],
                            'end_date' => ['bsonType' => 'null'],
                            'end_reason' => ['bsonType' => 'null'],
                        ]],
                        ['required' => ['end_date', 'end_reason'], 'properties' => [
                            'active' => ['enum' => [false]],
                            'end_date' => ['bsonType' => 'date'],
                            'end_reason' => ['bsonType' => 'string'],
                        ]],
                    ],
                ]),
                [
                    self::calendarDay('$start_date'),
                    self::calendarDay('$end_date', true),
                    ['$or' => [
                        ['$eq' => [['$ifNull' => ['$end_date', null]], null]],
                        ['$gte' => ['$end_date', '$start_date']],
                    ]],
                ]
            ),
            'indexes' => [
                // uma matrícula ativa por aluno; matrículas encerradas ficam fora do índice (histórico livre)
                self::index("uq_{$c}_student_active", ['student_id' => 1], [
                    'unique' => true,
                    'partialFilterExpression' => ['active' => true],
                ]),
                self::index("ix_{$c}_class_active", ['class_id' => 1, 'active' => 1]),
                self::index("ix_{$c}_student_start", ['student_id' => 1, 'start_date' => -1]),
            ],
        ];
    }

    /** @return array{validator: array<string, mixed>, indexes: list<array<string, mixed>>} */
    private static function attendanceRecords(): array
    {
        $reasons = JustificationReason::values();
        $withoutOther = array_values(array_diff($reasons, [JustificationReason::OTHER->value]));

        $historyItem = self::struct(
            'Uma mudança de status da justificativa',
            ['status', 'at'],
            [
                'status' => self::choice('Status assumido: PENDING ou RESPONDED', JustificationStatus::values()),
                'at' => self::date('Quando mudou'),
                'by_user_id' => self::objectId('Usuário interno que causou a mudança (ou null)', true),
                'by_guardian_id' => self::objectId('Responsável que respondeu pelo link (ou null)', true),
            ]
        );
        $justification = self::struct(
            'Justificativa do responsável (embutida; null quando não há)',
            ['status', 'status_history'],
            [
                'status' => self::choice('PENDING (aguardando) ou RESPONDED', JustificationStatus::values()),
                'guardian_id' => self::objectId('Quem respondeu: referência a guardians._id', true),
                'reason_code' => self::choice('ILLNESS, MEDICAL, FAMILY, TRANSPORT ou OTHER', $reasons, true),
                'explanation' => self::text('Explicação por escrito (obrigatória no motivo OTHER)', 0, 2000, null, true),
                'submitted_at' => self::date('Data e hora da resposta', true),
                'notification_id' => self::objectId('E-mail cujo link foi usado: referência a email_notifications._id', true),
                'status_history' => self::listOf('Histórico de status (embutido e limitado)', $historyItem, 1, self::MAX_STATUS_HISTORY),
            ],
            true,
            [
                'anyOf' => [
                    // PENDING: ainda sem resposta
                    ['properties' => [
                        'status' => ['enum' => [JustificationStatus::PENDING->value]],
                        'guardian_id' => ['bsonType' => 'null'],
                        'reason_code' => ['bsonType' => 'null'],
                        'explanation' => ['bsonType' => 'null'],
                        'submitted_at' => ['bsonType' => 'null'],
                    ]],
                    // RESPONDED: quem, por quê e quando; o motivo OTHER exige explicação
                    ['required' => ['guardian_id', 'reason_code', 'submitted_at'], 'properties' => [
                        'status' => ['enum' => [JustificationStatus::RESPONDED->value]],
                        'guardian_id' => ['bsonType' => 'objectId'],
                        'reason_code' => ['bsonType' => 'string'],
                        'submitted_at' => ['bsonType' => 'date'],
                    ], 'anyOf' => [
                        ['properties' => ['reason_code' => ['enum' => $withoutOther]]],
                        ['required' => ['explanation'], 'properties' => [
                            'explanation' => ['bsonType' => 'string', 'minLength' => 3],
                        ]],
                    ]],
                ],
            ]
        );
        return [
            'validator' => self::validator(
                self::root('Frequência diária: um registro por aluno por dia', [
                    'enrollment_id', 'student_id', 'class_id', 'class_name', 'academic_period_id',
                    'date', 'status', 'registered_by', 'created_at',
                ], [
                    '_id' => self::objectId('Identificador do registro'),
                    'enrollment_id' => self::objectId('Matrícula vigente no dia: referência a enrollments._id'),
                    'student_id' => self::objectId('Referência a students._id'),
                    'class_id' => self::objectId('Turma do aluno NAQUELE dia: referência a classes._id'),
                    'class_name' => self::text('Cópia (snapshot) do nome da turma naquele dia', 1, 100),
                    'academic_period_id' => self::objectId('Referência a academic_periods._id'),
                    'date' => self::date('Dia da frequência (meia-noite UTC)'),
                    'status' => self::choice('PRESENT, ABSENT ou EXCUSED (falta justificada e aceita)', AttendanceStatus::values()),
                    'registered_by' => self::objectId('Quem lançou: referência a users._id'),
                    'details' => self::struct('Informações complementares da falta (embutido)', ['source'], [
                        'source' => self::text('Origem da informação, por exemplo INTERNAL_MESSAGE', 1, 50),
                        'note' => self::text('Observação da AOE', 0, 500, null, true),
                    ]),
                    'justification' => $justification,
                    'created_at' => self::date('Data do lançamento'),
                    'updated_at' => self::date('Última alteração (opcional)'),
                ], [
                    // presença não tem justificativa
                    'anyOf' => [
                        ['properties' => ['status' => ['enum' => [AttendanceStatus::ABSENT->value, AttendanceStatus::EXCUSED->value]]]],
                        ['properties' => ['status' => ['enum' => [AttendanceStatus::PRESENT->value]], 'justification' => ['bsonType' => 'null']]],
                    ],
                ]),
                [self::calendarDay('$date')]
            ),
            'indexes' => [
                // um registro por aluno por dia; também atende "faltas do aluno, da mais recente para a mais antiga"
                self::index('uq_attendance_student_date', ['student_id' => 1, 'date' => 1], ['unique' => true]),
                self::index('ix_attendance_date_status', ['date' => 1, 'status' => 1]),
                self::index('ix_attendance_class_date', ['class_id' => 1, 'date' => 1]),
                self::index('ix_attendance_justification_status_date', ['justification.status' => 1, 'date' => -1]),
                self::index('ix_attendance_enrollment', ['enrollment_id' => 1]),
            ],
        ];
    }

    /** @return array{validator: array<string, mixed>, indexes: list<array<string, mixed>>} */
    private static function emailNotifications(): array
    {
        $event = self::struct(
            'Evento de envio, entrega, abertura, falha ou acesso ao link',
            ['type', 'occurred_at'],
            [
                'type' => self::choice('SENT, DELIVERED, OPENED, FAILED ou LINK_ACCESSED', NotificationEventType::values()),
                'occurred_at' => self::date('Quando o evento ocorreu'),
                'detail' => self::text('Detalhe, por exemplo o motivo da falha', 0, 500, null, true),
            ]
        );
        $link = self::struct(
            'Link seguro de justificativa: só o hash do token é gravado',
            ['token_hash', 'expires_at'],
            [
                'token_hash' => self::text('SHA-256 do token em hexadecimal (64 caracteres, único)', 64, 64, self::SHA256_HEX_PATTERN),
                'expires_at' => self::date('Validade do link'),
                'used_at' => self::date('Quando o link foi usado (uso único); null enquanto não usado', true),
            ]
        );
        return [
            'validator' => self::validator(
                self::root('E-mail de ausência enviado (ou na fila) para um responsável', [
                    'attendance_id', 'student_id', 'guardian_id', 'recipient_email', 'subject', 'body',
                    'status', 'attempts', 'justification_link', 'events', 'created_at',
                ], [
                    '_id' => self::objectId('Identificador da notificação'),
                    'attendance_id' => self::objectId('Referência a attendance_records._id'),
                    'student_id' => self::objectId('Referência a students._id'),
                    'guardian_id' => self::objectId('Referência a guardians._id'),
                    'recipient_email' => self::text('Cópia (snapshot) do e-mail usado no envio', 3, 254, self::EMAIL_PATTERN),
                    'subject' => self::text('Assunto enviado', 1, 255),
                    'body' => self::text('Texto enviado (snapshot), sem o token em claro', 1, 10000, null, false, [
                        'not' => ['pattern' => self::RAW_JUSTIFICATION_LINK_PATTERN],
                    ]),
                    'status' => self::choice('Último estado: PENDING, SENT, DELIVERED, OPENED ou FAILED', NotificationStatus::values()),
                    'attempts' => self::whole('Tentativas de envio (0 a 3)', 0, self::MAX_NOTIFICATION_ATTEMPTS),
                    'provider_message_id' => self::text('Identificador da mensagem no provedor de e-mail', 1, 255, null, true),
                    'justification_link' => $link,
                    'events' => self::listOf('Histórico do provedor (embutido e limitado)', $event, null, self::MAX_NOTIFICATION_EVENTS),
                    'resend_of' => self::objectId('Notificação original, quando esta é um reenvio', true),
                    'locked_at' => self::date('Reserva do processador da fila (findOneAndUpdate)', true),
                    'next_attempt_at' => self::date('Quando tentar novamente', true),
                    'last_error' => self::text('Última mensagem de erro de envio', 0, 1000, null, true),
                    'created_by' => self::objectId('Quem criou: referência a users._id', true),
                    'created_at' => self::date('Criação da notificação'),
                    'updated_at' => self::date('Última alteração (opcional)'),
                ])
            ),
            'indexes' => [
                self::index('uq_notifications_token_hash', ['justification_link.token_hash' => 1], ['unique' => true]),
                self::index('ix_notifications_attendance', ['attendance_id' => 1]),
                self::index('ix_notifications_guardian_created', ['guardian_id' => 1, 'created_at' => -1]),
                self::index('ix_notifications_status_next_attempt', ['status' => 1, 'next_attempt_at' => 1]),
                // o webhook do provedor localiza a notificação pelo id da mensagem; só entram os já aceitos pelo provedor
                // ($gt: '' pega apenas textos não vazios; com $type o planejador não reconhece a consulta por igualdade)
                self::index('ix_notifications_provider_message', ['provider_message_id' => 1], [
                    'partialFilterExpression' => ['provider_message_id' => ['$gt' => '']],
                ]),
            ],
        ];
    }

    /** @return array{validator: array<string, mixed>, indexes: list<array<string, mixed>>} */
    private static function auditEvents(): array
    {
        return [
            'validator' => self::validator(
                self::root('Trilha de auditoria: quem fez o quê, quando e sobre qual registro', [
                    'at', 'action', 'entity_type',
                ], [
                    '_id' => self::objectId('Identificador do evento'),
                    'at' => self::date('Quando aconteceu'),
                    'actor_user_id' => self::objectId('Usuário autor: referência a users._id (null se não houver)', true),
                    'actor_label' => self::text('Rótulo do autor (nome, e-mail ou Sistema)', 1, 150, null, true),
                    'action' => self::text('Ação, por exemplo auth.login ou attendance.create', 1, 100),
                    'entity_type' => self::text('Tipo da entidade afetada, por exemplo students', 1, 50),
                    'entity_id' => [
                        'bsonType' => ['objectId', 'string', 'null'],
                        'maxLength' => 100,
                        'description' => 'Identificador da entidade (ObjectId; texto quando não há documento)',
                    ],
                    // [] vira array BSON no PHP; um array vazio também é aceito para não derrubar a gravação da auditoria
                    'details' => [
                        'anyOf' => [['bsonType' => 'object'], ['bsonType' => 'array', 'maxItems' => 0]],
                        'description' => 'Detalhes da ação (objeto)',
                    ],
                    'ip' => self::text('Endereço IP de origem', 1, 64, null, true),
                ])
            ),
            'indexes' => [
                self::index('ix_audit_at', ['at' => -1]),
                self::index('ix_audit_entity_at', ['entity_type' => 1, 'entity_id' => 1, 'at' => -1]),
                self::index('ix_audit_actor_at', ['actor_user_id' => 1, 'at' => -1]),
            ],
        ];
    }

    /** @return array{validator: array<string, mixed>, indexes: list<array<string, mixed>>} */
    private static function passwordResets(): array
    {
        return [
            'validator' => self::validator(
                self::root('Pedido de redefinição de senha (só o hash do token é gravado)', [
                    'user_id', 'token_hash', 'expires_at', 'created_at',
                ], [
                    '_id' => self::objectId('Identificador do pedido'),
                    'user_id' => self::objectId('Referência a users._id'),
                    'token_hash' => self::text('SHA-256 do token em hexadecimal (único)', 64, 64, self::SHA256_HEX_PATTERN),
                    'expires_at' => self::date('Validade do token (1 hora)'),
                    'used_at' => self::date('Quando foi usado (uso único); null enquanto não usado', true),
                    'created_at' => self::date('Criação do pedido (base do TTL de 30 dias)'),
                    'ip' => self::text('Endereço IP que pediu', 1, 64, null, true),
                ])
            ),
            'indexes' => [
                self::index('uq_password_resets_token_hash', ['token_hash' => 1], ['unique' => true]),
                self::index('ttl_password_resets_created_at', ['created_at' => 1], [
                    'expireAfterSeconds' => self::PASSWORD_RESETS_TTL_SECONDS,
                ]),
                self::index('ix_password_resets_user_created', ['user_id' => 1, 'created_at' => -1]),
            ],
        ];
    }

    /** @return array{validator: array<string, mixed>, indexes: list<array<string, mixed>>} */
    private static function loginAttempts(): array
    {
        return [
            'validator' => self::validator(
                self::root('Tentativa de login para o limite por janela de tempo', ['key', 'at'], [
                    '_id' => self::objectId('Identificador da tentativa'),
                    'key' => self::text('Chave do limite (por exemplo hash de e-mail + IP)', 1, 255),
                    'at' => self::date('Quando a tentativa ocorreu (base do TTL de 1 hora)'),
                ])
            ),
            'indexes' => [
                self::index('ttl_login_attempts_at', ['at' => 1], ['expireAfterSeconds' => self::LOGIN_ATTEMPTS_TTL_SECONDS]),
                self::index('ix_login_attempts_key_at', ['key' => 1, 'at' => 1]),
            ],
        ];
    }

    /** @return array{validator: array<string, mixed>, indexes: list<array<string, mixed>>} */
    private static function messageTemplates(): array
    {
        return [
            'validator' => self::validator(
                self::root('Texto predefinido de e-mail, com variáveis {{nome}}', [
                    'code', 'subject', 'body', 'variables', 'active', 'updated_at',
                ], [
                    '_id' => self::objectId('Identificador do modelo'),
                    'code' => self::text('Código do modelo (único), por exemplo ABSENCE_NOTICE', 1, 60),
                    'subject' => self::text('Assunto do e-mail', 1, 255),
                    'body' => self::text('Corpo do e-mail, com variáveis {{nome}}', 1, 10000),
                    'variables' => self::listOf('Variáveis aceitas no texto', self::text('Nome da variável', 1, 50), null, 30, true),
                    'active' => self::flag('Modelo em uso'),
                    'updated_at' => self::date('Última alteração'),
                ])
            ),
            'indexes' => [
                self::index('uq_message_templates_code', ['code' => 1], ['unique' => true]),
            ],
        ];
    }

    // ------------------------------------------------------------------------------------------------ índices

    /**
     * Cria os índices que faltam; recria os que existem com opções diferentes; nunca remove índices desconhecidos.
     *
     * @param list<array<string, mixed>> $desired
     * @return array{indexes_total: int, indexes_created: list<string>, indexes_rebuilt: list<string>, indexes_extra: list<string>}
     */
    private static function applyIndexes(Database $database, string $collectionName, array $desired, bool $isNew): array
    {
        $collection = $database->selectCollection($collectionName);
        $current = [];
        if (!$isNew) {
            try {
                foreach ($collection->listIndexes() as $info) {
                    $current[$info->getName()] = $info;
                }
            } catch (Throwable $e) {
                throw new RuntimeException("Falha ao listar os índices de '{$collectionName}': " . $e->getMessage(), 0, $e);
            }
        }

        $desiredNames = array_column($desired, 'name');
        $toCreate = [];
        $created = [];
        $rebuilt = [];
        foreach ($desired as $index) {
            $name = (string)$index['name'];
            try {
                if (isset($current[$name])) {
                    if (self::sameIndex($current[$name], $index)) {
                        continue;
                    }
                    $collection->dropIndex($name);
                    unset($current[$name]);
                    $rebuilt[] = $name;
                } else {
                    // Mesma chave sob outro nome (por exemplo índice criado à mão ou por versão anterior): o MongoDB
                    // recusa duas definições equivalentes, então o antigo é trocado pelo novo.
                    foreach ($current as $otherName => $other) {
                        if ($otherName !== $name && $otherName !== '_id_' && !in_array($otherName, $desiredNames, true)
                            && self::keySignature($other->getKey()) === self::keySignature($index['key'])) {
                            $collection->dropIndex($otherName);
                            unset($current[$otherName]);
                        }
                    }
                    $created[] = $name;
                }
            } catch (Throwable $e) {
                throw new RuntimeException("Falha ao preparar o índice '{$name}' de '{$collectionName}': " . $e->getMessage(), 0, $e);
            }
            $toCreate[] = $index;
        }

        if ($toCreate !== []) {
            try {
                $collection->createIndexes($toCreate);
            } catch (Throwable $e) {
                throw new RuntimeException(
                    "Falha ao criar índice(s) de '{$collectionName}' (" . implode(', ', array_column($toCreate, 'name'))
                    . '): ' . $e->getMessage()
                    . ' Se a causa for chave duplicada, corrija os dados repetidos e rode de novo.',
                    0,
                    $e
                );
            }
        }

        $extra = array_values(array_diff(array_keys($current), $desiredNames, ['_id_']));
        sort($extra);
        return [
            'indexes_total' => count($desired),
            'indexes_created' => $created,
            'indexes_rebuilt' => $rebuilt,
            'indexes_extra' => $extra,
        ];
    }

    /** @param array<string, mixed> $desired */
    private static function sameIndex(IndexInfo $existing, array $desired): bool
    {
        if (self::keySignature($existing->getKey()) !== self::keySignature($desired['key'])) {
            return false;
        }
        foreach (['unique', 'sparse'] as $flag) {
            if ((bool)($existing[$flag] ?? false) !== (bool)($desired[$flag] ?? false)) {
                return false;
            }
        }
        foreach (['partialFilterExpression', 'expireAfterSeconds'] as $option) {
            if (self::canonical($existing[$option] ?? null) !== self::canonical($desired[$option] ?? null)) {
                return false;
            }
        }
        return true;
    }

    /** "campo:direção" na ordem declarada (a ordem das chaves importa em índice composto). */
    private static function keySignature(mixed $key): string
    {
        $key = self::plain($key);
        $parts = [];
        foreach (is_array($key) ? $key : [] as $field => $direction) {
            $parts[] = $field . ':' . (is_numeric($direction) ? (string)(float)$direction : (string)$direction);
        }
        return implode(',', $parts);
    }

    private static function canonical(mixed $value): string
    {
        return (string)json_encode(self::sortKeys(self::plain($value)));
    }

    /** BSONDocument/BSONArray -> arrays; números -> float (1, 1.0 e Int64 comparam igual). */
    private static function plain(mixed $value): mixed
    {
        if ($value instanceof BSONDocument || $value instanceof BSONArray) {
            $value = $value->getArrayCopy();
        }
        if (is_array($value)) {
            return array_map(self::plain(...), $value);
        }
        return is_int($value) ? (float)$value : $value;
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $value = array_map(self::sortKeys(...), $value);
        if (!array_is_list($value)) {
            ksort($value);
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $key
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function index(string $name, array $key, array $options = []): array
    {
        return ['name' => $name, 'key' => $key] + $options;
    }

    // ------------------------------------------------------------------------------------------------ validator

    /**
     * @param array<string, mixed> $jsonSchema
     * @param list<array<string, mixed>> $rules expressões de agregação que devem ser verdadeiras (regras entre campos)
     * @return array<string, mixed>
     */
    private static function validator(array $jsonSchema, array $rules = []): array
    {
        $validator = ['$jsonSchema' => $jsonSchema];
        if ($rules !== []) {
            $validator['$expr'] = ['$and' => array_values($rules)];
        }
        return $validator;
    }

    /**
     * @param list<string> $required
     * @param array<string, array<string, mixed>> $properties
     * @param array<string, mixed> $extra palavras-chave adicionais do JSON Schema (anyOf, not...)
     * @return array<string, mixed>
     */
    private static function root(string $title, array $required, array $properties, array $extra = []): array
    {
        return ['bsonType' => 'object', 'title' => $title, 'required' => array_values($required), 'properties' => $properties] + $extra;
    }

    /**
     * @param list<string> $required
     * @param array<string, array<string, mixed>> $properties
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function struct(string $description, array $required, array $properties, bool $nullable = false, array $extra = []): array
    {
        return [
            'bsonType' => $nullable ? ['object', 'null'] : 'object',
            'description' => $description,
            'required' => array_values($required),
            'properties' => $properties,
        ] + $extra;
    }

    /**
     * @param array<string, mixed> $items
     * @return array<string, mixed>
     */
    private static function listOf(string $description, array $items, ?int $min, int $max, bool $unique = false): array
    {
        $field = ['bsonType' => 'array', 'description' => $description, 'items' => $items, 'maxItems' => $max];
        if ($min !== null) {
            $field['minItems'] = $min;
        }
        if ($unique) {
            $field['uniqueItems'] = true;
        }
        return $field;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function text(string $description, int $min, int $max, ?string $pattern = null, bool $nullable = false, array $extra = []): array
    {
        $field = [
            'bsonType' => $nullable ? ['string', 'null'] : 'string',
            'minLength' => $min,
            'maxLength' => $max,
            'description' => $description,
        ];
        if ($pattern !== null) {
            $field['pattern'] = $pattern;
        }
        return $field + $extra;
    }

    /**
     * @param list<string> $values
     * @return array<string, mixed>
     */
    private static function choice(string $description, array $values, bool $nullable = false): array
    {
        $values = array_values($values);
        return [
            'bsonType' => $nullable ? ['string', 'null'] : 'string',
            'enum' => $nullable ? [...$values, null] : $values,
            'description' => $description,
        ];
    }

    /** @return array<string, mixed> */
    private static function objectId(string $description, bool $nullable = false): array
    {
        return ['bsonType' => $nullable ? ['objectId', 'null'] : 'objectId', 'description' => $description];
    }

    /** @return array<string, mixed> */
    private static function date(string $description, bool $nullable = false): array
    {
        return ['bsonType' => $nullable ? ['date', 'null'] : 'date', 'description' => $description];
    }

    /** @return array<string, mixed> */
    private static function flag(string $description): array
    {
        return ['bsonType' => 'bool', 'description' => $description];
    }

    /** Número inteiro dentro de um intervalo ('number' + multipleOf 1: aceita int, long e double inteiro). @return array<string, mixed> */
    private static function whole(string $description, int $min, int $max): array
    {
        return ['bsonType' => 'number', 'minimum' => $min, 'maximum' => $max, 'multipleOf' => 1, 'description' => $description];
    }

    /** @return array<string, mixed> */
    private static function percent(string $description): array
    {
        return ['bsonType' => 'number', 'minimum' => 0, 'maximum' => 100, 'description' => $description];
    }

    /**
     * Data de calendário: tipo date e exatamente meia-noite UTC (convenção do projeto, ver Bson::day()).
     * Sem isso, 09/10 às 00:00 e 09/10 às 12:00 seriam "dias diferentes" para o índice único (aluno, dia).
     * O $and corta no primeiro falso, então $dateTrunc nunca recebe um valor que não seja data.
     *
     * @return array<string, mixed>
     */
    private static function calendarDay(string $path, bool $nullable = false): array
    {
        $strict = ['$and' => [
            ['$eq' => [['$type' => $path], 'date']],
            ['$eq' => [['$dateTrunc' => ['date' => $path, 'unit' => 'day']], $path]],
        ]];
        if (!$nullable) {
            return $strict;
        }
        return ['$or' => [['$in' => [['$type' => $path], ['missing', 'null']]], $strict]];
    }
}
