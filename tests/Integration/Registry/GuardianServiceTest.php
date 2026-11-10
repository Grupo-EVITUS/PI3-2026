<?php
declare(strict_types=1);

namespace Saa\Tests\Integration\Registry;

use Saa\Database\Collections;
use Saa\Database\Mongo;
use Saa\Services\RegistrySupport;

/** Responsáveis (issue #39): cadastro, e-mail único normalizado, telefone com DDD, busca, edição, arquivamento e ficha. */
final class GuardianServiceTest extends RegistryTestCase
{
    public function testIssue39PhoneMustHaveTheAreaCodeWithTenOrElevenDigits(): void
    {
        foreach (['(11) 99999-0001', '11 3333-4444', '11999990001', '(21) 2222.3333'] as $valid) {
            self::assertTrue(RegistrySupport::isPhone($valid), $valid);
        }
        foreach (['9999-0001', '(11) 99999-00012', '+55 11 99999-0001', 'abc', '(01) 2345-6789', '11 9999 0001 ext'] as $invalid) {
            self::assertFalse(RegistrySupport::isPhone($invalid), $invalid);
        }

        $errors = self::errorsOf(fn () => $this->links->createGuardian(['name' => 'Ana', 'email' => 'ana@exemplo.com']));
        self::assertSame('Informe o telefone com DDD.', $errors['phone']);
    }

    public function testIssue39EmailIsUniqueByItsNormalizedValue(): void
    {
        $this->guardian('carlos@exemplo.com');

        $errors = self::errorsOf(fn () => $this->guardian('  Carlos@EXEMPLO.com '));

        self::assertSame('Já existe um responsável com este e-mail.', $errors['email']);
        self::assertSame(1, Mongo::collection(Collections::GUARDIANS)->countDocuments());
    }

    public function testIssue39ListSearchesByNameOrEmailAndCountsLinkedStudents(): void
    {
        $class = $this->schoolClass($this->period()['id']);
        $student = $this->student($class['id']);
        $carlos = $this->guardian('carlos@exemplo.com', 'Carlos Souza');
        $this->guardian('marta@escola.org', 'Marta Lima');
        $this->links->linkExisting($student['id'], ['guardian_id' => $carlos['id'], 'relationship' => 'PAI']);

        $byName = $this->guardians->list('souza', null, 1);
        $byEmail = $this->guardians->list('ESCOLA.ORG', null, 1);
        $all = $this->guardians->list(null, null, 1);

        self::assertSame(['Carlos Souza'], array_column($byName['items'], 'name'));
        self::assertSame(1, $byName['items'][0]['student_count']);
        self::assertSame(['Marta Lima'], array_column($byEmail['items'], 'name'));
        self::assertSame(0, $byEmail['items'][0]['student_count']);
        self::assertSame(2, $all['pagination']['total']);
    }

    public function testIssue39UpdateChangesContactAndEmailStatusAndRejectsDuplicateEmail(): void
    {
        $carlos = $this->guardian('carlos@exemplo.com');
        $this->guardian('marta@exemplo.com', 'Marta Lima');

        $updated = $this->guardians->update($carlos['id'], [
            'name' => 'Carlos A. Souza', 'email' => 'Carlos.Novo@exemplo.com', 'phone' => '(11) 98888-0000', 'email_status' => 'BOUNCED',
        ]);
        $errors = self::errorsOf(fn () => $this->guardians->update($carlos['id'], [
            'name' => 'Carlos', 'email' => 'MARTA@exemplo.com', 'phone' => '(11) 98888-0000', 'email_status' => 'VALID',
        ]));

        self::assertSame('Carlos.Novo@exemplo.com', $updated['email']);
        self::assertSame('BOUNCED', $updated['email_status']);
        self::assertSame('carlos.novo@exemplo.com', Mongo::collection(Collections::GUARDIANS)->findOne(['name' => 'Carlos A. Souza'])['email_normalized']);
        self::assertSame('Já existe um responsável com este e-mail.', $errors['email']);
        self::assertCount(1, $this->audit('guardian.update'));
    }

    public function testIssue39ArchiveAndReactivateUseTheActiveFlag(): void
    {
        $guardian = $this->guardian();

        $this->guardians->archive($guardian['id']);
        self::assertSame([], $this->guardians->list(null, null, 1)['items']);
        self::assertSame([$guardian['id']], array_column($this->guardians->list(null, 'archived', 1)['items'], 'id'));

        $this->guardians->reactivate($guardian['id']);
        self::assertTrue($this->guardians->show($guardian['id'])['guardian']['active']);
        self::assertCount(1, $this->audit('guardian.archive'));
        self::assertCount(1, $this->audit('guardian.reactivate'));
        self::assertSame(1, Mongo::collection(Collections::GUARDIANS)->countDocuments());
    }

    public function testIssue39ShowListsTheLinkedStudentsWithTheirCurrentClass(): void
    {
        $class = $this->schoolClass($this->period()['id'], '7B');
        $ana = $this->student($class['id'], 'RA-1', 'Ana Souza');
        $bia = $this->student($class['id'], 'RA-2', 'Bia Souza');
        $guardian = $this->guardian();
        $this->links->linkExisting($ana['id'], ['guardian_id' => $guardian['id'], 'relationship' => 'MAE']);
        $this->links->linkExisting($bia['id'], ['guardian_id' => $guardian['id'], 'relationship' => 'MAE', 'receives_absence_notifications' => false]);

        $show = $this->guardians->show($guardian['id']);

        self::assertSame(['Ana Souza', 'Bia Souza'], array_column($show['students'], 'name'));
        self::assertSame('7B', $show['students'][0]['class_code']);
        self::assertSame([true, false], array_column($show['students'], 'receives_absence_notifications'));
        self::assertSame('MAE', $show['students'][1]['relationship']);
    }
}
