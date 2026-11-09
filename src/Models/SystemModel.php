<?php
declare(strict_types=1);

namespace Saa\Models;

use Throwable;

/** Informações do próprio MongoDB (saúde e ambiente). Não tem coleção; nenhuma consulta lança exceção. */
final class SystemModel extends Model
{
    /** O servidor respondeu ao comando ping? */
    public function ping(): bool
    {
        try {
            $this->database->command(['ping' => 1])->toArray();
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function databaseName(): string
    {
        return $this->database->getDatabaseName();
    }

    public function collectionCount(): ?int
    {
        try {
            return iterator_count($this->database->listCollectionNames());
        } catch (Throwable) {
            return null;
        }
    }

    public function serverVersion(): ?string
    {
        try {
            $info = $this->database->command(['buildInfo' => 1])->toArray()[0] ?? [];
            return is_array($info) && is_string($info['version'] ?? null) ? $info['version'] : null;
        } catch (Throwable) {
            return null;
        }
    }
}
