<?php
declare(strict_types=1);

namespace Saa\Database;

/** Nomes das coleções do SAA no MongoDB. */
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

    // Chamada completa de uma turma em uma data (presentes e ausentes).
    public const ATTENDANCE_SESSIONS = 'attendance_sessions';

    // Notificação push (PWA): aparelhos inscritos, convites de ativação e fila de entrega.
    public const PUSH_SUBSCRIPTIONS = 'push_subscriptions';
    public const PUSH_INVITES = 'push_invites';
    public const PUSH_QUEUE = 'push_queue';
}
