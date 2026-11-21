<?php
declare(strict_types=1);

namespace Saa\Database;

use DateTimeImmutable;

/** Texto do e-mail de aviso de ausência, comum à chamada e ao reenvio. */
final class AbsenceNotice
{
    /** @return array{recipient: string, subject: string, body: string} */
    public static function email(
        string $recipient, string $guardianName, string $studentName, string $className,
        string $absenceDate, string $link
    ): array {
        $day = (new DateTimeImmutable($absenceDate))->format('d/m/Y');
        return [
            'recipient' => $recipient,
            'subject' => 'Comunicado de ausência — ' . $studentName,
            'body' => 'Olá, ' . $guardianName . ".\n\n"
                . 'Informamos que ' . $studentName . ' não compareceu à turma '
                . $className . ' em ' . $day
                . ".\n\nPara enviar a justificativa, acesse o link individual:\n"
                . $link
                . "\n\nAtenciosamente,\nEquipe escolar",
        ];
    }
}
