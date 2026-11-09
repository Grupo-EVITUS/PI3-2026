<?php
declare(strict_types=1);

namespace Saa\Domain;

/**
 * Utilitários comuns a todos os enums de domínio (BackedEnum de string com método label()).
 * Os valores (value) são os códigos gravados no MongoDB; label() é o texto exibido em pt-BR.
 */
trait EnumHelpers
{
    /** @return list<string> códigos gravados no banco (úteis em validators $jsonSchema e na validação de formulários) */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** @return array<string, string> código => rótulo em pt-BR (para <select> e listagens) */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }
        return $options;
    }

    /** Converte qualquer valor em case; devolve null se não for uma string válida (nunca lança). */
    public static function tryFromValue(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }

    /** Rótulo em pt-BR para um código vindo do banco; devolve o próprio código se desconhecido. */
    public static function labelFor(mixed $value): string
    {
        return self::tryFromValue($value)?->label() ?? (is_scalar($value) ? (string)$value : '—');
    }
}
