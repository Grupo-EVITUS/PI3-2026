<?php
declare(strict_types=1);

namespace Saa\Database;

/**
 * Nomes das coleções do SAA no MongoDB.
 * attendance_sessions guarda a chamada completa da turma.
 */
final class Collections
{
    public const USERS = 'users';
    public const CLASSES = 'classes';
    public const STUDENTS = 'students';
    public const GUARDIANS = 'guardians';
    public const STUDENT_GUARDIANS = 'student_guardians';
    public const ABSENCES = 'absences';
    public const JUSTIFICATIONS = 'justifications';
    public const COMMUNICATIONS = 'communications';
    public const MAIL_QUEUE = 'mail_queue';
    public const OPERATION_LOGS = 'operation_logs';

    // Registro canônico de uma turma e data, sem presença presumida na importação.
    public const ATTENDANCE_SESSIONS = 'attendance_sessions';
    public const LEGACY_ATTENDANCE_SESSIONS = 'legacy_attendance_sessions';
    public const LEGACY_ATTENDANCE_RECORDS = 'legacy_attendance_records';

    // Notificação push (PWA): aparelhos inscritos, convites de ativação e fila de entrega.
    public const PUSH_SUBSCRIPTIONS = 'push_subscriptions';
    public const PUSH_INVITES = 'push_invites';
    public const PUSH_QUEUE = 'push_queue';

    /** @return list<string> */
    public static function legacyImport(): array
    {
        return [
            self::USERS, self::CLASSES, self::STUDENTS, self::GUARDIANS,
            self::STUDENT_GUARDIANS, self::ABSENCES, self::JUSTIFICATIONS,
            self::COMMUNICATIONS, self::MAIL_QUEUE, self::OPERATION_LOGS,
            self::LEGACY_ATTENDANCE_SESSIONS, self::LEGACY_ATTENDANCE_RECORDS,
        ];
    }
}
