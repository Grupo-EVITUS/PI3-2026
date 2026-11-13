<?php
declare(strict_types=1);

namespace Saa\Database;

/**
 * Nomes de coleções compatíveis com o contrato SQLite que será importado na etapa 4.
 * attendance_sessions é reservado para a chamada completa da etapa 5; não é gravado nesta etapa.
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

    // Reservada ao registro canônico da chamada completa na etapa 5.
    public const ATTENDANCE_SESSIONS = 'attendance_sessions';

    /** @return list<string> */
    public static function legacyImport(): array
    {
        return [
            self::USERS, self::CLASSES, self::STUDENTS, self::GUARDIANS,
            self::STUDENT_GUARDIANS, self::ABSENCES, self::JUSTIFICATIONS,
            self::COMMUNICATIONS, self::MAIL_QUEUE, self::OPERATION_LOGS,
        ];
    }
}
