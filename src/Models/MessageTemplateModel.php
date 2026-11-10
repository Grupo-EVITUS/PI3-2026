<?php
declare(strict_types=1);

namespace Saa\Models;

use MongoDB\Driver\Exception\BulkWriteException;
use Saa\Database\Bson;
use Saa\Database\Collections;

/**
 * Coleção message_templates: textos predefinidos dos e-mails (code ÚNICO, subject, body com {{variáveis}},
 * variables, active, updated_at). O modelo padrão ABSENCE_NOTICE é garantido por Saa\Database\DefaultData.
 */
final class MessageTemplateModel extends Model
{
    protected const COLLECTION = Collections::MESSAGE_TEMPLATES;

    /** @return array<string, mixed>|null modelo ATIVO com o código informado */
    public function findActiveByCode(string $code): ?array
    {
        $template = $this->collection()->findOne(['code' => $code, 'active' => true]);
        return is_array($template) ? $template : null;
    }

    /**
     * Cria o modelo somente se ainda não existir um com o mesmo código (idempotente: nunca altera um modelo existente,
     * que pode ter sido editado pela escola).
     *
     * @param list<string> $variables
     * @return bool true se o modelo foi criado agora
     */
    public function insertIfMissing(string $code, string $subject, string $body, array $variables): bool
    {
        try {
            $result = $this->collection()->updateOne(
                ['code' => $code],
                ['$setOnInsert' => [
                    'code' => $code,
                    'subject' => $subject,
                    'body' => $body,
                    'variables' => array_values($variables),
                    'active' => true,
                    'updated_at' => Bson::now(),
                ]],
                ['upsert' => true]
            );
        } catch (BulkWriteException $error) {
            if (self::isDuplicateKey($error)) {
                return false; // outro processo criou o mesmo modelo no mesmo instante
            }
            throw $error;
        }
        return $result->getUpsertedCount() === 1;
    }
}
