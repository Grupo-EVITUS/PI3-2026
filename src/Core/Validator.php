<?php
declare(strict_types=1);

namespace Saa\Core;

use Saa\Core\Exceptions\ValidationException;
use Saa\Database\Bson;

/**
 * Validação de entrada com mensagens em pt-BR.
 *
 *   $data = Validator::make($request->all(), ['name' => 'o nome', 'email' => 'o e-mail'])
 *       ->required('name', 'email', 'profile')
 *       ->string('name', 120, 3)
 *       ->email('email')
 *       ->in('profile', Profile::loginValues())
 *       ->validate();            // lança ValidationException (422) se algo falhar
 *
 * As regras (exceto required) ignoram campos em branco: um campo opcional vazio não gera erro.
 * Cada campo guarda apenas a PRIMEIRA mensagem de erro. validated() devolve os valores normalizados
 * (textos sem espaços nas pontas, ids em minúsculas, datas AAAA-MM-DD) dos campos válidos.
 */
final class Validator
{
    private const INVALID = 'Valor inválido.';
    private const BAD_CHARACTERS = 'Contém caracteres inválidos.';

    /** @var array<string, string> */
    private array $errors = [];
    /** @var array<string, mixed> */
    private array $validated = [];

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $labels campo => rótulo com artigo ("o nome"), usado em "Informe o nome."
     */
    private function __construct(private readonly array $data, private readonly array $labels)
    {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $labels
     */
    public static function make(array $data, array $labels = []): self
    {
        return new self($data, $labels);
    }

    public function required(string ...$fields): self
    {
        foreach ($fields as $field) {
            $value = $this->data[$field] ?? null;
            if (self::isBlank($value)) {
                $label = $this->labels[$field] ?? null;
                $this->fail($field, $label !== null ? 'Informe ' . $label . '.' : 'Este campo é obrigatório.');
            } elseif (!isset($this->errors[$field]) && !array_key_exists($field, $this->validated)) {
                $this->validated[$field] = is_string($value) ? trim($value) : $value;
            }
        }
        return $this;
    }

    public function string(string $field, int $max = 255, int $min = 0, bool $multiline = false): self
    {
        return $this->apply($field, static function (mixed $value) use ($max, $min, $multiline): array {
            if (!is_string($value)) {
                return [null, self::INVALID];
            }
            $text = trim($value);
            if (!mb_check_encoding($text, 'UTF-8')) {
                return [null, self::BAD_CHARACTERS];
            }
            if ($multiline) {
                $text = str_replace("\r\n", "\n", $text);
            }
            $forbidden = $multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';
            if (preg_match($forbidden, $text) === 1) {
                return [null, self::BAD_CHARACTERS];
            }
            $length = mb_strlen($text, 'UTF-8');
            if ($length > $max) {
                return [null, 'Use no máximo ' . $max . ' caracteres.'];
            }
            if ($length < $min) {
                return [null, 'Use pelo menos ' . $min . ' caracteres.'];
            }
            return [$text, null];
        });
    }

    public function email(string $field, int $max = 254): self
    {
        return $this->apply($field, static function (mixed $value) use ($max): array {
            if (!is_string($value)) {
                return [null, self::INVALID];
            }
            $email = trim($value);
            if (strlen($email) > $max || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return [null, 'Informe um e-mail válido.'];
            }
            return [$email, null];
        });
    }

    /** @param list<string> $allowed valores permitidos (comparação estrita como texto) */
    public function in(string $field, array $allowed): self
    {
        return $this->apply($field, static function (mixed $value) use ($allowed): array {
            if (is_int($value)) {
                $value = (string)$value;
            }
            if (!is_string($value)) {
                return [null, 'Selecione uma opção válida.'];
            }
            $value = trim($value);
            foreach ($allowed as $option) {
                if ((string)$option === $value) {
                    return [$value, null];
                }
            }
            return [null, 'Selecione uma opção válida.'];
        });
    }

    /** Data de calendário no formato AAAA-MM-DD. */
    public function date(string $field): self
    {
        return $this->apply($field, static function (mixed $value): array {
            $text = is_string($value) ? trim($value) : '';
            return Bson::isDay($text) ? [$text, null] : [null, 'Informe uma data válida.'];
        });
    }

    /** Identificador do MongoDB (24 caracteres hexadecimais). */
    public function objectId(string $field): self
    {
        return $this->apply($field, static function (mixed $value): array {
            $id = Bson::objectId(is_string($value) ? trim($value) : null);
            return $id !== null ? [(string)$id, null] : [null, 'Seleção inválida.'];
        });
    }

    /** Senha nova: mínimo de caracteres e máximo em bytes (o bcrypt ignora o que passa de 72 bytes). Não remove espaços. */
    public function password(string $field, int $min = 10, int $max = 72): self
    {
        return $this->apply($field, static function (mixed $value) use ($min, $max): array {
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
                return [null, self::INVALID];
            }
            if (mb_strlen($value, 'UTF-8') < $min) {
                return [null, 'A senha deve ter pelo menos ' . $min . ' caracteres.'];
            }
            if (strlen($value) > $max) {
                return [null, 'A senha deve ter no máximo ' . $max . ' caracteres.'];
            }
            return [$value, null];
        });
    }

    public function integer(string $field, ?int $min = null, ?int $max = null): self
    {
        return $this->apply($field, static function (mixed $value) use ($min, $max): array {
            if (is_string($value) && preg_match('/^\s*-?\d{1,18}\s*$/', $value) === 1) {
                $value = (int)trim($value);
            }
            if (!is_int($value)) {
                return [null, 'Informe um número inteiro.'];
            }
            if ($min !== null && $value < $min) {
                return [null, 'Informe um valor maior ou igual a ' . $min . '.'];
            }
            if ($max !== null && $value > $max) {
                return [null, 'Informe um valor menor ou igual a ' . $max . '.'];
            }
            return [$value, null];
        });
    }

    /** Registra um erro manual (regra de negócio, ex.: matrícula duplicada). Mantém a primeira mensagem do campo. */
    public function addError(string $field, string $message): self
    {
        $this->fail($field, $message);
        return $this;
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string, string> campo => primeira mensagem */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string, mixed> valores normalizados dos campos sem erro */
    public function validated(): array
    {
        return array_diff_key($this->validated, $this->errors);
    }

    /**
     * @return array<string, mixed> valores normalizados
     * @throws ValidationException se houver erros
     */
    public function validate(): array
    {
        if ($this->errors !== []) {
            throw new ValidationException($this->errors);
        }
        return $this->validated();
    }

    // ------------------------------------------------------------------ internos

    /** @param callable(mixed): array{0: mixed, 1: string|null} $rule */
    private function apply(string $field, callable $rule): self
    {
        if (isset($this->errors[$field])) {
            return $this;
        }
        $value = $this->data[$field] ?? null;
        if (self::isBlank($value)) {
            return $this;
        }
        [$normalized, $error] = $rule($value);
        if ($error !== null) {
            $this->fail($field, $error);
        } else {
            $this->validated[$field] = $normalized;
        }
        return $this;
    }

    private function fail(string $field, string $message): void
    {
        $this->errors[$field] ??= $message;
    }

    private static function isBlank(mixed $value): bool
    {
        return $value === null || $value === [] || (is_string($value) && trim($value) === '');
    }
}
