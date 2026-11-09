<?php
declare(strict_types=1);

namespace Saa\Tests\Acceptance;

/**
 * O mesmo conjunto de StaticFilesTest, porém com o servidor iniciado SEM "-t public" (o DocumentRoot vira a raiz do
 * projeto, como no antigo "php -S 127.0.0.1:8000 router.php"): o router.php precisa entregar os arquivos sozinho.
 */
final class StaticFilesWithoutDocumentRootTest extends StaticFilesTest
{
    protected static function serverUsesPublicDocumentRoot(): bool
    {
        return false;
    }
}
