<?php
declare(strict_types=1);

namespace Saa\Tests\Integration\Registry;

use MongoDB\BSON\ObjectId;
use Saa\Core\Exceptions\NotFoundException;
use Saa\Database\Collections;
use Saa\Database\Mongo;
use Saa\Models\StudentModel;
use Saa\Services\GuardianLinkService;

/** Vínculos aluno ↔ responsável (issues #38 e #39): até 3, um principal, sem repetição, avisos ligados por padrão. */
final class GuardianLinkServiceTest extends RegistryTestCase
{
    private string $studentId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $class = $this->schoolClass($this->period()['id']);
        $this->studentId = $this->student($class['id'])['id'];
    }

    /** @return list<array<string, mixed>> vínculos gravados */
    private function storedLinks(): array
    {
        $student = Mongo::collection(Collections::STUDENTS)->findOne(['_id' => new ObjectId($this->studentId)]);
        return $student['guardians'];
    }

    /** @param array<string, mixed> $extra */
    private function link(string $guardianId, string $relationship = 'MAE', array $extra = []): void
    {
        $this->links->linkExisting($this->studentId, ['guardian_id' => $guardianId, 'relationship' => $relationship] + $extra);
    }

    public function testIssue39FirstLinkBecomesPrimaryAndReceivesNoticesByDefault(): void
    {
        $guardian = $this->guardian();

        $this->link($guardian['id'], 'PAI');

        $links = $this->storedLinks();
        self::assertCount(1, $links);
        self::assertSame($guardian['id'], (string)$links[0]['guardian_id']);
        self::assertSame('PAI', $links[0]['relationship']);
        self::assertTrue($links[0]['is_primary'], 'o primeiro vínculo vira principal automaticamente');
        self::assertTrue($links[0]['receives_absence_notifications']);
        self::assertSame('VALID', Mongo::collection(Collections::GUARDIANS)->findOne([])['email_status']);
        self::assertCount(1, $this->audit('guardian.link'));
    }

    public function testIssue39MarkingAnotherGuardianAsPrimaryUnsetsThePreviousOne(): void
    {
        $first = $this->guardian('pai@exemplo.com');
        $second = $this->guardian('mae@exemplo.com', 'Maria Souza');
        $this->link($first['id'], 'PAI');

        $this->link($second['id'], 'MAE', ['is_primary' => true, 'receives_absence_notifications' => false]);

        $links = $this->storedLinks();
        self::assertSame([false, true], array_column($links, 'is_primary'));
        self::assertSame([true, false], array_column($links, 'receives_absence_notifications'));

        $this->links->updateLink($this->studentId, $first['id'], ['relationship' => 'RESPONSAVEL', 'is_primary' => true, 'receives_absence_notifications' => true]);
        $links = $this->storedLinks();
        self::assertSame([true, false], array_column($links, 'is_primary'));
        self::assertSame('RESPONSAVEL', $links[0]['relationship']);
        self::assertCount(1, $this->audit('guardian.link_update'));
    }

    public function testIssue38StudentCanHaveAtMostThreeGuardiansAndNoRepeats(): void
    {
        $ids = [];
        foreach (['a', 'b', 'c', 'd'] as $letter) {
            $ids[] = $this->guardian($letter . '@exemplo.com', 'Responsável ' . strtoupper($letter))['id'];
        }
        $this->link($ids[0]);

        $repeat = self::errorsOf(fn () => $this->link($ids[0]));
        self::assertSame('Este responsável já está vinculado ao aluno.', $repeat['guardian_id']);

        $this->link($ids[1]);
        $this->link($ids[2]);
        $fourth = self::errorsOf(fn () => $this->link($ids[3]));
        self::assertSame(GuardianLinkService::LIMIT_MESSAGE, $fourth['guardians']);
        self::assertCount(3, $this->storedLinks());
        self::assertSame(1, count(array_filter(array_column($this->storedLinks(), 'is_primary'))), 'um único principal');
    }

    public function testIssue39LinkingANewGuardianCreatesTheContactAndTheLinkTogether(): void
    {
        $this->links->linkNew($this->studentId, [
            'guardian_name' => 'Joana Prado', 'guardian_email' => 'Joana.Prado@Exemplo.com', 'guardian_phone' => '11 3333-4444', 'relationship' => 'MAE',
        ]);

        $guardian = Mongo::collection(Collections::GUARDIANS)->findOne([]);
        self::assertSame('Joana.Prado@Exemplo.com', $guardian['email']);
        self::assertSame('joana.prado@exemplo.com', $guardian['email_normalized']);
        self::assertSame('11 3333-4444', $guardian['phone'], 'telefone guardado como digitado');
        self::assertSame((string)$guardian['_id'], (string)$this->storedLinks()[0]['guardian_id']);

        $duplicate = self::errorsOf(fn () => $this->links->linkNew($this->studentId, [
            'guardian_name' => 'Outra', 'guardian_email' => ' JOANA.prado@exemplo.com ', 'guardian_phone' => '(11) 98888-7777', 'relationship' => 'PAI',
        ]));
        self::assertSame('Já existe um responsável com este e-mail.', $duplicate['guardian_email']);
    }

    public function testIssue39NewGuardianFormValidatesPhoneEmailAndRelationship(): void
    {
        $errors = self::errorsOf(fn () => $this->links->linkNew($this->studentId, [
            'guardian_name' => 'J', 'guardian_email' => 'sem-arroba', 'guardian_phone' => '9999-0000', 'relationship' => 'TIO',
        ]));

        self::assertSame('Use pelo menos 2 caracteres.', $errors['guardian_name']);
        self::assertSame('Informe um e-mail válido.', $errors['guardian_email']);
        self::assertSame('Informe o telefone com DDD, por exemplo (11) 99999-0001.', $errors['guardian_phone']);
        self::assertSame('Selecione uma opção válida.', $errors['relationship']);
        self::assertSame(0, Mongo::collection(Collections::GUARDIANS)->countDocuments());
    }

    public function testIssue38LimitIsCheckedBeforeCreatingANewGuardian(): void
    {
        foreach (['a', 'b', 'c'] as $letter) {
            $this->link($this->guardian($letter . '@exemplo.com')['id']);
        }

        $errors = self::errorsOf(fn () => $this->links->linkNew($this->studentId, [
            'guardian_name' => 'Quarto', 'guardian_email' => 'd@exemplo.com', 'guardian_phone' => '(11) 98888-7777', 'relationship' => 'PAI',
        ]));

        self::assertSame(GuardianLinkService::LIMIT_MESSAGE, $errors['guardians']);
        self::assertSame(0, Mongo::collection(Collections::GUARDIANS)->countDocuments(['email_normalized' => 'd@exemplo.com']));
    }

    public function testIssue39RemovingThePrimaryPromotesTheNextLinkAndKeepsTheGuardian(): void
    {
        $first = $this->guardian('pai@exemplo.com');
        $second = $this->guardian('mae@exemplo.com');
        $this->link($first['id'], 'PAI');
        $this->link($second['id'], 'MAE');

        $this->links->unlink($this->studentId, $first['id']);

        $links = $this->storedLinks();
        self::assertCount(1, $links);
        self::assertSame($second['id'], (string)$links[0]['guardian_id']);
        self::assertTrue($links[0]['is_primary']);
        self::assertSame(2, Mongo::collection(Collections::GUARDIANS)->countDocuments(), 'o cadastro do responsável continua');
        self::assertCount(1, $this->audit('guardian.unlink'));

        $this->expectException(NotFoundException::class);
        $this->links->unlink($this->studentId, $first['id']);
    }

    public function testIssue39ArchivedGuardianCannotBeLinked(): void
    {
        $guardian = $this->guardian();
        $this->guardians->archive($guardian['id']);

        $errors = self::errorsOf(fn () => $this->link($guardian['id']));

        self::assertSame('Este responsável está arquivado. Reative o cadastro dele antes de vincular.', $errors['guardian_id']);
    }

    public function testIssue39CreatingAGuardianCanLinkHimToAStudentByTheSchoolCode(): void
    {
        $result = $this->links->createGuardian([
            'name' => 'Paulo Reis', 'email' => 'paulo@exemplo.com', 'phone' => '(21) 2222-3333', 'student_code' => 'RA-001', 'relationship' => 'PAI',
        ]);

        self::assertSame('Ana Beatriz Souza', $result['student_name']);
        self::assertSame($result['guardian']['id'], (string)$this->storedLinks()[0]['guardian_id']);

        $unknown = self::errorsOf(fn () => $this->links->createGuardian([
            'name' => 'Rita', 'email' => 'rita@exemplo.com', 'phone' => '(21) 2222-3333', 'student_code' => 'RA-999',
        ]));
        self::assertSame('Nenhum aluno ativo com esta matrícula escolar.', $unknown['student_code']);
        self::assertSame('Informe o parentesco.', $unknown['relationship']);
        self::assertSame(1, Mongo::collection(Collections::GUARDIANS)->countDocuments(), 'nada é gravado com erro');
    }

    public function testIssue39SchoolCodeTypedInLowercaseStillFindsTheStudent(): void
    {
        $result = $this->links->createGuardian([
            'name' => 'Paulo Reis', 'email' => 'paulo@exemplo.com', 'phone' => '(21) 2222-3333', 'student_code' => '  ra-001 ', 'relationship' => 'PAI',
        ]);

        self::assertSame('Ana Beatriz Souza', $result['student_name']);
        self::assertSame($result['guardian']['id'], (string)$this->storedLinks()[0]['guardian_id']);
    }

    public function testIssue39ConcurrentChangeToTheLinksIsDetected(): void
    {
        $model = new StudentModel($this->db());
        $student = $model->findById($this->studentId);
        $this->link($this->guardian()['id']);

        $stale = $model->replaceGuardians($student['_id'], $student['guardians'], []);

        self::assertFalse($stale, 'gravação com dados antigos não pode apagar o vínculo feito por outra pessoa');
        self::assertCount(1, $this->storedLinks());
    }

    public function testIssue39LinkedGuardiansComeWithContactDataInOneBatch(): void
    {
        $guardian = $this->guardian();
        $this->link($guardian['id'], 'PAI');

        $show = $this->students->show($this->studentId);

        self::assertSame('Carlos Souza', $show['guardians'][0]['name']);
        self::assertSame('carlos@exemplo.com', $show['guardians'][0]['email']);
        self::assertSame('(11) 99999-0001', $show['guardians'][0]['phone']);
        self::assertSame('VALID', $show['guardians'][0]['email_status']);
        self::assertTrue($show['guardians'][0]['is_primary']);
        self::assertSame(1, $show['student']['guardian_count']);
    }
}
