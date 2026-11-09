<?php
declare(strict_types=1);

namespace Saa\Support;

use InvalidArgumentException;
use Saa\Domain\AttendanceStatus;
use Saa\Domain\EmailStatus;
use Saa\Domain\JustificationReason;
use Saa\Domain\JustificationStatus;
use Saa\Domain\NotificationStatus;
use Saa\Domain\Profile;
use Saa\Domain\Relationship;
use Saa\Domain\Shift;

/**
 * Rótulos pt-BR e selos (badges) dos enums de domínio, para os helpers label() e badge().
 * Tipos: shift, profile, attendance_status, justification_status, reason, notification_status, email_status, relationship.
 */
final class Labels
{
    /** @var array<string, class-string<\BackedEnum>> */
    private const ENUMS = [
        'shift' => Shift::class,
        'profile' => Profile::class,
        'attendance_status' => AttendanceStatus::class,
        'justification_status' => JustificationStatus::class,
        'reason' => JustificationReason::class,
        'notification_status' => NotificationStatus::class,
        'email_status' => EmailStatus::class,
        'relationship' => Relationship::class,
    ];

    /** Tom visual de cada código (success | warning | danger | info | neutral). Códigos fora da tabela: neutral. */
    private const TONES = [
        'attendance_status' => ['PRESENT' => 'success', 'ABSENT' => 'danger', 'EXCUSED' => 'info'],
        'justification_status' => ['PENDING' => 'warning', 'RESPONDED' => 'success'],
        'notification_status' => ['PENDING' => 'neutral', 'SENT' => 'info', 'DELIVERED' => 'info', 'OPENED' => 'success', 'FAILED' => 'danger'],
        'email_status' => ['VALID' => 'success', 'UNVERIFIED' => 'warning', 'BOUNCED' => 'danger'],
    ];

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::ENUMS);
    }

    /**
     * Rótulo em pt-BR do código (código desconhecido: o próprio texto; vazio: "—").
     *
     * @throws InvalidArgumentException tipo desconhecido (erro de digitação no template)
     */
    public static function label(string $type, mixed $code): string
    {
        $enum = self::ENUMS[$type] ?? throw new InvalidArgumentException('Tipo de rótulo desconhecido: ' . $type);
        return $enum::labelFor($code);
    }

    /** HTML do selo: <span class="badge badge--tom">Rótulo</span> (rótulo escapado). */
    public static function badge(string $type, mixed $code): string
    {
        $label = self::label($type, $code);
        $tone = is_string($code) ? (self::TONES[$type][$code] ?? 'neutral') : 'neutral';
        return '<span class="badge badge--' . $tone . '">' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
    }
}
