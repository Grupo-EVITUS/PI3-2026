<?php
declare(strict_types=1);

namespace Saa\Services;

use DateTimeZone;
use Saa\Core\Clock;
use Saa\Models\SystemModel;

/** Saúde da aplicação e dados do ambiente (MongoDB) para /health e para o card "Ambiente" da tela inicial. */
final class SystemStatusService
{
    public function __construct(private readonly SystemModel $system)
    {
    }

    /** @return array{status: string, mongo: bool, time: string} */
    public function health(): array
    {
        $mongo = $this->system->ping();
        return [
            'status' => $mongo ? 'ok' : 'degraded',
            'mongo' => $mongo,
            'time' => Clock::now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Dados da conexão (somente ADMIN). Falhas não lançam exceção: ok=false e valores desconhecidos como null.
     *
     * @return array{ok: bool, database: string, collections: int|null, server_version: string|null, driver_version: string|null}
     */
    public function environment(): array
    {
        $driver = phpversion('mongodb');
        $environment = [
            'ok' => false,
            'database' => $this->system->databaseName(),
            'collections' => null,
            'server_version' => null,
            'driver_version' => $driver === false ? null : $driver,
        ];
        if (!$this->system->ping()) {
            return $environment;
        }
        $environment['ok'] = true;
        $environment['collections'] = $this->system->collectionCount();
        $environment['server_version'] = $this->system->serverVersion();
        return $environment;
    }
}
