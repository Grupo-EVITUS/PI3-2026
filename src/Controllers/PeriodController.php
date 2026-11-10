<?php
declare(strict_types=1);

namespace Saa\Controllers;

use Saa\Core\Exceptions\ValidationException;
use Saa\Core\Request;
use Saa\Core\Response;
use Saa\Services\AcademicPeriodService;

/** Períodos letivos: GET/POST /periods, GET/POST /periods/{id} e POST /periods/{id}/activate. */
final class PeriodController extends Controller
{
    public function __construct(private readonly AcademicPeriodService $periods)
    {
    }

    public function index(Request $request): Response
    {
        return $this->listPage();
    }

    public function store(Request $request): Response
    {
        try {
            $period = $this->periods->create(self::input($request));
        } catch (ValidationException $error) {
            return $this->listPage(['errors' => $error->errors(), 'old' => $this->oldInput($request), 'openForm' => true], 422);
        }
        $this->flash('success', $period['active']
            ? 'Período letivo "' . $period['name'] . '" cadastrado e definido como ativo.'
            : 'Período letivo "' . $period['name'] . '" cadastrado.');
        return $this->redirect('/periods');
    }

    public function edit(Request $request): Response
    {
        return $this->editPage($this->periods->get((string)$request->param('id')));
    }

    public function update(Request $request): Response
    {
        $period = $this->periods->get((string)$request->param('id'));
        try {
            $updated = $this->periods->update($period['id'], self::input($request));
        } catch (ValidationException $error) {
            return $this->editPage($period, ['errors' => $error->errors(), 'old' => $this->oldInput($request)], 422);
        }
        $this->flash('success', 'Período letivo "' . $updated['name'] . '" atualizado.');
        return $this->redirect('/periods');
    }

    public function activate(Request $request): Response
    {
        $period = $this->periods->activate((string)$request->param('id'));
        $this->flash('success', 'O período "' . $period['name'] . '" agora é o período letivo ativo.');
        return $this->redirect('/periods');
    }

    // ------------------------------------------------------------------ internos

    /** @return array<string, string|null> */
    private static function input(Request $request): array
    {
        return [
            'name' => $request->string('name'),
            'start_date' => $request->string('start_date'),
            'end_date' => $request->string('end_date'),
            'min_attendance_percent' => $request->string('min_attendance_percent'),
            'alert_below_percent' => $request->string('alert_below_percent'),
        ];
    }

    /** @param array<string, mixed> $extra */
    private function listPage(array $extra = [], int $status = 200): Response
    {
        return $this->render('periods/index', [
            'title' => 'Períodos letivos',
            'eyebrow' => 'Cadastros',
            'activeNav' => '/periods',
            'periods' => $this->periods->list(),
        ] + $extra, $status);
    }

    /**
     * @param array<string, mixed> $period
     * @param array<string, mixed> $extra
     */
    private function editPage(array $period, array $extra = [], int $status = 200): Response
    {
        return $this->render('periods/edit', [
            'title' => 'Período ' . $period['name'],
            'eyebrow' => 'Períodos letivos',
            'activeNav' => '/periods',
            'period' => $period,
        ] + $extra, $status);
    }
}
