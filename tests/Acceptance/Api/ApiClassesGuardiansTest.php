<?php
declare(strict_types=1);

namespace Saa\Tests\Acceptance\Api;

/**
 * /api/periods, /api/classes, /api/guardians e /api/enrollments (issues #26, #39, #40 e #41): CRUD com
 * 201/200/204/404/422/409, arquivamento e troca de turma preservando o histórico.
 */
final class ApiClassesGuardiansTest extends ApiTestCase
{
    /** @var array<string, mixed> */
    private array $period = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->period = $this->seedPeriod();
        $this->apiSignIn('AOE', 'aoe@escola.local');
    }

    public function testIssue26PeriodsAreListedWithThePolicy(): void
    {
        $response = $this->getJson('/api/periods');

        self::assertSame(200, $response->status);
        self::assertSame(['page' => 1, 'per_page' => 20, 'total' => 1, 'pages' => 1], $response->json()['meta']);
        $period = $response->json()['data'][0];
        self::assertSame($this->period['id'], $period['id']);
        self::assertTrue($period['active']);
        self::assertMatchesRegularExpression('/^\d{4}-01-01$/', $period['start_date']);
        self::assertSame(75, $period['min_attendance_percent']);
    }

    public function testIssue26ClassCrud(): void
    {
        $created = $this->sendJson('POST', '/api/classes', [
            'academic_period_id' => $this->period['id'], 'code' => '6a', 'name' => '6º Ano A', 'grade' => '6º ano', 'shift' => 'MORNING',
        ]);
        self::assertSame(201, $created->status, $created->body);
        $class = $created->json()['data'];
        self::assertSame('/api/classes/' . $class['id'], $created->location());
        self::assertSame('6A', $class['code']);
        self::assertSame($this->period['id'], $class['academic_period_id']);
        self::assertSame([], $class['students']);

        $duplicate = $this->sendJson('POST', '/api/classes', [
            'academic_period_id' => $this->period['id'], 'code' => '6A', 'name' => 'Outra', 'grade' => '6º ano', 'shift' => 'AFTERNOON',
        ]);
        self::assertError($duplicate, 409, 'conflict', ['code']);

        $invalid = $this->sendJson('POST', '/api/classes', ['academic_period_id' => str_repeat('e', 24), 'code' => '', 'name' => 'X', 'grade' => 'X', 'shift' => 'MADRUGADA']);
        self::assertError($invalid, 422, 'validation_error', ['academic_period_id', 'code', 'shift']);

        $patched = $this->sendJson('PATCH', '/api/classes/' . $class['id'], ['shift' => 'AFTERNOON']);
        self::assertSame(200, $patched->status, $patched->body);
        self::assertSame('AFTERNOON', $patched->json()['data']['shift']);
        self::assertSame('6º Ano A', $patched->json()['data']['name'], 'campo não enviado fica igual');

        $list = $this->getJson('/api/classes?shift=AFTERNOON')->json();
        self::assertSame([$class['id']], array_column($list['data'], 'id'));
        self::assertSame(0, $this->getJson('/api/classes?shift=NIGHT')->json()['meta']['total']);
        self::assertSame(1, $this->getJson('/api/classes?period_id=all')->json()['meta']['total']);
        self::assertError($this->getJson('/api/classes?period_id=xyz'), 422, 'validation_error', ['period_id']);

        self::assertError($this->getJson('/api/classes/' . str_repeat('f', 24)), 404, 'not_found');
    }

    public function testIssue26ArchivingAClassWithStudentsIs409AndEmptyClassIs204(): void
    {
        $busy = $this->seedClass($this->period['id'], '6A');
        $empty = $this->seedClass($this->period['id'], '7B');
        $student = $this->seedStudent($busy['id']);

        $blocked = $this->sendJson('DELETE', '/api/classes/' . $busy['id']);
        self::assertError($blocked, 409, 'conflict', ['class']);
        self::assertSame('Transfira os alunos antes de arquivar a turma.', $blocked->json()['error']['message']);
        $show = $this->getJson('/api/classes/' . $busy['id'])->json()['data'];
        self::assertTrue($show['active']);
        self::assertSame([$student['id']], array_column($show['students'], 'id'));

        self::assertSame(204, $this->sendJson('DELETE', '/api/classes/' . $empty['id'])->status);
        self::assertFalse($this->getJson('/api/classes/' . $empty['id'])->json()['data']['active']);
        self::assertSame([$empty['id']], array_column($this->getJson('/api/classes?status=archived')->json()['data'], 'id'));
    }

    public function testIssue26GuardianCrudWithDuplicateEmail409(): void
    {
        $class = $this->seedClass($this->period['id']);
        $student = $this->seedStudent($class['id'], 'RA-001');

        $created = $this->sendJson('POST', '/api/guardians', [
            'name' => 'Carlos Souza', 'email' => 'Carlos.Souza@Exemplo.com', 'phone' => '(11) 99999-0001',
            'student_code' => 'ra-001', 'relationship' => 'PAI',
        ]);
        self::assertSame(201, $created->status, $created->body);
        $guardian = $created->json()['data'];
        self::assertSame('/api/guardians/' . $guardian['id'], $created->location());
        self::assertSame('VALID', $guardian['email_status']);
        self::assertSame($student['id'], $guardian['students'][0]['id'], 'vinculado pela matrícula escolar');
        self::assertTrue($guardian['students'][0]['receives_absence_notifications']);
        self::assertSame('PAI', $this->getJson('/api/students/' . $student['id'])->json()['data']['guardians'][0]['relationship']);

        $duplicate = $this->sendJson('POST', '/api/guardians', ['name' => 'Outro', 'email' => 'carlos.souza@exemplo.com', 'phone' => '(11) 3333-4444']);
        self::assertError($duplicate, 409, 'conflict', ['email']);

        $invalid = $this->sendJson('POST', '/api/guardians', ['name' => 'X', 'email' => 'sem-arroba', 'phone' => '123']);
        self::assertError($invalid, 422, 'validation_error', ['name', 'email', 'phone']);

        $other = $this->seedGuardian('marina@exemplo.com', 'Marina Souza');
        $patched = $this->sendJson('PATCH', '/api/guardians/' . $guardian['id'], ['email_status' => 'BOUNCED']);
        self::assertSame(200, $patched->status, $patched->body);
        self::assertSame('BOUNCED', $patched->json()['data']['email_status']);
        self::assertSame('Carlos.Souza@Exemplo.com', $patched->json()['data']['email'], 'campo não enviado fica igual');
        self::assertError($this->sendJson('PATCH', '/api/guardians/' . $other['id'], ['email' => 'CARLOS.SOUZA@exemplo.com']), 409, 'conflict', ['email']);

        $search = $this->getJson('/api/guardians?q=marina')->json();
        self::assertSame([$other['id']], array_column($search['data'], 'id'));

        self::assertSame(204, $this->sendJson('DELETE', '/api/guardians/' . $other['id'])->status);
        self::assertFalse($this->getJson('/api/guardians/' . $other['id'])->json()['data']['active']);
        self::assertSame(1, $this->getJson('/api/guardians')->json()['meta']['total']);
        self::assertError($this->sendJson('DELETE', '/api/guardians/' . str_repeat('a', 24)), 404, 'not_found');
    }

    public function testIssue26EnrollmentChangeKeepsTheHistory(): void
    {
        $from = $this->seedClass($this->period['id'], '6A');
        $to = $this->seedClass($this->period['id'], '7B');
        $student = $this->seedStudent($from['id']);

        $changed = $this->sendJson('POST', '/api/enrollments', ['student_id' => $student['id'], 'class_id' => $to['id']]);
        self::assertSame(201, $changed->status, $changed->body);
        $data = $changed->json()['data'];
        self::assertTrue($data['changed']);
        self::assertSame($to['id'], $data['enrollment']['class_id']);
        self::assertTrue($data['enrollment']['active']);
        self::assertCount(2, $data['history']);

        $history = $this->getJson('/api/enrollments?student_id=' . $student['id']);
        self::assertSame(2, $history->json()['meta']['total']);
        $ended = array_values(array_filter($history->json()['data'], static fn (array $item): bool => !$item['active']));
        self::assertSame($from['id'], $ended[0]['class_id']);
        self::assertSame('CLASS_CHANGE', $ended[0]['end_reason']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $ended[0]['end_date']);

        $again = $this->sendJson('POST', '/api/enrollments', ['student_id' => $student['id'], 'class_id' => $to['id']]);
        self::assertError($again, 409, 'conflict', ['class_id']);

        self::assertError($this->sendJson('POST', '/api/enrollments', ['class_id' => $to['id']]), 422, 'validation_error', ['student_id']);
        self::assertError($this->sendJson('POST', '/api/enrollments', ['student_id' => str_repeat('a', 24), 'class_id' => $to['id']]), 422, 'validation_error', ['student_id']);
        self::assertError($this->sendJson('POST', '/api/enrollments', ['student_id' => $student['id'], 'class_id' => 'x']), 422, 'validation_error', ['class_id']);
        self::assertError($this->getJson('/api/enrollments'), 422, 'validation_error', ['student_id']);
        self::assertError($this->getJson('/api/enrollments?student_id=' . str_repeat('a', 24)), 404, 'not_found');
    }
}
