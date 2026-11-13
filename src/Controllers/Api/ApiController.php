<?php
declare(strict_types=1);

namespace Saa\Controllers\Api;

use Saa\Controllers\Controller;
use Saa\Core\Exceptions\ValidationException;
use Saa\Core\Request;
use Saa\Core\Response;
use Saa\Core\Validator;

/**
 * Base dos controllers da API JSON (docs/ARQUITETURA.md, seção 10, issue #26).
 *
 * Mesma sessão e mesmas permissões das telas (o Kernel confere sessão, X-CSRF-Token e permissão antes de chegar
 * aqui). Sucesso: {"data": ..., "meta": {...}}; listas com meta.page, meta.per_page (padrão 20, máx. 100),
 * meta.total e meta.pages. Erros saem do Kernel no formato {"error": {"code", "message", "fields"?}}; aqui só se
 * decide quando uma regra de duplicidade vira 409 em vez de 422.
 */
abstract class ApiController extends Controller
{
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE = 100;

    /**
     * Página e itens por página da query string (page ≥ 1; per_page de 1 a 100, padrão 20).
     *
     * @return array{0: int, 1: int}
     * @throws ValidationException valores inválidos (422)
     */
    protected function paging(Request $request): array
    {
        $data = Validator::make(['page' => $request->query('page'), 'per_page' => $request->query('per_page')])
            ->integer('page', 1, 100000)
            ->integer('per_page', 1, self::MAX_PER_PAGE)
            ->validate();
        return [(int)($data['page'] ?? 1), (int)($data['per_page'] ?? self::DEFAULT_PER_PAGE)];
    }

    /**
     * Lista paginada: {"data": [...], "meta": {"page", "per_page", "total", "pages"}}.
     *
     * @param list<mixed> $items
     * @param array{page: int, per_page: int, total: int, pages?: int} $pagination
     */
    protected function paginated(array $items, array $pagination): Response
    {
        return $this->json($items, 200, [
            'page' => $pagination['page'],
            'per_page' => $pagination['per_page'],
            'total' => $pagination['total'],
            'pages' => $pagination['pages'] ?? max(1, (int)ceil($pagination['total'] / max(1, $pagination['per_page']))),
        ]);
    }

    /**
     * Pagina uma lista que o serviço já devolve inteira (períodos, turmas, histórico): mesma página fora do intervalo
     * vai para a última, como nas telas.
     *
     * @param list<mixed> $items
     */
    protected function paginateList(array $items, int $page, int $perPage): Response
    {
        $total = count($items);
        $pages = max(1, (int)ceil($total / max(1, $perPage)));
        $page = max(1, min($page, $pages));
        return $this->paginated(array_slice($items, ($page - 1) * $perPage, $perPage), [
            'page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => $pages,
        ]);
    }

    /** Criação: 201 com Location do recurso novo. */
    protected function created(mixed $data, string $location): Response
    {
        return $this->json($data, 201)->withHeader('Location', $location);
    }

    /**
     * Converte em 409 (code "conflict", com os campos) a validação que falhou por duplicidade; os demais erros
     * continuam 422 (a exceção é relançada para o Kernel).
     *
     * @param list<string> $conflicts mensagens do serviço que indicam duplicidade
     * @throws ValidationException
     */
    protected function conflictOr(ValidationException $error, array $conflicts): Response
    {
        foreach ($error->errors() as $message) {
            if (in_array($message, $conflicts, true)) {
                return $this->jsonError('conflict', $message, 409, $error->errors());
            }
        }
        throw $error;
    }

    /**
     * Campos realmente enviados no corpo (PATCH parcial), lidos pelos getters tipados (arrays viram null e o
     * serviço recusa). Corpo sem nenhum campo conhecido → 422.
     *
     * @param list<string> $fields campos aceitos
     * @return array<string, string|null>
     * @throws ValidationException
     */
    protected function sentFields(Request $request, array $fields): array
    {
        $body = $request->body();
        $sent = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $body)) {
                $sent[$field] = $request->string($field);
            }
        }
        if ($sent === []) {
            throw new ValidationException(
                ['body' => 'Envie pelo menos um destes campos (JSON): ' . implode(', ', $fields) . '.'],
                'Nenhum campo para alterar.'
            );
        }
        return $sent;
    }

    /** Situação de cadastro pedida na listagem: active (padrão) ou archived. @throws ValidationException */
    protected function statusFilter(Request $request): string
    {
        $data = Validator::make(['status' => $request->string('status')])->in('status', ['active', 'archived'])->validate();
        return (string)($data['status'] ?? 'active');
    }
}
