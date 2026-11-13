<?php
declare(strict_types=1);

namespace Saa\Tests\Acceptance\Api;

/**
 * /api/students (issues #26 e #38): CRUD com 201/200/204/404/422/409, PATCH parcial, arquivamento que preserva o
 * histórico, paginação (per_page padrão 20, máximo 100) e filtros.
 */
final class ApiStudentsTest extends ApiTestCase
{
    private string $classId = '';
    private string $otherClassId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $period = $this->seedPeriod();
        $this->classId = $this->seedClass($period['id'], '6A')['id'];
        $this->otherClassId = $this->seedClass($period['id'], '7B')['id'];
        $this->apiSignIn('SECRETARY');
    }

    public function testIssue26CreateReturns201WithLocationAndTheStudentRecord(): void
    {
        $response = $this->sendJson('POST', '/api/students', [
            'name' => 'Ana Beatriz Souza', 'student_code' => 'ra-2026-0001', 'birth_date' => '2015-03-14', 'class_id' => $this->classId,
        ]);

        self::assertSame(201, $response->status, $response->body);
        $data = $response->json()['data'];
        self::assertMatchesRegularExpression('/^[a-f0-9]{24}$/', $data['id']);
        self::assertSame('/api/students/' . $data['id'], $response->location());
        self::assertSame('Ana Beatriz Souza', $data['name']);
        self::assertSame('RA-2026-0001', $data['student_code'], 'matrícula normalizada como na tela');
        self::assertSame('2015-03-14', $data['birth_date'], 'data de calendário AAAA-MM-DD');
        self::assertTrue($data['active']);
        self::assertSame($this->classId, $data['class']['id']);
        self::assertSame('6A', $data['class']['code']);
        self::assertSame([], $data['guardians']);
        self::assertCount(1, $data['enrollments']);
        self::assertTrue($data['enrollments'][0]['active']);
        self::assertArrayNotHasKey('_id', $data);

        $show = $this->getJson('/api/students/' . $data['id']);
        self::assertSame(200, $show->status);
        self::assertSame($data, $show->json()['data']);
    }

    public function testIssue26InvalidStudentIs422WithFieldMessages(): void
    {
        $response = $this->sendJson('POST', '/api/students', ['name' => '', 'student_code' => ['$ne' => null], 'birth_date' => '2999-01-01', 'class_id' => 'abc']);

        self::assertError($response, 422, 'validation_error', ['name', 'student_code', 'birth_date', 'class_id']);
        self::assertSame('Informe o nome do aluno.', $response->json()['error']['fields']['name']);
        self::assertSame(0, $this->getJson('/api/students')->json()['meta']['total']);
    }

    public function testIssue26DuplicateStudentCodeIs409(): void
    {
        $this->seedStudent($this->classId, 'RA-001');

        $response = $this->sendJson('POST', '/api/students', ['name' => 'Outra Pessoa', 'student_code' => 'ra-001', 'birth_date' => '2015-01-01', 'class_id' => $this->classId]);

        self::assertError($response, 409, 'conflict', ['student_code']);
        self::assertSame('Já existe um aluno com esta matrícula escolar.', $response->json()['error']['message']);
        self::assertSame(1, $this->getJson('/api/students')->json()['meta']['total']);
    }

    public function testIssue26PatchChangesOnlyTheFieldsSent(): void
    {
        $student = $this->seedStudent($this->classId, 'RA-001', 'Ana Souza');
        $this->seedStudent($this->classId, 'RA-002', 'Bruno Lima');

        $renamed = $this->sendJson('PATCH', '/api/students/' . $student['id'], ['name' => 'Ana Beatriz Souza']);
        self::assertSame(200, $renamed->status, $renamed->body);
        self::assertSame('Ana Beatriz Souza', $renamed->json()['data']['name']);
        self::assertSame('RA-001', $renamed->json()['data']['student_code'], 'campo não enviado fica igual');
        self::assertSame('2015-03-14', $renamed->json()['data']['birth_date']);

        $duplicate = $this->sendJson('PATCH', '/api/students/' . $student['id'], ['student_code' => 'RA-002']);
        self::assertError($duplicate, 409, 'conflict', ['student_code']);

        $invalid = $this->sendJson('PATCH', '/api/students/' . $student['id'], ['birth_date' => 'ontem', 'name' => null]);
        self::assertError($invalid, 422, 'validation_error', ['birth_date', 'name']);

        $empty = $this->sendJson('PATCH', '/api/students/' . $student['id'], ['unknown' => 'x']);
        self::assertError($empty, 422, 'validation_error', ['body']);

        $missing = $this->sendJson('PATCH', '/api/students/' . str_repeat('c', 24), ['name' => 'Ninguém']);
        self::assertError($missing, 404, 'not_found');

        self::assertSame('Ana Beatriz Souza', $this->getJson('/api/students/' . $student['id'])->json()['data']['name']);
    }

    public function testIssue26DeleteArchivesWith204AndKeepsTheHistory(): void
    {
        $student = $this->seedStudent($this->classId);

        $deleted = $this->sendJson('DELETE', '/api/students/' . $student['id']);
        self::assertSame(204, $deleted->status);
        self::assertSame('', $deleted->body);

        $show = $this->getJson('/api/students/' . $student['id'])->json()['data'];
        self::assertFalse($show['active'], 'arquivado, não apagado');
        self::assertNull($show['class']);
        self::assertCount(1, $show['enrollments']);
        self::assertFalse($show['enrollments'][0]['active']);
        self::assertSame('TRANSFER', $show['enrollments'][0]['end_reason']);

        self::assertSame(204, $this->sendJson('DELETE', '/api/students/' . $student['id'])->status, 'idempotente');
        self::assertError($this->sendJson('DELETE', '/api/students/' . str_repeat('d', 24)), 404, 'not_found');

        self::assertSame(0, $this->getJson('/api/students')->json()['meta']['total']);
        $archived = $this->getJson('/api/students?status=archived')->json();
        self::assertSame(1, $archived['meta']['total']);
        self::assertSame($student['id'], $archived['data'][0]['id']);
    }

    public function testIssue26ListIsPaginatedWithMeta(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->seedStudent($this->classId, sprintf('RA-%03d', $i), sprintf('Aluno %02d', $i));
        }

        $first = $this->getJson('/api/students');
        self::assertSame(200, $first->status);
        self::assertSame(['page' => 1, 'per_page' => 20, 'total' => 25, 'pages' => 2], $first->json()['meta']);
        self::assertCount(20, $first->json()['data']);
        self::assertSame('Aluno 01', $first->json()['data'][0]['name'], 'ordem por nome');

        $third = $this->getJson('/api/students?per_page=10&page=3');
        self::assertSame(['page' => 3, 'per_page' => 10, 'total' => 25, 'pages' => 3], $third->json()['meta']);
        self::assertSame(['Aluno 21', 'Aluno 22', 'Aluno 23', 'Aluno 24', 'Aluno 25'], array_column($third->json()['data'], 'name'));

        $all = $this->getJson('/api/students?per_page=100');
        self::assertCount(25, $all->json()['data']);

        foreach (['per_page=101', 'per_page=0', 'page=0', 'page=abc', 'per_page[]=5', 'status=deleted', 'class_id=xyz'] as $query) {
            self::assertError($this->getJson('/api/students?' . $query), 422, 'validation_error');
        }
    }

    public function testIssue26ListFiltersBySearchClassAndIgnoresOperatorInjection(): void
    {
        $this->seedStudent($this->classId, 'RA-001', 'Ana Beatriz Souza');
        $this->seedStudent($this->otherClassId, 'RA-002', 'Bruno Lima');

        $search = $this->getJson('/api/students?q=' . rawurlencode('beatriz'))->json();
        self::assertSame(['Ana Beatriz Souza'], array_column($search['data'], 'name'));

        $byClass = $this->getJson('/api/students?class_id=' . $this->otherClassId)->json();
        self::assertSame(['Bruno Lima'], array_column($byClass['data'], 'name'));
        self::assertSame('7B', $byClass['data'][0]['class']['code']);

        $regex = $this->getJson('/api/students?q=' . rawurlencode('.*'))->json();
        self::assertSame(0, $regex['meta']['total'], 'o termo é escapado (sem regex)');

        $injection = $this->getJson('/api/students?q[$ne]=x')->json();
        self::assertSame(2, $injection['meta']['total'], 'operador NoSQL ignorado');
    }
}
