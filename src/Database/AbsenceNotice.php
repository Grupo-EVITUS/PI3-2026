<?php
declare(strict_types=1);

namespace Saa\Database;

use DateTimeImmutable;

/**
 * Texto do e-mail de aviso de ausência, comum à chamada, ao reenvio e ao lembrete de push sem resposta,
 * e da correção enviada quando a escola desfaz uma falta marcada por engano.
 */
final class AbsenceNotice
{
    /**
     * @param bool $reminder lembrete por e-mail de um push entregue que ficou sem justificativa
     * @return array{recipient: string, subject: string, body: string}
     */
    public static function email(
        string $recipient, string $guardianName, string $studentName, string $className,
        string $absenceDate, string $link, bool $reminder = false
    ): array {
        $day = (new DateTimeImmutable($absenceDate))->format('d/m/Y');
        $intro = $reminder
            ? 'Enviamos uma notificação ao seu celular sobre a ausência de ' . $studentName . ' na turma '
                . $className . ' em ' . $day . ', mas ainda não recebemos a justificativa.'
            : 'Informamos que ' . $studentName . ' não compareceu à turma ' . $className . ' em ' . $day . '.';
        return [
            'recipient' => $recipient,
            'subject' => ($reminder ? 'Lembrete: comunicado de ausência — ' : 'Comunicado de ausência — ') . $studentName,
            'body' => 'Olá, ' . $guardianName . ".\n\n"
                . $intro
                . "\n\nPara enviar a justificativa, acesse o link individual:\n"
                . $link
                . "\n\nAtenciosamente,\nEquipe escolar",
        ];
    }

    /**
     * Correção de um aviso já enviado (ou que pode ter chegado): sem link, porque não há o que justificar.
     *
     * @return array{recipient: string, subject: string, body: string}
     */
    public static function correctionEmail(
        string $recipient, string $guardianName, string $studentName, string $className, string $absenceDate
    ): array {
        $day = (new DateTimeImmutable($absenceDate))->format('d/m/Y');
        return [
            'recipient' => $recipient,
            'subject' => 'Correção: desconsidere o aviso de ausência — ' . $studentName,
            'body' => 'Olá, ' . $guardianName . ".\n\n"
                . 'O aviso de que ' . $studentName . ' não compareceu à turma ' . $className . ' em ' . $day
                . ' foi um erro de registro: ' . $studentName . ' estava presente.'
                . "\n\nPor favor, desconsidere esse aviso. O link de justificativa não é mais necessário."
                . "\n\nPedimos desculpas pelo transtorno.\n\nAtenciosamente,\nEquipe escolar",
        ];
    }
}
