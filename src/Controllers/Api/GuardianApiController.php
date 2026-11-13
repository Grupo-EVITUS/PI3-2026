<?php
declare(strict_types=1);

namespace Saa\Controllers\Api;

use Saa\Core\Exceptions\ValidationException;
use Saa\Core\Request;
use Saa\Core\Response;
use Saa\Services\GuardianLinkService;
use Saa\Services\GuardianService;

/**
 * /api/guardians (issue #26): responsáveis com as regras de GuardianService/GuardianLinkService (e-mail único
 * normalizado, telefone com DDD). E-mail repetido responde 409. No cadastro, student_code + relationship
 * (opcionais) já vinculam o responsável a um aluno, como na tela.
 */
final class GuardianApiController extends ApiController
{
    private const EDITABLE = ['name', 'email', 'phone', 'email_status'];

    public function __construct(
        private readonly GuardianService $guardians,
        private readonly GuardianLinkService $links,
    ) {
    }

    /** GET /api/guardians?q=&status=active|archived&page=&per_page= */
    public function index(Request $request): Response
    {
        [$page, $perPage] = $this->paging($request);
        $list = $this->guardians->list($request->string('q'), $this->statusFilter($request), $page, $perPage);
        return $this->paginated($list['items'], $list['pagination']);
    }

    /** POST /api/guardians {name, email, phone, student_code?, relationship?, is_primary?, receives_absence_notifications?} → 201 */
    public function store(Request $request): Response
    {
        try {
            $result = $this->links->createGuardian([
                'name' => $request->string('name'),
                'email' => $request->string('email'),
                'phone' => $request->string('phone'),
                'student_code' => $request->string('student_code'),
                'relationship' => $request->string('relationship'),
                'is_primary' => $request->bool('is_primary'),
                'receives_absence_notifications' => $request->has('receives_absence_notifications') ? $request->bool('receives_absence_notifications', true) : null,
            ]);
        } catch (ValidationException $error) {
            return $this->conflictOr($error, [GuardianService::DUPLICATE_EMAIL]);
        }
        $id = $result['guardian']['id'];
        return $this->created($this->detail($id), '/api/guardians/' . $id);
    }

    /** GET /api/guardians/{id}: contato e alunos vinculados. */
    public function show(Request $request): Response
    {
        return $this->json($this->detail((string)$request->param('id')));
    }

    /** PATCH /api/guardians/{id}: só os campos enviados (name, email, phone, email_status). */
    public function update(Request $request): Response
    {
        $id = (string)$request->param('id');
        $current = $this->guardians->show($id)['guardian'];
        $sent = $this->sentFields($request, self::EDITABLE);
        try {
            $this->guardians->update($id, $sent + [
                'name' => $current['name'],
                'email' => $current['email'],
                'phone' => $current['phone'],
                'email_status' => $current['email_status'],
            ]);
        } catch (ValidationException $error) {
            return $this->conflictOr($error, [GuardianService::DUPLICATE_EMAIL]);
        }
        return $this->json($this->detail($id));
    }

    /** DELETE /api/guardians/{id}: arquiva (deixa de receber avisos; vínculos e histórico ficam) → 204. */
    public function archive(Request $request): Response
    {
        $this->guardians->archive((string)$request->param('id'));
        return Response::noContent();
    }

    /** @return array<string, mixed> */
    private function detail(string $id): array
    {
        $data = $this->guardians->show($id);
        return $data['guardian'] + ['students' => $data['students']];
    }
}
