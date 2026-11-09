<?php
declare(strict_types=1);

namespace Saa\Core;

use InvalidArgumentException;

/**
 * Resposta HTTP imutável. Construa com html(), json(), text(), redirect() ou noContent();
 * ajuste com withHeader()/withStatus(); envie com send() (o Kernel faz isso).
 */
final class Response
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /** @var array<string, array{0: string, 1: string}> nome em minúsculas => [nome original, valor] */
    private array $headers = [];

    /** @param array<string, string> $headers */
    private function __construct(private int $status, private string $body, array $headers = [])
    {
        foreach ($headers as $name => $value) {
            $this->setHeader($name, $value);
        }
    }

    /** @param array<string, string> $headers */
    public static function html(string $body, int $status = 200, array $headers = []): self
    {
        return new self($status, $body, ['Content-Type' => 'text/html; charset=UTF-8'] + $headers);
    }

    /** @param array<string, string> $headers */
    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        return new self(
            $status,
            json_encode($data, self::JSON_FLAGS),
            ['Content-Type' => 'application/json; charset=UTF-8'] + $headers
        );
    }

    /**
     * Erro JSON padrão da API: {"error": {"code", "message", "fields"?}}.
     *
     * @param array<string, string> $fields mensagens por campo (omitido se vazio)
     * @param array<string, string> $headers
     */
    public static function jsonError(string $code, string $message, int $status = 400, array $fields = [], array $headers = []): self
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== []) {
            $error['fields'] = $fields;
        }
        return self::json(['error' => $error], $status, $headers);
    }

    /** @param array<string, string> $headers */
    public static function text(string $body, int $status = 200, array $headers = []): self
    {
        return new self($status, $body, ['Content-Type' => 'text/plain; charset=UTF-8'] + $headers);
    }

    public static function redirect(string $to, int $status = 303): self
    {
        return new self($status, '', ['Location' => $to]);
    }

    public static function noContent(): self
    {
        return new self(204, '');
    }

    public function withHeader(string $name, string $value): self
    {
        $copy = clone $this;
        $copy->setHeader($name, $value);
        return $copy;
    }

    public function withoutHeader(string $name): self
    {
        $copy = clone $this;
        unset($copy->headers[strtolower($name)]);
        return $copy;
    }

    public function withStatus(int $status): self
    {
        $copy = clone $this;
        $copy->status = $status;
        return $copy;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][1] ?? null;
    }

    /** @return array<string, string> nome => valor */
    public function headers(): array
    {
        $all = [];
        foreach ($this->headers as [$name, $value]) {
            $all[$name] = $value;
        }
        return $all;
    }

    /** Envia status, cabeçalhos e corpo. Em HEAD (ou 204/304) o corpo é omitido. */
    public function send(bool $headOnly = false): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            header_remove('X-Powered-By');
            foreach ($this->headers as [$name, $value]) {
                header($name . ': ' . $value, true);
            }
        }
        if ($headOnly || $this->status === 204 || $this->status === 304 || $this->status < 200) {
            return;
        }
        echo $this->body;
    }

    private function setHeader(string $name, string $value): void
    {
        if (preg_match('/^[A-Za-z0-9\-]+$/', $name) !== 1) {
            throw new InvalidArgumentException('Nome de cabeçalho inválido.');
        }
        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('Valor de cabeçalho inválido (contém quebra de linha ou caractere de controle).');
        }
        $this->headers[strtolower($name)] = [$name, $value];
    }
}
