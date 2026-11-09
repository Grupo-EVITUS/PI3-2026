<?php
declare(strict_types=1);

namespace Saa\Tests\Acceptance;

use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Saa\Core\Config;
use Saa\Tests\Support\HttpTestCase;

/**
 * router.php com "php -S ... -t public router.php": arquivos de public/ saem com o Content-Type correto (css, js,
 * svg), e nada fora de public/ (nem arquivos ocultos ou .php) pode ser lido por path traversal.
 */
class StaticFilesTest extends HttpTestCase
{
    public function testSvgSpriteIsServedWithSvgContentType(): void
    {
        $this->requireAsset('img/icons.svg');

        $response = $this->http->get('/assets/img/icons.svg');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('image/svg+xml', $response->contentType());
        self::assertStringContainsString('<svg', $response->body);
        self::assertStringContainsString('id="icon-home"', $response->body);
    }

    public function testStylesheetIsServedAsCss(): void
    {
        $file = $this->firstAsset('css');

        $response = $this->http->get('/assets/' . $file);

        self::assertSame(200, $response->status);
        self::assertStringContainsString('text/css', $response->contentType());
    }

    public function testScriptIsServedAsJavaScript(): void
    {
        $file = $this->firstAsset('js');

        $response = $this->http->get('/assets/' . $file);

        self::assertSame(200, $response->status);
        self::assertMatchesRegularExpression('#(text|application)/javascript#', $response->contentType());
    }

    public function testStaticFilesDoNotNeedASession(): void
    {
        $this->requireAsset('img/icons.svg');

        $response = $this->http->get('/assets/img/icons.svg');

        self::assertNull($response->setCookie('SAASESSID'));
    }

    /** @return array<string, array{string}> */
    public static function traversalAttempts(): array
    {
        return [
            'dot dot' => ['/assets/../../composer.json'],
            'encoded dot dot' => ['/%2e%2e/composer.json'],
            'encoded slash' => ['/..%2fcomposer.json'],
            'double encoded' => ['/assets/%252e%252e/%252e%252e/composer.json'],
            'backslash' => ['/assets/..%5c..%5ccomposer.json'],
            'hidden env example' => ['/assets/%2e%2e/%2e%2e/.env.example'],
            'front controller source' => ['/index.php'],
            'htaccess' => ['/.htaccess'],
            'null byte' => ['/assets/css/%00.php'],
            'router source' => ['/../router.php'],
        ];
    }

    #[DataProvider('traversalAttempts')]
    public function testTraversalAndSensitiveFilesAreNeverServed(string $path): void
    {
        $response = $this->http->get($path, [], true);

        self::assertContains($response->status, [400, 404], "{$path} respondeu {$response->status}");
        self::assertStringNotContainsString('grupo-evitus/saa', $response->body, 'composer.json não pode vazar');
        self::assertStringNotContainsString('RewriteEngine', $response->body, '.htaccess não pode vazar');
        self::assertStringNotContainsString('MONGODB_URI', $response->body, '.env não pode vazar');
        self::assertStringNotContainsString('StaticFiles::resolve', $response->body, 'código-fonte não pode vazar');
    }

    protected function requireAsset(string $relative): void
    {
        if (!is_file(Config::basePath('public/assets/' . $relative))) {
            self::markTestSkipped("public/assets/{$relative} ainda não existe (entregue pelo agente de UI).");
        }
    }

    /** Primeiro arquivo (por ordem alfabética) de public/assets/ com a extensão informada, relativo a assets/. */
    protected function firstAsset(string $extension): string
    {
        $root = Config::basePath('public/assets');
        $found = [];
        if (is_dir($root)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $item) {
                if ($item->isFile() && strtolower($item->getExtension()) === $extension) {
                    $found[] = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
                }
            }
        }
        sort($found);
        if ($found === []) {
            self::markTestSkipped("Não há arquivo .{$extension} em public/assets/ (entregue pelo agente de UI).");
        }
        return $found[0];
    }
}
