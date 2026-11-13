<?php
declare(strict_types=1);

namespace Saa\Controllers\Api;

use Saa\Core\Exceptions\NotFoundException;
use Saa\Core\Exceptions\ValidationException;
use Saa\Core\Request;
use Saa\Core\Response;
use Saa\Core\Validator;
use Saa\Services\EnrollmentService;
use Saa\Services\StudentService;

/**
 * /api/enrollments (issues #26 e #41): histórico de matrículas do aluno e matrícula/troca de turma. Trocar de turma
 * encerra a matrícula atual (CLASS_CHANGE) e cria outra — o histórico é preservado. Matricular de novo na mesma
 * turma responde 409.
 */
final class EnrollmentApiController extends ApiController
{
    public function __construct(private readonly StudentService $students)
    {
    }

    /** GET /api/enrollments?student_id=<id>: histórico (mais recente primeiro). */
    public function index(Request $request): Response
    {
        [$page, $perPage] = $this->paging($request);
        $studentId = $this->studentId($request);
        return $this->paginateList($this->students->show($studentId)['history'], $page, $perPage);
    }

    /** POST /api/enrollments {student_id, class_id} → 201 com a matrícula ativa e "changed" (true = troca de turma). */
    public function store(Request $request): Response
    {
        $studentId = $this->studentId($request);
        try {
            $changed = $this->students->changeClass($studentId, $request->string('class_id'));
        } catch (NotFoundException) {
            throw new ValidationException(['student_id' => 'Aluno não encontrado.']);
        } catch (ValidationException $error) {
            return $this->conflictOr($error, [EnrollmentService::ALREADY_ENROLLED]);
        }
        $history = $this->students->show($studentId)['history'];
        $active = null;
        foreach ($history as $item) {
            if ($item['active']) {
                $active = $item;
                break;
            }
        }
        return $this->created(
            ['enrollment' => $active, 'changed' => $changed, 'history' => $history],
            '/api/enrollments?student_id=' . $studentId
        );
    }

    /** @throws ValidationException student_id ausente ou malformado */
    private function studentId(Request $request): string
    {
        $data = Validator::make(['student_id' => $request->string('student_id')], ['student_id' => 'o aluno (student_id)'])
            ->required('student_id')
            ->objectId('student_id')
            ->validate();
        return (string)$data['student_id'];
    }
}
