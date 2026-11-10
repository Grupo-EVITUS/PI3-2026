<?php
declare(strict_types=1);

namespace Saa\Tests\Acceptance\Registry;

use Saa\Database\Collections;
use Saa\Database\Mongo;

/** Fluxos HTTP de alunos (issue #38), vínculo com responsáveis (issue #39) e troca de turma (issue #41). */
final class StudentFlowTest extends RegistryHttpTestCase
{
    private string $classId = '';
    private string $otherClassId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $period = $this->seedPeriod();
        $this->classId = $this->seedClass($period['id'], '6A')['id'];
        $this->otherClassId = $this->seedClass($period['id'], '7B')['id'];
        $this->signIn('SECRETARY');
    }

    public function testIssue38CreatingAStudentWithAClassRedirectsToHisRecord(): void
    {
        $created = $this->send('/students', ['name' => 'Ana Beatriz Souza', 'student_code' => 'RA-2026-0001', 'birth_date' => '2015-03-14', 'class_id' => $this->classId]);

        self::assertSame(303, $created->status);
        $id = self::idFromLocation($created);
        self::assertSame('/students/' . $id, $created->location());

        $page = $this->http->get('/students/' . $id);
        self::assertSame(200, $page->status);
        $text = $page->text();
        self::assertStringContainsString('Ana Beatriz Souza', $text);
        self::assertStringContainsString('cadastrado(a) e matriculado(a)', $text, 'mensagem flash');
        self::assertStringContainsString('RA-2026-0001', $text);
        self::assertStringContainsString('14/03/2015', $text);
        self::assertStringContainsString('6A — Turma 6A', $text);
        self::assertStringContainsString('Vigente', $text);
        self::assertStringContainsString('Nenhum responsável vinculado', $text);
    }

    public function testIssue38ValidationErrorsReturn422WithMessagesAndTypedValues(): void
    {
        $response = $this->send('/students', ['name' => 'Bruno <b>Lima</b>', 'student_code' => '', 'birth_date' => '2999-01-01', 'class_id' => '']);

        self::assertSame(422, $response->status);
        $text = $response->text();
        self::assertStringContainsString('Verifique os campos destacados', $text);
        self::assertStringContainsString('Informe a matrícula escolar.', $text);
        self::assertStringContainsString('A data de nascimento precisa ser anterior a hoje.', $text);
        self::assertStringContainsString('Informe a turma.', $text);
        self::assertStringContainsString('value="Bruno &lt;b&gt;Lima&lt;/b&gt;"', $response->body, 'valor digitado volta escapado');
        self::assertStringContainsString('<details class="card registry-disclosure" open>', $response->body);
        self::assertSame(0, Mongo::collection(Collections::STUDENTS)->countDocuments());
    }

    public function testIssue38DuplicateSchoolCodeIsAFriendly422(): void
    {
        $this->seedStudent($this->classId, 'RA-001');

        $response = $this->send('/students', ['name' => 'Outra Pessoa', 'student_code' => 'RA-001', 'birth_date' => '2015-01-01', 'class_id' => $this->classId]);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('Já existe um aluno com esta matrícula escolar.', $response->text());
    }

    public function testIssue38ListSearchesFiltersAndPaginates(): void
    {
        $this->seedStudent($this->classId, 'RA-001', 'Ana Beatriz Souza');
        $this->seedStudent($this->otherClassId, 'RA-002', 'Bruno Lima');
        for ($i = 1; $i <= 21; $i++) {
            $this->seedStudent($this->classId, sprintf('ZZ-%02d', $i), sprintf('Zeca %02d', $i));
        }

        $search = $this->http->get('/students?q=' . rawurlencode('beatriz'))->text();
        self::assertStringContainsString('Ana Beatriz Souza', $search);
        self::assertStringNotContainsString('Bruno Lima', $search);
        self::assertStringContainsString('1 aluno encontrado', $search);

        $byClass = $this->http->get('/students?class=' . $this->otherClassId)->text();
        self::assertStringContainsString('Bruno Lima', $byClass);
        self::assertStringNotContainsString('Ana Beatriz Souza', $byClass);
        self::assertStringContainsString('7B', $byClass);

        $first = $this->http->get('/students');
        self::assertStringContainsString('23 alunos encontrados', $first->text());
        self::assertStringContainsString('href="/students?page=2"', $first->body);
        $second = $this->http->get('/students?page=2')->text();
        self::assertStringContainsString('Zeca 20', $second);
        self::assertStringNotContainsString('Ana Beatriz Souza', $second);
    }

    public function testIssue38OperatorInjectionInTheSearchIsIgnored(): void
    {
        $this->seedStudent($this->classId);

        $response = $this->http->get('/students?q[$ne]=x&class[$gt]=&page[$gt]=1');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Ana Beatriz Souza', $response->text());
    }

    public function testIssue39LinkingANewGuardianShowsHimAsPrimaryWithNotices(): void
    {
        $student = $this->seedStudent($this->classId);

        $response = $this->send('/students/' . $student['id'] . '/guardians', [
            'mode' => 'new', 'guardian_name' => 'Carlos Souza', 'guardian_email' => 'Carlos@Exemplo.com',
            'guardian_phone' => '(11) 99999-0001', 'relationship' => 'PAI', 'receives_absence_notifications' => '1',
        ]);

        self::assertSame(303, $response->status, $response->text());
        $text = $this->http->get('/students/' . $student['id'])->text();
        self::assertStringContainsString('Carlos Souza', $text);
        self::assertStringContainsString('Pai', $text);
        self::assertStringContainsString('Principal', $text);
        self::assertStringContainsString('Recebe avisos', $text);
        self::assertStringContainsString('Válido', $text);
        self::assertStringContainsString('(11) 99999-0001', $text);
        self::assertStringContainsString('1 de 3', $text);
    }

    public function testIssue39NewGuardianWithInvalidPhoneReturns422InTheRightForm(): void
    {
        $student = $this->seedStudent($this->classId);

        $response = $this->send('/students/' . $student['id'] . '/guardians', [
            'mode' => 'new', 'guardian_name' => 'Carlos', 'guardian_email' => 'carlos@exemplo.com', 'guardian_phone' => '9999-0001', 'relationship' => 'PAI',
        ]);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('Informe o telefone com DDD, por exemplo (11) 99999-0001.', $response->text());
        self::assertStringContainsString('value="carlos@exemplo.com"', $response->body);
        self::assertSame(0, Mongo::collection(Collections::GUARDIANS)->countDocuments());
    }

    public function testIssue39ExistingGuardianIsFoundBySearchAndLinkedThenUpdatedAndRemoved(): void
    {
        $student = $this->seedStudent($this->classId);
        $guardian = $this->seedGuardian('marta@exemplo.com', 'Marta Prado');

        $search = $this->http->get('/students/' . $student['id'] . '?guardian_q=prado');
        self::assertStringContainsString('value="' . $guardian['id'] . '"', $search->body);

        $linked = $this->send('/students/' . $student['id'] . '/guardians', [
            'mode' => 'existing', 'guardian_id' => $guardian['id'], 'relationship' => 'MAE', 'receives_absence_notifications' => '0',
        ]);
        self::assertSame(303, $linked->status, $linked->text());
        $text = $this->http->get('/students/' . $student['id'])->text();
        self::assertStringContainsString('Marta Prado', $text);
        self::assertStringContainsString('Não recebe avisos', $text);

        $updated = $this->send('/students/' . $student['id'] . '/guardians/' . $guardian['id'], ['relationship' => 'RESPONSAVEL', 'is_primary' => '1', 'receives_absence_notifications' => '1']);
        self::assertSame(303, $updated->status);
        $link = Mongo::collection(Collections::STUDENTS)->findOne([])['guardians'][0];
        self::assertSame('RESPONSAVEL', $link['relationship']);
        self::assertTrue($link['receives_absence_notifications']);

        $repeat = $this->send('/students/' . $student['id'] . '/guardians', ['mode' => 'existing', 'guardian_id' => $guardian['id'], 'relationship' => 'MAE']);
        self::assertSame(422, $repeat->status);
        self::assertStringContainsString('Este responsável já está vinculado ao aluno.', $repeat->text());

        $removed = $this->send('/students/' . $student['id'] . '/guardians/' . $guardian['id'] . '/remove');
        self::assertSame(303, $removed->status);
        self::assertSame([], Mongo::collection(Collections::STUDENTS)->findOne([])['guardians']);
    }

    public function testIssue41ChangingClassKeepsTheHistoryOnTheRecord(): void
    {
        $student = $this->seedStudent($this->classId);

        $response = $this->send('/students/' . $student['id'] . '/enrollment', ['class_id' => $this->otherClassId]);

        self::assertSame(303, $response->status);
        $text = $this->http->get('/students/' . $student['id'])->text();
        self::assertStringContainsString('Turma alterada', $text);
        self::assertStringContainsString('7B — Turma 7B', $text);
        self::assertStringContainsString('Encerrada: Mudança de turma', $text);
        self::assertSame(2, Mongo::collection(Collections::ENROLLMENTS)->countDocuments());

        $same = $this->send('/students/' . $student['id'] . '/enrollment', ['class_id' => $this->otherClassId]);
        self::assertSame(422, $same->status);
        self::assertStringContainsString('O aluno já está matriculado nesta turma.', $same->text());
    }

    public function testIssue38EditArchiveAndReactivate(): void
    {
        $student = $this->seedStudent($this->classId);

        $invalid = $this->send('/students/' . $student['id'], ['name' => 'Ana', 'student_code' => 'RA-001', 'birth_date' => 'ontem']);
        self::assertSame(422, $invalid->status);
        self::assertStringContainsString('Informe uma data válida.', $invalid->text());

        $edited = $this->send('/students/' . $student['id'], ['name' => 'Ana B. Souza', 'student_code' => 'RA-001', 'birth_date' => '2015-03-15']);
        self::assertSame(303, $edited->status);
        self::assertStringContainsString('15/03/2015', $this->http->get('/students/' . $student['id'])->text());

        self::assertSame(303, $this->send('/students/' . $student['id'] . '/archive')->status);
        $archived = $this->http->get('/students/' . $student['id'])->text();
        self::assertStringContainsString('Cadastro arquivado', $archived);
        self::assertStringContainsString('Encerrada: Transferência', $archived);
        self::assertStringNotContainsString('Ana B. Souza', $this->http->get('/students')->text());
        self::assertStringContainsString('Ana B. Souza', $this->http->get('/students?status=archived')->text());

        self::assertSame(303, $this->send('/students/' . $student['id'] . '/reactivate')->status);
        self::assertStringContainsString('Matricular em uma turma', $this->http->get('/students/' . $student['id'])->text());
    }

    public function testIssue38UnknownStudentIs404(): void
    {
        self::assertSame(404, $this->http->get('/students/507f1f77bcf86cd799439011')->status);
        self::assertSame(404, $this->http->get('/students/nao-e-um-id')->status);
        self::assertSame(404, $this->send('/students/507f1f77bcf86cd799439011/archive')->status);
    }
}
