<?php
declare(strict_types=1);

namespace Saa\Tests\Acceptance\Registry;

use Saa\Database\Collections;
use Saa\Database\Mongo;

/** Fluxos HTTP de períodos e turmas (issue #40), associação de alunos (issue #41) e responsáveis (issue #39). */
final class ClassGuardianFlowTest extends RegistryHttpTestCase
{
    public function testIssue40PeriodAndClassAreCreatedThroughTheForms(): void
    {
        $this->signIn('AOE', 'aoe@escola.local');

        $empty = $this->http->get('/periods');
        self::assertStringContainsString('Nenhum período letivo cadastrado', $empty->text());

        $invalid = $this->send('/periods', ['name' => '2026', 'start_date' => '2026-12-01', 'end_date' => '2026-02-01', 'min_attendance_percent' => '75', 'alert_below_percent' => '70']);
        self::assertSame(422, $invalid->status);
        self::assertStringContainsString('A data de término deve ser depois da data de início.', $invalid->text());
        self::assertStringContainsString('O limite de alerta não pode ser menor que a frequência mínima.', $invalid->text());

        $period = $this->send('/periods', ['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'min_attendance_percent' => '75', 'alert_below_percent' => '80']);
        self::assertSame(303, $period->status);
        self::assertStringContainsString('definido como ativo', $this->http->get('/periods')->text());
        $periodId = (string)Mongo::collection(Collections::ACADEMIC_PERIODS)->findOne([])['_id'];

        $class = $this->send('/classes', ['academic_period_id' => $periodId, 'code' => '6a', 'name' => '6º Ano A', 'grade' => '6º ano', 'shift' => 'AFTERNOON']);
        self::assertSame(303, $class->status, $class->text());
        $classPage = $this->http->get((string)$class->location())->text();
        self::assertStringContainsString('6A — 6º Ano A', $classPage);
        self::assertStringContainsString('Tarde', $classPage);

        $duplicate = $this->send('/classes', ['academic_period_id' => $periodId, 'code' => '6A', 'name' => 'Outra', 'grade' => '6º ano', 'shift' => 'MORNING']);
        self::assertSame(422, $duplicate->status);
        self::assertStringContainsString('Já existe uma turma com este código neste período letivo.', $duplicate->text());

        $list = $this->http->get('/classes?shift=AFTERNOON')->text();
        self::assertStringContainsString('6º Ano A', $list);
        self::assertStringContainsString('Nenhuma turma encontrada', $this->http->get('/classes?shift=NIGHT')->text());
    }

    public function testIssue41StudentIsAssociatedFromTheClassPageAndArchiveIsRefusedWhileHeIsThere(): void
    {
        $period = $this->seedPeriod();
        $a = $this->seedClass($period['id'], '6A');
        $b = $this->seedClass($period['id'], '6B');
        $student = $this->seedStudent($a['id']);
        $this->signIn('SECRETARY');

        $search = $this->http->get('/classes/' . $b['id'] . '?student_q=' . rawurlencode('beatriz'));
        self::assertStringContainsString('value="' . $student['id'] . '"', $search->body);
        self::assertStringContainsString('hoje na turma 6A', $search->text());

        $moved = $this->send('/classes/' . $b['id'] . '/students', ['student_id' => $student['id']]);
        self::assertSame(303, $moved->status);
        $page = $this->http->get('/classes/' . $b['id'])->text();
        self::assertStringContainsString('transferido(a) para esta turma', $page);
        self::assertStringContainsString('Ana Beatriz Souza', $page);

        $refused = $this->send('/classes/' . $b['id'] . '/archive');
        self::assertSame(422, $refused->status);
        self::assertStringContainsString('Transfira os alunos antes de arquivar a turma.', $refused->text());

        $archived = $this->send('/classes/' . $a['id'] . '/archive');
        self::assertSame(303, $archived->status);
        self::assertSame('/classes', $archived->location());
        self::assertStringContainsString('6A', $this->http->get('/classes?status=archived')->text());
        self::assertFalse(Mongo::collection(Collections::CLASSES)->findOne(['code' => '6A'])['active']);
    }

    public function testIssue39GuardianIsCreatedLinkedByStudentCodeEditedAndArchived(): void
    {
        $period = $this->seedPeriod();
        $class = $this->seedClass($period['id']);
        $this->seedStudent($class['id'], 'RA-001');
        $this->seedGuardian('existe@exemplo.com', 'Já Existe');
        $this->signIn('SECRETARY');

        $duplicate = $this->send('/guardians', ['name' => 'Outro', 'email' => 'EXISTE@exemplo.com', 'phone' => '(11) 3333-4444']);
        self::assertSame(422, $duplicate->status);
        self::assertStringContainsString('Já existe um responsável com este e-mail.', $duplicate->text());

        $created = $this->send('/guardians', [
            'name' => 'Carlos Souza', 'email' => 'carlos@exemplo.com', 'phone' => '(11) 99999-0001',
            'student_code' => 'RA-001', 'relationship' => 'PAI', 'receives_absence_notifications' => '1',
        ]);
        self::assertSame(303, $created->status, $created->text());
        $id = self::idFromLocation($created);
        $page = $this->http->get('/guardians/' . $id)->text();
        self::assertStringContainsString('vinculado(a) a Ana Beatriz Souza', $page);
        self::assertStringContainsString('Ana Beatriz Souza', $page);
        self::assertStringContainsString('Principal', $page);

        $list = $this->http->get('/guardians?q=carlos')->text();
        self::assertStringContainsString('Carlos Souza', $list);
        self::assertStringNotContainsString('Já Existe', $list);

        $edited = $this->send('/guardians/' . $id, ['name' => 'Carlos Souza', 'email' => 'carlos@exemplo.com', 'phone' => '(11) 99999-0001', 'email_status' => 'BOUNCED']);
        self::assertSame(303, $edited->status);
        self::assertStringContainsString('Retornou com erro', $this->http->get('/guardians/' . $id)->text());

        $badPhone = $this->send('/guardians/' . $id, ['name' => 'Carlos Souza', 'email' => 'carlos@exemplo.com', 'phone' => '123', 'email_status' => 'VALID']);
        self::assertSame(422, $badPhone->status);
        self::assertStringContainsString('Informe o telefone com DDD', $badPhone->text());

        self::assertSame(303, $this->send('/guardians/' . $id . '/archive')->status);
        self::assertStringContainsString('Cadastro arquivado', $this->http->get('/guardians/' . $id)->text());
        self::assertStringContainsString('Carlos Souza', $this->http->get('/guardians?status=archived')->text());
    }
}
