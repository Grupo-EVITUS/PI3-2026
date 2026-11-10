<?php
declare(strict_types=1);

namespace Saa\Services;

use MongoDB\BSON\ObjectId;
use Saa\Core\Clock;
use Saa\Core\Exceptions\NotFoundException;
use Saa\Database\Bson;

/**
 * Utilitários comuns aos serviços de cadastro (períodos, turmas, alunos, responsáveis e matrículas):
 * paginação, conversão de ids vindos da URL e regras de formato compartilhadas.
 */
final class RegistrySupport
{
    public const PER_PAGE = 20;

    /** Dígitos aceitos no telefone brasileiro com DDD: 10 (fixo) ou 11 (celular). */
    public const PHONE_MESSAGE = 'Informe o telefone com DDD, por exemplo (11) 99999-0001.';

    /** Id da URL/formulário → ObjectId, ou 404 com a mensagem informada. */
    public static function idOrFail(ObjectId|string|null $id, string $message): ObjectId
    {
        return Bson::objectId($id) ?? throw new NotFoundException($message);
    }

    /**
     * @return array{page: int, per_page: int, pages: int, total: int, skip: int}
     */
    public static function pagination(int $page, int $total, int $perPage = self::PER_PAGE): array
    {
        $pages = max(1, (int)ceil($total / max(1, $perPage)));
        $page = max(1, min($page, $pages));
        return ['page' => $page, 'per_page' => $perPage, 'pages' => $pages, 'total' => $total, 'skip' => ($page - 1) * $perPage];
    }

    /** Página pedida (1 se inválida). */
    public static function page(?int $page): int
    {
        return $page !== null && $page > 0 ? min($page, 100000) : 1;
    }

    /** Telefone brasileiro com DDD: só dígitos, espaços, parênteses, hífen e ponto; 10 ou 11 dígitos. */
    public static function isPhone(string $phone): bool
    {
        if (preg_match('/^[0-9()\s.\-]{10,30}$/', $phone) !== 1) {
            return false;
        }
        $digits = (string)preg_replace('/\D/', '', $phone);
        return in_array(strlen($digits), [10, 11], true) && $digits[0] !== '0';
    }

    /**
     * Matrícula escolar (RA) normalizada: maiúsculas e espaços simples. "ra-2026-0001" e "RA-2026-0001" são o mesmo
     * aluno; sem isso, o índice único (sensível a maiúsculas) aceitaria a duplicidade.
     */
    public static function studentCode(string $code): string
    {
        return mb_strtoupper((string)preg_replace('/\s+/u', ' ', trim($code)), 'UTF-8');
    }

    /** Hoje (fuso escolar) como data de calendário AAAA-MM-DD. */
    public static function today(): string
    {
        return Clock::today();
    }

    /** Converte um texto de status de listagem ("archived" ou outro) em active=true/false. */
    public static function activeFilter(?string $status): bool
    {
        return $status !== 'archived';
    }
}
