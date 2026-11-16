<?php
declare(strict_types=1);

namespace Saa\Database;

use MongoDB\Database;

/**
 * Contrato de persistência candidato. Instantes são BSON date, IDs são ObjectId.
 * Aplicação explícita por CLI; nunca executada durante uma requisição web.
 */
final class Schema
{
    public static function definitions(): array
    {
        $text = ['bsonType' => 'string'];
        $id = ['bsonType' => 'objectId'];
        $date = ['bsonType' => 'date'];
        $nullableDate = ['bsonType' => ['date', 'null']];
        $nullableId = ['bsonType' => ['objectId', 'null']];
        $active = ['bsonType' => 'bool'];
        $enum = static fn(array $values): array => ['bsonType' => 'string', 'enum' => $values];
        $schema = static function (array $required, array $properties): array {
            return [
                'bsonType' => 'object',
                'required' => array_merge(['_id'], $required),
                'properties' => array_merge(['_id' => ['bsonType' => 'objectId']], $properties),
            ];
        };
        return [
            Collections::USERS => $schema(
                ['name', 'email', 'password_hash', 'profile', 'active', 'created_at'],
                ['name' => $text, 'email' => $text, 'password_hash' => $text,
                 'profile' => $enum(['AOE', 'COORDENACAO', 'DIRETOR']), 'active' => $active,
                 'created_at' => $date, 'is_initial_admin' => ['bsonType' => 'bool']]
            ),
            Collections::CLASSES => $schema(
                ['name', 'grade', 'academic_year', 'shift', 'active', 'created_at'],
                ['name' => $text, 'grade' => $text, 'academic_year' => ['bsonType' => ['int', 'long']],
                 'shift' => $enum(['Manhã', 'Tarde', 'Noite', 'Integral']), 'active' => $active, 'created_at' => $date,
                 'link_revision' => ['bsonType' => ['int', 'long']]]
            ),
            Collections::STUDENTS => $schema(
                ['name', 'class_id', 'active', 'created_at'],
                ['name' => $text, 'class_id' => $id, 'registration_no' => ['bsonType' => ['string', 'null']],
                 'active' => $active, 'created_at' => $date, 'link_revision' => ['bsonType' => ['int', 'long']]]
            ),
            Collections::GUARDIANS => $schema(
                ['name', 'email', 'relationship', 'active', 'created_at'],
                ['name' => $text, 'email' => $text, 'relationship' => $text, 'active' => $active, 'created_at' => $date]
            ),
            Collections::STUDENT_GUARDIANS => $schema(
                ['student_id', 'guardian_id'], ['student_id' => $id, 'guardian_id' => $id]
            ),
            Collections::ATTENDANCE_SESSIONS => $schema(
                ['class_id', 'attendance_date', 'completed_by', 'completed_at', 'class_name', 'academic_year',
                 'class_shift', 'entries', 'roster_hash', 'status'],
                [
                    'class_id' => $id,
                    'attendance_date' => ['bsonType' => 'string', 'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'],
                    'completed_by' => $id, 'completed_at' => $date,
                    'class_name' => $text,
                    'academic_year' => ['bsonType' => ['int', 'long']],
                    'class_shift' => $enum(['Manhã', 'Tarde', 'Noite', 'Integral']),
                    'entries' => [
                        'bsonType' => 'array', 'minItems' => 1,
                        'items' => [
                            'bsonType' => 'object',
                            'required' => ['student_id', 'student_name', 'status'],
                            'properties' => [
                                'student_id' => $id, 'student_name' => $text,
                                'registration_no' => ['bsonType' => ['string', 'null']],
                                'status' => $enum(['PRESENTE', 'AUSENTE']),
                            ],
                        ],
                    ],
                    'roster_hash' => ['bsonType' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
                    'status' => $enum(['PROCESSANDO', 'CONCLUIDA']),
                ]
            ),
            Collections::LEGACY_ATTENDANCE_SESSIONS => $schema(
                ['legacy_id', 'class_id', 'attendance_date', 'completed_by', 'completed_at'],
                ['legacy_id' => ['bsonType' => ['int', 'long']], 'class_id' => $id,
                 'attendance_date' => ['bsonType' => 'string', 'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'],
                 'completed_by' => $id, 'completed_at' => $date]
            ),
            Collections::LEGACY_ATTENDANCE_RECORDS => $schema(
                ['legacy_id', 'session_id', 'student_id', 'status', 'created_at'],
                ['legacy_id' => ['bsonType' => ['int', 'long']], 'session_id' => $id,
                 'student_id' => $id, 'status' => $enum(['PRESENTE', 'AUSENTE']), 'created_at' => $date]
            ),
            Collections::ABSENCES => $schema(
                ['student_id', 'absence_date', 'status', 'registered_by', 'created_at'],
                ['student_id' => $id, 'class_id' => $nullableId,
                 'attendance_session_id' => $nullableId,
                 'legacy_attendance_session_id' => $nullableId,
                 'absence_date' => ['bsonType' => 'string', 'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'],
                 'status' => $enum(['PENDENTE', 'RESPONDIDA']), 'registered_by' => $id, 'created_at' => $date,
                 'class_snapshot_source' => $enum(['captured', 'legacy_backfill', 'unknown'])]
            ),
            Collections::COMMUNICATIONS => $schema(
                ['absence_id', 'guardian_id', 'email_to', 'token_hash', 'status', 'created_at'],
                ['absence_id' => $id, 'guardian_id' => $id, 'email_to' => $text, 'token_hash' => $text,
                 'status' => $enum(['PENDENTE', 'ENVIADO', 'FALHA']), 'created_at' => $date,
                 'sent_at' => $nullableDate, 'expires_at' => $nullableDate, 'retry_of_id' => $nullableId,
                 'failure_detail' => ['bsonType' => ['string', 'null']],
                 // Ausente em comunicações legadas/importadas, que foram sempre por e-mail.
                 'channel' => $enum(['EMAIL', 'PUSH'])]
            ),
            Collections::MAIL_QUEUE => $schema(
                ['communication_id', 'recipient', 'subject', 'body', 'status', 'created_at'],
                ['communication_id' => $id, 'recipient' => $text, 'subject' => $text, 'body' => $text,
                 'status' => $enum(['AGUARDANDO', 'PROCESSANDO', 'CONCLUIDO', 'FALHA', 'QUARENTENA']),
                 'created_at' => $date, 'started_at' => $nullableDate, 'processed_at' => $nullableDate,
                 'next_attempt_at' => $nullableDate, 'claim_id' => $nullableId,
                 'attempts' => ['bsonType' => ['int', 'long']]]
            ),
            Collections::PUSH_SUBSCRIPTIONS => $schema(
                ['guardian_id', 'endpoint', 'endpoint_hash', 'p256dh', 'auth', 'active', 'created_at'],
                ['guardian_id' => $id, 'endpoint' => ['bsonType' => 'string', 'maxLength' => 1024],
                 'endpoint_hash' => ['bsonType' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
                 'p256dh' => ['bsonType' => 'string', 'pattern' => '^[A-Za-z0-9_-]{87}$'],
                 'auth' => ['bsonType' => 'string', 'pattern' => '^[A-Za-z0-9_-]{22}$'],
                 'active' => $active, 'created_at' => $date, 'source' => $enum(['CONVITE', 'COMUNICADO']),
                 'last_success_at' => $nullableDate, 'revoked_at' => $nullableDate,
                 'revoke_reason' => ['bsonType' => ['string', 'null']]]
            ),
            Collections::PUSH_INVITES => $schema(
                ['guardian_id', 'token_hash', 'created_by', 'created_at', 'expires_at'],
                ['guardian_id' => $id, 'token_hash' => ['bsonType' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
                 'created_by' => $id, 'created_at' => $date, 'expires_at' => $date, 'used_at' => $nullableDate]
            ),
            Collections::PUSH_QUEUE => $schema(
                ['communication_id', 'guardian_id', 'title', 'body', 'url', 'status', 'created_at'],
                ['communication_id' => $id, 'guardian_id' => $id, 'title' => $text, 'body' => $text, 'url' => $text,
                 'status' => $enum(['AGUARDANDO', 'PROCESSANDO', 'CONCLUIDO', 'ENCAMINHADO_EMAIL']),
                 'fallback_email' => ['bsonType' => ['object', 'null'],
                     'required' => ['recipient', 'subject', 'body'],
                     'properties' => ['recipient' => $text, 'subject' => $text, 'body' => $text]],
                 'created_at' => $date, 'started_at' => $nullableDate, 'processed_at' => $nullableDate,
                 'next_attempt_at' => $nullableDate, 'claim_id' => $nullableId,
                 'attempts' => ['bsonType' => ['int', 'long']],
                 'failure_detail' => ['bsonType' => ['string', 'null']]]
            ),
            Collections::JUSTIFICATIONS => $schema(
                ['absence_id', 'communication_id', 'guardian_id', 'reason', 'explanation', 'submitted_at'],
                ['absence_id' => $id, 'communication_id' => $id, 'guardian_id' => $id,
                 'reason' => $enum(['DOENCA', 'CONSULTA_MEDICA', 'PROBLEMA_FAMILIAR', 'TRANSPORTE', 'OUTRO']),
                 'explanation' => $text, 'submitted_at' => $date]
            ),
            Collections::OPERATION_LOGS => $schema(
                ['action', 'entity', 'details', 'happened_at'],
                ['user_id' => $nullableId, 'action' => $text, 'entity' => $text,
                 'entity_id' => ['bsonType' => ['string', 'null']], 'details' => $text, 'happened_at' => $date]
            ),
        ];
    }

    public static function indexes(): array
    {
        return [
            // ASCII NOCASE do SQLite é preservado por email_key, calculado pelo runtime/importador.
            Collections::USERS => [
                ['key' => ['email_key' => 1], 'name' => 'users_email_key_unique', 'unique' => true],
                ['key' => ['is_initial_admin' => 1], 'name' => 'users_initial_admin_unique', 'unique' => true,
                 'partialFilterExpression' => ['is_initial_admin' => true]],
            ],
            Collections::STUDENTS => [
                ['key' => ['registration_no' => 1], 'name' => 'students_registration_unique', 'unique' => true,
                 'partialFilterExpression' => ['registration_no' => ['$type' => 'string']]],
                ['key' => ['class_id' => 1, 'active' => 1], 'name' => 'students_class_active'],
            ],
            Collections::STUDENT_GUARDIANS => [
                ['key' => ['student_id' => 1, 'guardian_id' => 1], 'name' => 'student_guardian_unique', 'unique' => true],
                ['key' => ['guardian_id' => 1], 'name' => 'links_guardian'],
            ],
            Collections::ATTENDANCE_SESSIONS => [
                ['key' => ['class_id' => 1, 'attendance_date' => 1], 'name' => 'attendance_class_day_unique', 'unique' => true],
                ['key' => ['attendance_date' => -1, 'status' => 1], 'name' => 'attendance_date_status'],
            ],
            Collections::LEGACY_ATTENDANCE_SESSIONS => [
                ['key' => ['legacy_id' => 1], 'name' => 'legacy_session_id_unique', 'unique' => true],
            ],
            Collections::LEGACY_ATTENDANCE_RECORDS => [
                ['key' => ['session_id' => 1, 'student_id' => 1], 'name' => 'legacy_session_student_unique', 'unique' => true],
            ],
            Collections::ABSENCES => [
                ['key' => ['student_id' => 1, 'absence_date' => 1], 'name' => 'absence_student_day_unique', 'unique' => true],
                ['key' => ['attendance_session_id' => 1, 'student_id' => 1], 'name' => 'absence_session_student_unique',
                 'unique' => true, 'partialFilterExpression' => ['attendance_session_id' => ['$type' => 'objectId']]],
                ['key' => ['status' => 1, 'absence_date' => -1], 'name' => 'absence_status_date'],
                ['key' => ['class_id' => 1, 'absence_date' => -1], 'name' => 'absence_class_date'],
            ],
            Collections::COMMUNICATIONS => [
                ['key' => ['token_hash' => 1], 'name' => 'communication_token_unique', 'unique' => true],
                ['key' => ['absence_id' => 1, 'created_at' => 1], 'name' => 'communication_absence_created'],
            ],
            Collections::MAIL_QUEUE => [
                ['key' => ['communication_id' => 1], 'name' => 'queue_communication_unique', 'unique' => true],
                ['key' => ['status' => 1, 'created_at' => 1], 'name' => 'queue_status_created'],
                ['key' => ['status' => 1, 'next_attempt_at' => 1], 'name' => 'queue_retry_due'],
                ['key' => ['status' => 1, 'started_at' => 1], 'name' => 'queue_stale_lease'],
            ],
            Collections::PUSH_SUBSCRIPTIONS => [
                // Um aparelho compartilhado pode servir a mais de um responsável.
                ['key' => ['guardian_id' => 1, 'endpoint_hash' => 1], 'name' => 'push_guardian_endpoint_unique',
                 'unique' => true],
                ['key' => ['guardian_id' => 1, 'active' => 1, 'created_at' => -1], 'name' => 'push_guardian_active'],
            ],
            Collections::PUSH_INVITES => [
                ['key' => ['token_hash' => 1], 'name' => 'push_invite_token_unique', 'unique' => true],
                ['key' => ['guardian_id' => 1, 'created_at' => -1], 'name' => 'push_invite_guardian'],
            ],
            Collections::PUSH_QUEUE => [
                ['key' => ['communication_id' => 1], 'name' => 'push_queue_communication_unique', 'unique' => true],
                ['key' => ['status' => 1, 'created_at' => 1], 'name' => 'push_queue_status_created'],
                ['key' => ['status' => 1, 'next_attempt_at' => 1], 'name' => 'push_queue_retry_due'],
                ['key' => ['status' => 1, 'started_at' => 1], 'name' => 'push_queue_stale_lease'],
            ],
            Collections::JUSTIFICATIONS => [
                ['key' => ['absence_id' => 1], 'name' => 'justification_absence_unique', 'unique' => true],
            ],
            Collections::OPERATION_LOGS => [
                ['key' => ['happened_at' => -1], 'name' => 'logs_happened_at'],
            ],
        ];
    }

    /** SQLite NOCASE dobra somente A-Z; não altera caracteres não ASCII. */
    public static function emailKey(string $email): string
    {
        return strtr($email, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }

    public static function validator(array $schema, bool $user = false): array
    {
        if (!$user) {
            return ['$jsonSchema' => $schema];
        }
        $schema['required'][] = 'email_key';
        $schema['properties']['email_key'] = ['bsonType' => 'string'];
        $safeEmail = ['$cond' => [
            ['$eq' => [['$type' => '$email'], 'string']], '$email', '',
        ]];
        $folded = ['$reduce' => [
            'input' => ['$range' => [0, 26]],
            'initialValue' => $safeEmail,
            'in' => ['$replaceAll' => [
                'input' => '$$value',
                'find' => ['$substrBytes' => ['ABCDEFGHIJKLMNOPQRSTUVWXYZ', '$$this', 1]],
                'replacement' => ['$substrBytes' => ['abcdefghijklmnopqrstuvwxyz', '$$this', 1]],
            ]],
        ]];
        return ['$and' => [
            ['$jsonSchema' => $schema],
            ['$expr' => ['$eq' => ['$email_key', $folded]]],
        ]];
    }

    /**
     * Reexecutável, sem drop/seed/envio de e-mails. Falha em dados inválidos e
     * conflitos de índices; mudanças DDL parciais podem ser retomadas após correção.
     */
    public static function apply(Database $database): void
    {
        $existing = iterator_to_array($database->listCollectionNames(), false);
        foreach (self::definitions() as $name => $schema) {
            $validator = self::validator($schema, $name === Collections::USERS);
            if (in_array($name, $existing, true)) {
                // collMod não valida retroativamente: conferir dados antes de promover.
                if ($database->selectCollection($name)->countDocuments(['$nor' => [$validator]]) > 0) {
                    throw new \RuntimeException('Coleção contém documentos incompatíveis: ' . $name);
                }
                $database->command(['collMod' => $name, 'validator' => $validator,
                    'validationLevel' => 'strict', 'validationAction' => 'error'])->toArray();
            } else {
                $database->createCollection($name, ['validator' => $validator,
                    'validationLevel' => 'strict', 'validationAction' => 'error']);
            }
            foreach (self::indexes()[$name] ?? [] as $index) {
                $key = $index['key'];
                unset($index['key']);
                $database->selectCollection($name)->createIndex($key, $index);
            }
        }
    }
}
