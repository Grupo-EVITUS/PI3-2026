<?php
declare(strict_types=1);

namespace Saa\Controllers\Api;

use Saa\Core\Exceptions\ValidationException;
use Saa\Core\Request;
use Saa\Core\Response;
use Saa\Core\Validator;
use Saa\Domain\Shift;
use Saa\Services\ClassService;

/**
 * /api/classes (issue #26): turmas por período letivo, com as regras de ClassService. Código repetido no período
 * e arquivar turma com alunos matriculados respondem 409.
 */
final class ClassApiController extends ApiController
{
    private const EDITABLE = ['code', 'name', 'grade', 'shift'];

    public function __construct(private readonly ClassService $classes)
    {
    }

    /** GET /api/classes?period_id=<id>|all&shift=&status=active|archived&page=&per_page= (padrão: período vigente) */
    public function index(Request $request): Response
    {
        [$page, $perPage] = $this->paging($request);
        $status = $this->statusFilter($request);
        $period = $request->string('period_id');
        $validator = Validator::make(['period_id' => $period === 'all' ? null : $period, 'shift' => $request->string('shift')])
            ->objectId('period_id')
            ->in('shift', Shift::values());
        $filters = $validator->validate();
        $list = $this->classes->list($period === 'all' ? 'all' : ($filters['period_id'] ?? null), $filters['shift'] ?? null, $status);
        return $this->paginateList($list['items'], $page, $perPage);
    }

    /** POST /api/classes {academic_period_id, code, name, grade, shift} → 201 */
    public function store(Request $request): Response
    {
        try {
            $class = $this->classes->create([
                'academic_period_id' => $request->string('academic_period_id'),
                'code' => $request->string('code'),
                'name' => $request->string('name'),
                'grade' => $request->string('grade'),
                'shift' => $request->string('shift'),
            ]);
        } catch (ValidationException $error) {
            return $this->conflictOr($error, [ClassService::DUPLICATE_CODE]);
        }
        return $this->created($this->detail($class['id']), '/api/classes/' . $class['id']);
    }

    /** GET /api/classes/{id}: dados da turma, período e alunos com matrícula ativa. */
    public function show(Request $request): Response
    {
        return $this->json($this->detail((string)$request->param('id')));
    }

    /** PATCH /api/classes/{id}: só os campos enviados (code, name, grade, shift; o período não muda). */
    public function update(Request $request): Response
    {
        $id = (string)$request->param('id');
        $current = $this->classes->show($id)['class'];
        $sent = $this->sentFields($request, self::EDITABLE);
        try {
            $this->classes->update($id, $sent + [
                'code' => $current['code'],
                'name' => $current['name'],
                'grade' => $current['grade'],
                'shift' => $current['shift'],
            ]);
        } catch (ValidationException $error) {
            return $this->conflictOr($error, [ClassService::DUPLICATE_CODE]);
        }
        return $this->json($this->detail($id));
    }

    /** DELETE /api/classes/{id}: arquiva → 204; com alunos matriculados → 409. */
    public function archive(Request $request): Response
    {
        try {
            $this->classes->archive((string)$request->param('id'));
        } catch (ValidationException $error) {
            return $this->conflictOr($error, [ClassService::ARCHIVE_BLOCKED]);
        }
        return Response::noContent();
    }

    /** @return array<string, mixed> */
    private function detail(string $id): array
    {
        $data = $this->classes->show($id);
        return $data['class'] + ['students' => $data['students']];
    }
}
