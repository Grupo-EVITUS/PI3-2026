<?php
declare(strict_types=1);

namespace Saa\Controllers;

use Saa\Core\Exceptions\ValidationException;
use Saa\Core\Request;
use Saa\Core\Response;
use Saa\Domain\Relationship;
use Saa\Services\AcademicPeriodService;
use Saa\Services\EnrollmentService;
use Saa\Services\GuardianLinkService;
use Saa\Services\StudentService;

/**
 * Alunos (issue #38), vínculos com responsáveis (issue #39) e troca de turma (issue #41):
 * GET/POST /students, GET/POST /students/{id}, /archive, /reactivate, /guardians, /guardians/{gid}[/remove], /enrollment.
 */
final class StudentController extends Controller
{
    public function __construct(
        private readonly StudentService $students,
        private readonly GuardianLinkService $links,
        private readonly EnrollmentService $enrollments,
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
            $student = $this->students->create([
                'name' => $request->string('name'),
                'student_code' => $request->string('student_code'),
                'birth_date' => $request->string('birth_date'),
                'class_id' => $request->string('class_id'),
            ]);
        } catch (ValidationException $error) {
            return $this->listPage($request, ['errors' => $error->errors(), 'old' => $this->oldInput($request), 'openForm' => true], 422);
        }
        $this->flash('success', 'Aluno(a) ' . $student['name'] . ' cadastrado(a) e matriculado(a) na turma. Agora vincule os responsáveis.');
        return $this->redirect('/students/' . $student['id']);
    }

    public function show(Request $request): Response
    {
        return $this->showPage($request);
    }

    public function update(Request $request): Response
    {
        $id = (string)$request->param('id');
        try {
            $this->students->update($id, [
                'name' => $request->string('name'),
                'student_code' => $request->string('student_code'),
                'birth_date' => $request->string('birth_date'),
            ]);
        } catch (ValidationException $error) {
            return $this->failed($request, $error, 'edit');
        }
        $this->flash('success', 'Dados do aluno atualizados.');
        return $this->redirect('/students/' . $id);
    }

    public function archive(Request $request): Response
    {
        $id = (string)$request->param('id');
        $this->students->archive($id);
        $this->flash('success', 'Cadastro arquivado. A matrícula foi encerrada e o histórico continua disponível.');
        return $this->redirect('/students/' . $id);
    }

    public function reactivate(Request $request): Response
    {
        $id = (string)$request->param('id');
        $this->students->reactivate($id);
        $this->flash('success', 'Cadastro reativado. Matricule o aluno em uma turma do período ativo.');
        return $this->redirect('/students/' . $id);
    }

    public function linkGuardian(Request $request): Response
    {
        $id = (string)$request->param('id');
        $mode = $request->string('mode') === 'new' ? 'new' : 'existing';
        $options = [
            'relationship' => $request->string('relationship'),
            'is_primary' => $request->bool('is_primary'),
            'receives_absence_notifications' => $request->has('receives_absence_notifications') ? $request->bool('receives_absence_notifications') : null,
        ];
        try {
            if ($mode === 'new') {
                $guardian = $this->links->linkNew($id, $options + [
                    'guardian_name' => $request->string('guardian_name'),
                    'guardian_email' => $request->string('guardian_email'),
                    'guardian_phone' => $request->string('guardian_phone'),
                ]);
            } else {
                $guardian = $this->links->linkExisting($id, $options + ['guardian_id' => $request->string('guardian_id')]);
            }
        } catch (ValidationException $error) {
            return $this->failed($request, $error, $mode === 'new' ? 'link-new' : 'link-existing');
        }
        $this->flash('success', 'Responsável ' . $guardian['name'] . ' vinculado(a) ao aluno.');
        return $this->redirect('/students/' . $id);
    }

    public function updateGuardian(Request $request): Response
    {
        $id = (string)$request->param('id');
        $gid = (string)$request->param('gid');
        try {
            $this->links->updateLink($id, $gid, [
                'relationship' => $request->string('relationship'),
                'is_primary' => $request->bool('is_primary'),
                'receives_absence_notifications' => $request->bool('receives_absence_notifications'),
            ]);
        } catch (ValidationException $error) {
            return $this->failed($request, $error, 'link-' . $gid);
        }
        $this->flash('success', 'Vínculo com o responsável atualizado.');
        return $this->redirect('/students/' . $id);
    }

    public function removeGuardian(Request $request): Response
    {
        $id = (string)$request->param('id');
        $this->links->unlink($id, (string)$request->param('gid'));
        $this->flash('success', 'Vínculo removido. O cadastro do responsável continua disponível.');
        return $this->redirect('/students/' . $id);
    }

    public function changeClass(Request $request): Response
    {
        $id = (string)$request->param('id');
        try {
            $changed = $this->students->changeClass($id, $request->string('class_id'));
        } catch (ValidationException $error) {
            return $this->failed($request, $error, 'enrollment');
        }
        $this->flash('success', $changed
            ? 'Turma alterada. A matrícula anterior foi encerrada (mudança de turma) e continua no histórico.'
            : 'Aluno matriculado na turma.');
        return $this->redirect('/students/' . $id);
    }

    // ------------------------------------------------------------------ internos

    /** Re-renderiza a ficha com status 422, as mensagens no formulário que falhou e os valores digitados. */
    private function failed(Request $request, ValidationException $error, string $form): Response
    {
        return $this->showPage($request, [
            'errors' => $error->errors(),
            'old' => $this->oldInput($request),
            'activeForm' => $form,
        ], 422);
    }

    /** @param array<string, mixed> $extra */
    private function listPage(Request $request, array $extra = [], int $status = 200): Response
    {
        $list = $this->students->list(
            $request->string('q'),
            $request->string('class'),
            $request->string('status'),
            $request->int('page', 1) ?? 1
        );
        return $this->render('students/index', array_replace([
            'title' => 'Alunos',
            'eyebrow' => 'Cadastros',
            'activeNav' => '/students',
            'students' => $list['items'],
            'pagination' => $list['pagination'],
            'filters' => $list['filters'],
            'classOptions' => $this->enrollments->classOptions(),
            'activePeriod' => $this->periods->active(),
            'openForm' => false,
        ], $extra), $status);
    }

    /** @param array<string, mixed> $extra */
    private function showPage(Request $request, array $extra = [], int $status = 200): Response
    {
        $term = $request->method() === 'GET' ? $request->string('guardian_q') : null;
        $data = $this->students->show((string)$request->param('id'), $term);
        return $this->render('students/show', array_replace([
            'title' => $data['student']['name'],
            'eyebrow' => 'Alunos',
            'activeNav' => '/students',
            'student' => $data['student'],
            'guardians' => $data['guardians'],
            'history' => $data['history'],
            'candidates' => $data['candidates'],
            'guardianTerm' => mb_substr((string)$term, 0, 100),
            'classOptions' => $this->enrollments->classOptions(),
            'relationshipOptions' => Relationship::options(),
            'maxGuardians' => GuardianLinkService::MAX_LINKS,
            'activeForm' => null,
        ], $extra), $status);
    }
}
