<?php
declare(strict_types=1);

namespace Saa\Controllers;

use Saa\Core\Exceptions\ValidationException;
use Saa\Core\Request;
use Saa\Core\Response;
use Saa\Domain\EmailStatus;
use Saa\Domain\Relationship;
use Saa\Services\GuardianLinkService;
use Saa\Services\GuardianService;

/**
 * Responsáveis (issue #39): GET/POST /guardians, GET/POST /guardians/{id}, POST /guardians/{id}/archive e /reactivate.
 */
final class GuardianController extends Controller
{
    public function __construct(
        private readonly GuardianService $guardians,
        private readonly GuardianLinkService $links,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->listPage($request);
    }

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
                'receives_absence_notifications' => $request->has('receives_absence_notifications') ? $request->bool('receives_absence_notifications') : null,
            ]);
        } catch (ValidationException $error) {
            return $this->listPage($request, ['errors' => $error->errors(), 'old' => $this->oldInput($request), 'openForm' => true], 422);
        }
        $this->flash('success', $result['student_name'] !== null
            ? 'Responsável cadastrado(a) e vinculado(a) a ' . $result['student_name'] . '.'
            : 'Responsável cadastrado(a). Vincule-o(a) a um aluno pela ficha do aluno.');
        return $this->redirect('/guardians/' . $result['guardian']['id']);
    }

    public function show(Request $request): Response
    {
        return $this->showPage($request);
    }

    public function update(Request $request): Response
    {
        $id = (string)$request->param('id');
        try {
            $this->guardians->update($id, [
                'name' => $request->string('name'),
                'email' => $request->string('email'),
                'phone' => $request->string('phone'),
                'email_status' => $request->string('email_status'),
            ]);
        } catch (ValidationException $error) {
            return $this->showPage($request, ['errors' => $error->errors(), 'old' => $this->oldInput($request)], 422);
        }
        $this->flash('success', 'Dados do responsável atualizados.');
        return $this->redirect('/guardians/' . $id);
    }

    public function archive(Request $request): Response
    {
        $id = (string)$request->param('id');
        $this->guardians->archive($id);
        $this->flash('success', 'Cadastro arquivado. Ele deixa de receber avisos, mas os vínculos e o histórico são mantidos.');
        return $this->redirect('/guardians/' . $id);
    }

    public function reactivate(Request $request): Response
    {
        $id = (string)$request->param('id');
        $this->guardians->reactivate($id);
        $this->flash('success', 'Cadastro reativado.');
        return $this->redirect('/guardians/' . $id);
    }

    // ------------------------------------------------------------------ internos

    /** @param array<string, mixed> $extra */
    private function listPage(Request $request, array $extra = [], int $status = 200): Response
    {
        $list = $this->guardians->list($request->string('q'), $request->string('status'), $request->int('page', 1) ?? 1);
        return $this->render('guardians/index', array_replace([
            'title' => 'Responsáveis',
            'eyebrow' => 'Cadastros',
            'activeNav' => '/guardians',
            'guardians' => $list['items'],
            'pagination' => $list['pagination'],
            'filters' => $list['filters'],
            'relationshipOptions' => Relationship::options(),
            'openForm' => false,
        ], $extra), $status);
    }

    /** @param array<string, mixed> $extra */
    private function showPage(Request $request, array $extra = [], int $status = 200): Response
    {
        $data = $this->guardians->show((string)$request->param('id'));
        return $this->render('guardians/show', array_replace([
            'title' => $data['guardian']['name'],
            'eyebrow' => 'Responsáveis',
            'activeNav' => '/guardians',
            'guardian' => $data['guardian'],
            'students' => $data['students'],
            'emailStatusOptions' => EmailStatus::options(),
        ], $extra), $status);
    }
}
