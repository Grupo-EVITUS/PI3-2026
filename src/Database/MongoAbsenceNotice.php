<?php
declare(strict_types=1);

namespace Saa\Database;

use DateTimeImmutable;
use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;
use MongoDB\Driver\Session;

/**
 * Cria a comunicação de uma ausência e põe o aviso na fila certa, dentro da transação de quem chama.
 * Responsável com aparelho inscrito recebe push (com e-mail de reserva); os demais, e-mail.
 * Usado pela chamada, pelo reenvio de comunicação que falhou e pelo lembrete por e-mail de um push
 * entregue que ficou sem justificativa.
 */
final class MongoAbsenceNotice
{
    /** Valida o endereço público usado nos links de justificativa e o devolve sem barra final. */
    public static function baseUrl(string $baseUrl): string
    {
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || strlen($baseUrl) > 300
            || preg_match('/[\x00-\x1f\x7f]/', $baseUrl) === 1) {
            throw new InvalidArgumentException('Endereço público do sistema inválido.');
        }
        return rtrim($baseUrl, '/');
    }

    /**
     * @param array{_id: ObjectId, name: string, email: string} $guardian responsável ativo
     * @param ObjectId|null $reminderOf comunicação por push sem resposta: força e-mail com texto de lembrete
     * @return string canal usado: PUSH ou EMAIL
     */
    public static function queue(
        Database $database, Session $session, array $guardian, ObjectId $absenceId, string $studentName,
        string $className, string $absenceDate, string $baseUrl, UTCDateTime $now, ?ObjectId $retryOf = null,
        ?ObjectId $reminderOf = null
    ): string {
        if (!filter_var($guardian['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('E-mail de responsável inválido; chamada não registrada.');
        }
        $options = ['session' => $session];
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $communicationId = new ObjectId();
        $usePush = $reminderOf === null && $database->push_subscriptions->countDocuments(
            ['guardian_id' => $guardian['_id'], 'active' => true], $options
        ) > 0;
        $communication = [
            '_id' => $communicationId, 'absence_id' => $absenceId, 'guardian_id' => $guardian['_id'],
            'email_to' => $guardian['email'], 'token_hash' => hash('sha256', $token),
            'status' => 'PENDENTE', 'created_at' => $now, 'channel' => $usePush ? 'PUSH' : 'EMAIL',
        ];
        if ($retryOf !== null) {
            $communication['retry_of_id'] = $retryOf;
        }
        if ($reminderOf !== null) {
            $communication['reminder_of_id'] = $reminderOf;
        }
        $database->communications->insertOne($communication, $options);
        $day = (new DateTimeImmutable($absenceDate))->format('d/m/Y');
        $path = '/justificar/' . rawurlencode($token);
        $intro = $reminderOf === null
            ? 'Informamos que ' . $studentName . ' não compareceu à turma ' . $className . ' em ' . $day . '.'
            : 'Enviamos uma notificação ao seu celular sobre a ausência de ' . $studentName . ' na turma '
                . $className . ' em ' . $day . ', mas ainda não recebemos a justificativa.';
        $email = [
            'recipient' => $guardian['email'],
            'subject' => ($reminderOf === null ? 'Comunicado de ausência — ' : 'Lembrete: comunicado de ausência — ')
                . $studentName,
            'body' => 'Olá, ' . $guardian['name'] . ".\n\n" . $intro
                . "\n\nPara enviar a justificativa, acesse o link individual:\n"
                . $baseUrl . $path
                . "\n\nAtenciosamente,\nEquipe escolar",
        ];
        if ($usePush) {
            // O e-mail fica guardado e só entra na fila se o push não for entregue.
            $database->push_queue->insertOne([
                '_id' => new ObjectId(), 'communication_id' => $communicationId,
                'guardian_id' => $guardian['_id'],
                'title' => 'Ausência de ' . $studentName,
                'body' => $studentName . ' não compareceu à turma ' . $className
                    . ' em ' . $day . '. Toque para enviar a justificativa.',
                'url' => $path, 'fallback_email' => $email,
                // Endereço público do lembrete por e-mail, caso o push entregue fique sem resposta.
                'base_url' => $baseUrl,
                'status' => 'AGUARDANDO', 'created_at' => $now,
            ], $options);
            return 'PUSH';
        }
        $database->mail_queue->insertOne([
            '_id' => new ObjectId(), 'communication_id' => $communicationId,
            'status' => 'AGUARDANDO', 'created_at' => $now,
        ] + $email, $options);
        return 'EMAIL';
    }
}
