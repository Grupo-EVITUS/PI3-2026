<?php
declare(strict_types=1);

namespace Saa\Tests\Acceptance\Api;

use Saa\Database\Collections;
use Saa\Database\Mongo;

/**
 * /api/attendance, /api/students/{id}/context e /api/notifications (issues #26, #29 e base da #42): registrar a
 * ausência pela API cria a ocorrência PENDING e envia um e-mail por responsável válido (MAIL_MODE=file no
 * servidor de teste); ausência repetida → 409; contexto do aluno (CONSULTA 1); histórico e reenvio das
 * comunicações; nenhuma resposta contém token, hash do token ou link de justificativa.
 */
final class ApiAttendanceTest extends ApiTestCase
{
    /** @var array<string, mixed> */
    private array $school = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->seedSchool();
        $this->apiSignIn('AOE', 'aoe@escola.local');
    }

    /** @return array<string, mixed> resposta "data" do registro */
    private function registerAbsence(?string $note = 'Faltou à primeira aula.'): array
    {
        $response = $this->sendJson('POST', '/api/attendance', ['student_id' => $this->school['student']['id'], 'date' => self::today(), 'note' => $note]);
        self::assertSame(201, $response->status, $response->body);
        return $response->json()['data'];
    }

    public function testIssue26RegisteringAnAbsenceCreatesAPendingOccurrenceAndSendsTheNotifications(): void
    {
        $mailsBefore = count(self::sentMails());

        $data = $this->registerAbsence();

        $attendance = $data['attendance'];
        self::assertMatchesRegularExpression('/^[a-f0-9]{24}$/', $attendance['id']);
        self::assertSame(self::today(), $attendance['date']);
        self::assertSame('ABSENT', $attendance['status']);
        self::assertSame($this->school['student']['id'], $attendance['student_id']);
        self::assertSame('Ana Beatriz Souza', $attendance['student']['name']);
        self::assertSame($this->school['class']['id'], $attendance['class_id']);
        self::assertSame('Turma 6A', $attendance['class_name']);
        self::assertSame('Faltou à primeira aula.', $attendance['details']['note']);
        self::assertSame('PENDING', $attendance['justification']['status']);
        self::assertCount(1, $attendance['justification']['status_history']);
        self::assertSame('PENDING', $attendance['justification']['status_history'][0]['status']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $attendance['justification']['status_history'][0]['at'], 'instante ISO-8601 UTC');
        self::assertFalse($data['converted_from_present']);
        self::assertSame([], $data['warnings']);

        self::assertCount(1, $data['notifications'], 'um e-mail por responsável válido');
        self::assertSame($this->school['guardian']['id'], $data['notifications'][0]['guardian_id']);
        self::assertSame('carlos@exemplo.com', $data['notifications'][0]['recipient_email']);
        self::assertSame('SENT', $data['notifications'][0]['status']);
        self::assertCount($mailsBefore + 1, self::sentMails(), 'o FileMailer do servidor gravou o .eml');

        $stored = Mongo::collection(Collections::ATTENDANCE_RECORDS)->findOne(['_id' => new \MongoDB\BSON\ObjectId($attendance['id'])]);
        self::assertIsArray($stored);
        self::assertSame('PENDING', $stored['justification']['status']);
        self::assertSame(1, Mongo::collection(Collections::EMAIL_NOTIFICATIONS)->countDocuments(['attendance_id' => $stored['_id']]));
    }

    public function testIssue26DuplicateAbsenceIs409AndInvalidDataIs422(): void
    {
        $this->registerAbsence();

        $duplicate = $this->sendJson('POST', '/api/attendance', ['student_id' => $this->school['student']['id'], 'date' => self::today()]);
        self::assertError($duplicate, 409, 'conflict');
        self::assertSame('Já existe ausência registrada para este aluno nesta data.', $duplicate->json()['error']['message']);
        self::assertSame(1, Mongo::collection(Collections::EMAIL_NOTIFICATIONS)->countDocuments([]), 'nenhum e-mail novo');

        self::assertError($this->sendJson('POST', '/api/attendance', []), 422, 'validation_error', ['student_id', 'date']);
        self::assertError($this->sendJson('POST', '/api/attendance', ['student_id' => ['$ne' => null], 'date' => '2026-02-30']), 422, 'validation_error', ['student_id', 'date']);
        self::assertError($this->sendJson('POST', '/api/attendance', ['student_id' => str_repeat('a', 24), 'date' => self::today()]), 422, 'validation_error', ['student_id']);
        self::assertError($this->sendJson('POST', '/api/attendance', ['student_id' => $this->school['student']['id'], 'date' => '2999-01-01']), 422, 'validation_error', ['date']);
    }

    public function testIssue26AttendanceListFiltersAndDetail(): void
    {
        $registered = $this->registerAbsence();
        $id = $registered['attendance']['id'];

        $all = $this->getJson('/api/attendance');
        self::assertSame(['page' => 1, 'per_page' => 20, 'total' => 1, 'pages' => 1], $all->json()['meta']);
        self::assertSame($id, $all->json()['data'][0]['id']);

        $filters = [
            'student_id=' . $this->school['student']['id'] => 1,
            'class_id=' . $this->school['class']['id'] => 1,
            'status=ABSENT' => 1,
            'status=PRESENT' => 0,
            'justification_status=PENDING' => 1,
            'justification_status=RESPONDED' => 0,
            'date_from=' . self::today() . '&date_to=' . self::today() => 1,
            'date_to=2000-01-01' => 0,
            'student_id=' . str_repeat('a', 24) => 0,
        ];
        foreach ($filters as $query => $expected) {
            self::assertSame($expected, $this->getJson('/api/attendance?' . $query)->json()['meta']['total'], $query);
        }
        foreach (['status=LATE' => 'status', 'date_from=amanha' => 'date_from', 'date_from=2026-05-02&date_to=2026-05-01' => 'date_to', 'class_id=1' => 'class_id', 'justification_status=X' => 'justification_status'] as $query => $field) {
            self::assertError($this->getJson('/api/attendance?' . $query), 422, 'validation_error', [$field]);
        }

        $detail = $this->getJson('/api/attendance/' . $id);
        self::assertSame(200, $detail->status);
        self::assertSame($registered['attendance'], $detail->json()['data']);
        self::assertError($this->getJson('/api/attendance/' . str_repeat('b', 24)), 404, 'not_found');
    }

    public function testIssue26StudentContextListsGuardiansToNotifyAndRecentAbsences(): void
    {
        $bounced = $this->seedGuardian('avo@exemplo.com', 'Avó Souza');
        $this->services()['links']->linkExisting($this->school['student']['id'], ['guardian_id' => $bounced['id'], 'relationship' => 'RESPONSAVEL']);
        $this->services()['guardians']->update($bounced['id'], ['name' => 'Avó Souza', 'email' => 'avo@exemplo.com', 'phone' => '(11) 99999-0001', 'email_status' => 'BOUNCED']);

        $before = $this->getJson('/api/students/' . $this->school['student']['id'] . '/context');
        self::assertSame(200, $before->status, $before->body);
        self::assertSame(['id' => $this->school['student']['id'], 'name' => 'Ana Beatriz Souza', 'student_code' => 'RA-001'], $before->json()['data']['student']);
        self::assertSame([['id' => $this->school['guardian']['id'], 'name' => 'Carlos Souza', 'email' => 'carlos@exemplo.com']], $before->json()['data']['guardians_to_notify'], 'e-mail BOUNCED não recebe aviso');
        self::assertSame([], $before->json()['data']['recent_absences']);

        $registered = $this->registerAbsence();
        $after = $this->getJson('/api/students/' . $this->school['student']['id'] . '/context')->json()['data'];
        self::assertCount(1, $after['recent_absences']);
        self::assertSame($registered['attendance']['id'], $after['recent_absences'][0]['id']);
        self::assertSame(self::today(), $after['recent_absences'][0]['date']);
        self::assertSame('PENDING', $after['recent_absences'][0]['justification_status']);

        self::assertError($this->getJson('/api/students/' . str_repeat('c', 24) . '/context'), 404, 'not_found');
        $this->services()['students']->archive($this->school['student']['id']);
        self::assertError($this->getJson('/api/students/' . $this->school['student']['id'] . '/context'), 404, 'not_found');
    }

    public function testIssue26NotificationHistoryAndResend(): void
    {
        $registered = $this->registerAbsence();
        $attendanceId = $registered['attendance']['id'];
        $original = $registered['notifications'][0]['id'];

        $history = $this->getJson('/api/notifications?attendance_id=' . $attendanceId);
        self::assertSame(200, $history->status, $history->body);
        self::assertSame(1, $history->json()['meta']['total']);
        $item = $history->json()['data'][0];
        self::assertSame($original, $item['id']);
        self::assertSame('SENT', $item['status']);
        self::assertSame(1, $item['attempts']);
        self::assertSame('SENT', $item['events'][0]['type']);
        self::assertMatchesRegularExpression('/Z$/', $item['events'][0]['occurred_at']);
        self::assertNull($item['resend_of']);
        self::assertArrayNotHasKey('justification_link', $item);
        self::assertArrayNotHasKey('body', $item);

        $mailsBefore = count(self::sentMails());
        $resent = $this->sendJson('POST', '/api/notifications/' . $original . '/resend');
        self::assertSame(201, $resent->status, $resent->body);
        $new = $resent->json()['data'];
        self::assertNotSame($original, $new['id']);
        self::assertSame($original, $new['resend_of']);
        self::assertSame('SENT', $new['status']);
        self::assertSame($attendanceId, $new['attendance_id']);
        self::assertCount($mailsBefore + 1, self::sentMails());

        $byStudent = $this->getJson('/api/notifications?student_id=' . $this->school['student']['id'])->json();
        self::assertSame(2, $byStudent['meta']['total']);
        self::assertSame([$new['id'], $original], array_column($byStudent['data'], 'id'), 'mais recentes primeiro; a original é preservada');
        self::assertSame('SENT', $byStudent['data'][1]['status']);

        self::assertSame(0, $this->getJson('/api/notifications?student_id=' . str_repeat('d', 24))->json()['meta']['total']);
        self::assertError($this->getJson('/api/notifications'), 422, 'validation_error', ['attendance_id']);
        self::assertError($this->getJson('/api/notifications?attendance_id=xyz'), 422, 'validation_error', ['attendance_id']);
        self::assertError($this->getJson('/api/notifications?attendance_id=' . str_repeat('e', 24)), 404, 'not_found');
        self::assertError($this->sendJson('POST', '/api/notifications/' . str_repeat('f', 24) . '/resend'), 404, 'not_found');
    }

    public function testIssue26AbsenceWithoutGuardianToNotifyIsRegisteredWithAWarning(): void
    {
        $lonely = $this->seedStudent($this->school['class']['id'], 'RA-002', 'Bruno Lima');

        $response = $this->sendJson('POST', '/api/attendance', ['student_id' => $lonely['id'], 'date' => self::today()]);

        self::assertSame(201, $response->status, $response->body);
        self::assertSame([], $response->json()['data']['notifications']);
        self::assertStringContainsString('nenhum e-mail foi enviado', implode(' ', $response->json()['data']['warnings']));
        self::assertSame('PENDING', $response->json()['data']['attendance']['justification']['status']);
    }
}
