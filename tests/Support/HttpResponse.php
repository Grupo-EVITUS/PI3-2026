<?php
declare(strict_types=1);

namespace Saa\Tests\Support;

/** Resposta HTTP recebida pelo HttpClient (status, cabeçalhos em minúsculas e corpo). */
final class HttpResponse
{
    /**
     * @param array<string, list<string>> $headers nome em minúsculas => valores (na ordem recebida)
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /** @return list<string> */
    public function headerValues(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    public function location(): ?string
    {
        return $this->header('location');
    }

    public function contentType(): string
    {
        return strtolower($this->header('content-type') ?? '');
    }

    /** Linha completa do Set-Cookie do cookie informado (ou null). */
    public function setCookie(string $name): ?string
    {
        foreach ($this->headerValues('set-cookie') as $line) {
            if (str_starts_with($line, $name . '=')) {
                return $line;
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** Texto visível aproximado (sem tags, espaços colapsados) para asserções de conteúdo. */
    public function text(): string
    {
        return trim((string)preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($this->body), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
