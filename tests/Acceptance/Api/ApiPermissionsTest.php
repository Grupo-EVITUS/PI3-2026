<?php
declare(strict_types=1);

namespace Saa\Tests\Acceptance\Api;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Permissões da API (issue #26; docs/ARQUITETURA.md, seção 8): as mesmas das telas. COORDINATION consulta e
 * registra faltas, mas não escreve cadastros; SECRETARY mantém cadastros, mas não registra faltas nem vê
 * ocorrências/comunicações.
 */
final class ApiPermissionsTest extends ApiTestCase
{
    public function testIssue26CoordinationReadsRegistriesAndRegistersAbsencesButCannotWriteRegistries(): void
    {
        $school = $this->seedSchool();
        $other = $this->seedClass($school['period']['id'], '7B');
        $this->apiSignIn('COORDINATION', 'coord@escola.local');

        foreach (['/api/students', '/api/students/' . $school['student']['id'], '/api/classes', '/api/guardians', '/api/periods', '/api/enrollments?student_id=' . $school['student']['id'], '/api/students/' . $school['student']['id'] . '/context', '/api/attendance'] as $path) {
            self::assertSame(200, $this->getJson($path)->status, $path);
        }

        $student = '/api/students/' . $school['student']['id'];
        $writes = [
            ['POST', '/api/students', ['name' => 'X Y', 'student_code' => 'RA-9', 'birth_date' => '2015-01-01', 'class_id' => $school['class']['id']]],
            ['PATCH', $student, ['name' => 'Outro nome']],
            ['DELETE', $student, null],
            ['POST', '/api/classes', ['academic_period_id' => $school['period']['id'], 'code' => '8C', 'name' => '8C', 'grade' => '8', 'shift' => 'NIGHT']],
            ['PATCH', '/api/classes/' . $school['class']['id'], ['name' => 'x']],
            ['DELETE', '/api/classes/' . $other['id'], null],
            ['POST', '/api/guardians', ['name' => 'Rita Rocha', 'email' => 'rita@exemplo.com', 'phone' => '(11) 3333-4444']],
            ['PATCH', '/api/guardians/' . $school['guardian']['id'], ['name' => 'x']],
            ['DELETE', '/api/guardians/' . $school['guardian']['id'], null],
            ['POST', '/api/enrollments', ['student_id' => $school['student']['id'], 'class_id' => $other['id']]],
        ];
        foreach ($writes as [$method, $path, $body]) {
            self::assertError($this->sendJson($method, $path, $body), 403, 'forbidden');
        }
        $show = $this->getJson($student)->json()['data'];
        self::assertSame('Ana Beatriz Souza', $show['name'], 'nada foi alterado');
        self::assertTrue($show['active']);
        self::assertSame($school['class']['id'], $show['class']['id']);

        $absence = $this->sendJson('POST', '/api/attendance', ['student_id' => $school['student']['id'], 'date' => self::today()]);
        self::assertSame(201, $absence->status, $absence->body);
        $resend = $this->sendJson('POST', '/api/notifications/' . $absence->json()['data']['notifications'][0]['id'] . '/resend');
        self::assertSame(201, $resend->status, 'COORDINATION envia comunicações');
    }

    public function testIssue26SecretaryKeepsRegistriesButCannotRegisterAbsencesOrSeeOccurrences(): void
    {
        $school = $this->seedSchool();
        $this->apiSignIn('SECRETARY');

        self::assertSame(200, $this->getJson('/api/students')->status);
        $created = $this->sendJson('POST', '/api/students', ['name' => 'Davi Rocha', 'student_code' => 'RA-777', 'birth_date' => '2014-08-20', 'class_id' => $school['class']['id']]);
        self::assertSame(201, $created->status, $created->body);

        self::assertError($this->sendJson('POST', '/api/attendance', ['student_id' => $school['student']['id'], 'date' => self::today()]), 403, 'forbidden');
        self::assertError($this->getJson('/api/attendance'), 403, 'forbidden');
        self::assertError($this->getJson('/api/students/' . $school['student']['id'] . '/context'), 403, 'forbidden');
        self::assertError($this->getJson('/api/notifications?student_id=' . $school['student']['id']), 403, 'forbidden');
        self::assertError($this->sendJson('POST', '/api/notifications/' . str_repeat('a', 24) . '/resend'), 403, 'forbidden');
        self::assertSame(0, \Saa\Database\Mongo::collection(\Saa\Database\Collections::ATTENDANCE_RECORDS)->countDocuments([]));
    }

    /** @return iterable<string, array{string}> */
    public static function absenceRegistrars(): iterable
    {
        yield 'Administrador' => ['ADMIN'];
        yield 'Coordenador' => ['COORDINATION'];
        yield 'AOE' => ['AOE'];
    }

    #[DataProvider('absenceRegistrars')]
    public function testIssue26ProfilesWithAbsencesRegisterCanRegisterThroughTheApi(string $profile): void
    {
        $school = $this->seedSchool();
        $this->apiSignIn($profile, strtolower($profile) . '@escola.local');

        $response = $this->sendJson('POST', '/api/attendance', ['student_id' => $school['student']['id'], 'date' => self::today()]);

        self::assertSame(201, $response->status, $response->body);
        self::assertSame('PENDING', $response->json()['data']['attendance']['justification']['status']);
    }

    public function testIssue26DeactivatedUserLosesApiAccessImmediately(): void
    {
        $this->seedSchool();
        $this->apiSignIn('AOE', 'aoe@escola.local');
        self::assertSame(200, $this->getJson('/api/students')->status);

        \Saa\Database\Mongo::collection(\Saa\Database\Collections::USERS)->updateOne(['email' => 'aoe@escola.local'], ['$set' => ['active' => false]]);

        self::assertError($this->getJson('/api/students'), 401, 'unauthenticated');
    }
}
