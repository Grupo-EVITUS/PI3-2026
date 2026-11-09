<?php
declare(strict_types=1);

namespace Saa\Database;

/**
 * Nomes das coleções MongoDB. Use SEMPRE estas constantes (nunca strings soltas).
 * As 8 primeiras são o modelo do grupo (ATIVIDADE_MODELAGEM_MONGODB); as demais são coleções de apoio.
 */
final class Collections
{
    // Modelo de domínio (8 coleções)
    public const USERS = 'users';
    public const STUDENTS = 'students';
    public const GUARDIANS = 'guardians';
    public const ACADEMIC_PERIODS = 'academic_periods';
    public const CLASSES = 'classes';
    public const ENROLLMENTS = 'enrollments';
    public const ATTENDANCE_RECORDS = 'attendance_records';
    public const EMAIL_NOTIFICATIONS = 'email_notifications';

    // Coleções de apoio da aplicação
    public const AUDIT_EVENTS = 'audit_events';
    public const PASSWORD_RESETS = 'password_resets';
    public const LOGIN_ATTEMPTS = 'login_attempts';
    public const MESSAGE_TEMPLATES = 'message_templates';

    /** @return list<string> */
    public static function domain(): array
    {
        return [
            self::USERS, self::STUDENTS, self::GUARDIANS, self::ACADEMIC_PERIODS,
            self::CLASSES, self::ENROLLMENTS, self::ATTENDANCE_RECORDS, self::EMAIL_NOTIFICATIONS,
        ];
    }

    /** @return list<string> */
    public static function support(): array
    {
        return [self::AUDIT_EVENTS, self::PASSWORD_RESETS, self::LOGIN_ATTEMPTS, self::MESSAGE_TEMPLATES];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_merge(self::domain(), self::support());
    }
}
