<?php
declare(strict_types=1);

namespace Saa\Models;

use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\Exception\BulkWriteException;
use Throwable;

/**
 * Base dos Models: SOMENTE aqui se fala com o MongoDB (Controllers e Services nunca montam consultas).
 *
 * Regras: filtros e atualizações usam apenas valores tipados (ObjectId validado por Bson::objectId, strings já
 * normalizadas, listas brancas de campos) — nunca a entrada crua da requisição. Leituras gerais excluem campos
 * sensíveis (password_hash, token_hash) por projeção. Um array PHP vazio vira array BSON: para documento vazio
 * use new \stdClass.
 */
abstract class Model
{
    /** Nome da coleção (constante de Saa\Database\Collections). */
    protected const COLLECTION = '';

    public function __construct(protected readonly Database $database)
    {
    }

    protected function collection(): Collection
    {
        return $this->database->selectCollection(static::COLLECTION);
    }

    /** O erro do MongoDB é de chave duplicada (índice único, código 11000)? */
    protected static function isDuplicateKey(Throwable $error): bool
    {
        if ($error->getCode() === 11000) {
            return true;
        }
        if ($error instanceof BulkWriteException) {
            foreach ($error->getWriteResult()->getWriteErrors() as $writeError) {
                if ($writeError->getCode() === 11000) {
                    return true;
                }
            }
        }
        return false;
    }
}
