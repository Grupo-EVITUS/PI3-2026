<?php
declare(strict_types=1);

namespace Saa\Controllers;

use Saa\Core\Exceptions\ValidationException;
use Saa\Core\Request;
use Saa\Core\Response;
use Saa\Domain\Shift;
use Saa\Services\AcademicPeriodService;
use Saa\Services\ClassService;

/**
 * Turmas (issue #40): GET/POST /classes, GET/POST /classes/{id}, POST /classes/{id}/students (associar aluno —
 * issue #41) e POST /classes/{id}/archive.
 */
final class ClassController extends Controller
{
    public function __construct(
        private readonly ClassService $classes,
        private readonly AcademicPeriodService $periods,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->listPage($request);
    }

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
            return $this->listPage($request, ['errors' => $error->errors(), 'old' => $this->oldInput($request), 'openForm' => true], 422);
        }
        $this->flash('success', 'Turma ' . $class['code'] . ' cadastrada.');
        return $this->redirect('/classes/' . $class['id']);
    }

    public function show(Request $request): Response
    {
        return $this->showPage($request);
    }

    public function update(Request $request): Response
    {
        $id = (string)$request->param('id');
        try {
            $class = $this->classes->update($id, [
                'code' => $request->string('code'),
                'name' => $request->string('name'),
                'grade' => $request->string('grade'),
                'shift' => $request->string('shift'),
            ]);
        } catch (ValidationException $error) {
            return $this->showPage($request, ['errors' => $error->errors(), 'old' => $this->oldInput($request), 'activeForm' => 'edit'], 422);
        }
        $this->flash('success', 'Turma ' . $class['code'] . ' atualizada.');
        return $this->redirect('/classes/' . $id);
    }

    public function enroll(Request $request): Response
    {
        $id = (string)$request->param('id');
        try {
            $result = $this->classes->enrollStudent($id, $request->string('student_id'));
        } catch (ValidationException $error) {
            return $this->showPage($request, ['errors' => $error->errors(), 'old' => $this->oldInput($request), 'activeForm' => 'enroll'], 422);
        }
        $this->flash('success', $result['changed']
            ? $result['student_name'] . ' foi transferido(a) para esta turma. A matrícula anterior foi encerrada e continua no histórico.'
            : $result['student_name'] . ' foi matriculado(a) nesta turma.');
        return $this->redirect('/classes/' . $id);
    }

    public function archive(Request $request): Response
    {
        $id = (string)$request->param('id');
        try {
            $this->classes->archive($id);
        } catch (ValidationException $error) {
            return $this->showPage($request, ['formError' => $error->getMessage()], 422);
        }
        $this->flash('success', 'Turma arquivada. Ela continua no histórico e nas consultas de turmas arquivadas.');
        return $this->redirect('/classes');
    }

    // ------------------------------------------------------------------ internos

    /** @param array<string, mixed> $extra */
    private function listPage(Request $request, array $extra = [], int $status = 200): Response
    {
        $list = $this->classes->list(
            $request->string('period'),
            $request->string('shift'),
            $request->string('status')
        );
        $active = $this->periods->active();
        return $this->render('classes/index', [
            'title' => 'Turmas',
            'eyebrow' => 'Cadastros',
            'activeNav' => '/classes',
            'classes' => $list['items'],
            'filters' => $list['filters'],
            'periodOptions' => $this->periods->options(),
            'activePeriodId' => $active['id'] ?? null,
            'shiftOptions' => Shift::options(),
        ] + $extra, $status);
    }

    /** @param array<string, mixed> $extra */
    private function showPage(Request $request, array $extra = [], int $status = 200): Response
    {
        $term = $request->method() === 'GET' ? $request->string('student_q') : null;
        $data = $this->classes->show((string)$request->param('id'), $term);
        return $this->render('classes/show', array_replace([
            'title' => 'Turma ' . $data['class']['code'],
            'eyebrow' => 'Turmas',
            'activeNav' => '/classes',
            'class' => $data['class'],
            'students' => $data['students'],
            'candidates' => $data['candidates'],
            'studentTerm' => mb_substr((string)$term, 0, 100),
            'shiftOptions' => Shift::options(),
            'activeForm' => null,
            'formError' => null,
        ], $extra), $status);
    }
}
