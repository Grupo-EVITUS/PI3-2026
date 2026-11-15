<?php
declare(strict_types=1);

use MongoDB\BSON\ObjectId;
use Saa\Database\InitialAdmin;
use Saa\Database\Mongo;
use Saa\Database\MongoSchoolDirectory;
use Saa\Database\Schema;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

function checkDirectory(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$database = Mongo::database('saa_directory_test_' . bin2hex(random_bytes(8)));
try {
    Schema::apply($database);
    $actor = InitialAdmin::create($database, 'Coordenação', 'coord@example.org', 'Senha-temporaria-2026');
    $directory = new MongoSchoolDirectory($database);
    $classId = $directory->createClass($actor, '5º A', '5º ano', 2026, 'Manhã');
    $studentId = $directory->createStudent($actor, 'Aluno Teste', null, $classId);
    $guardianId = $directory->createGuardian(
        $actor, 'Responsável Teste', 'responsavel@example.org', 'Mãe', [$studentId, $studentId]
    );
    $secondGuardian = $directory->createGuardian(
        $actor, 'Outro Responsável', 'responsavel@example.org', 'Pai', [$studentId]
    );

    checkDirectory($database->classes->countDocuments(['_id' => $classId]) === 1, 'Turma deve existir.');
    checkDirectory($database->students->countDocuments(['_id' => $studentId, 'class_id' => $classId]) === 1,
        'Aluno deve referenciar turma válida.');
    checkDirectory($database->student_guardians->countDocuments(['student_id' => $studentId]) === 2,
        'Dois responsáveis com mesmo e-mail são válidos, sem vínculo duplicado.');
    checkDirectory($database->operation_logs->countDocuments(['user_id' => $actor, 'entity_id' => (string)$guardianId]) === 1,
        'Cadastro e auditoria devem usar o mesmo ator e entidade.');
    checkDirectory($database->guardians->countDocuments(['_id' => $secondGuardian]) === 1,
        'Responsável com e-mail compartilhado deve existir.');

    $count = $database->guardians->countDocuments();
    try {
        $directory->createGuardian($actor, 'Inválido', 'outro@example.org', 'Pai', [new ObjectId()]);
        throw new LogicException('Aluno ausente deveria ser rejeitado.');
    } catch (InvalidArgumentException $error) {
        checkDirectory($error->getMessage() === 'Um ou mais alunos não estão disponíveis.', 'Esperada rejeição de vínculo.');
    }
    checkDirectory($database->guardians->countDocuments() === $count,
        'Vínculo inválido não pode deixar responsável órfão.');

    try {
        $directory->createClass($studentId, 'Turma não autorizada', '5º ano', 2026, 'Tarde');
        throw new LogicException('Ator sem autorização deveria ser rejeitado.');
    } catch (RuntimeException $error) {
        checkDirectory($error->getMessage() === 'Cadastro permitido somente à coordenação ativa.',
            'Somente coordenação pode cadastrar.');
    }
    checkDirectory($database->classes->countDocuments() === 1, 'Ator não autorizado não pode criar turma.');
    $directory->updateClass($actor, $classId, '5º B', '5º ano', 2026, 'Tarde');
    $directory->updateStudent($actor, $studentId, 'Aluno Atualizado', 'T-2026-1', $classId);
    $directory->updateGuardian(
        $actor, $guardianId, 'Responsável Atualizado', 'novo@example.org', 'Pai', [$studentId, $studentId]
    );
    checkDirectory($database->classes->countDocuments(['_id' => $classId, 'name' => '5º B']) === 1,
        'Edição de turma deve persistir.');
    checkDirectory($database->students->countDocuments(['_id' => $studentId, 'name' => 'Aluno Atualizado']) === 1,
        'Edição de aluno deve persistir.');
    checkDirectory($database->student_guardians->countDocuments(['guardian_id' => $guardianId]) === 1,
        'Edição deve deduplicar o vínculo.');
    try {
        $directory->archive($actor, 'turma', $classId);
        throw new LogicException('Turma com aluno ativo deveria ser protegida.');
    } catch (InvalidArgumentException $error) {
        checkDirectory(str_contains($error->getMessage(), 'alunos ativos'), 'Esperada proteção da turma.');
    }
    checkDirectory($database->classes->countDocuments(['_id' => $classId, 'active' => true]) === 1,
        'Tentativa de arquivar turma deve ser revertida.');
    $directory->archive($actor, 'aluno', $studentId);
    $directory->archive($actor, 'turma', $classId);
    $directory->archive($actor, 'responsavel', $guardianId);
    checkDirectory($database->classes->countDocuments(['_id' => $classId, 'active' => false]) === 1
        && $database->students->countDocuments(['_id' => $studentId, 'active' => false]) === 1
        && $database->guardians->countDocuments(['_id' => $guardianId, 'active' => false]) === 1,
        'Arquivamento deve manter registros e suas referências.');
    checkDirectory($database->operation_logs->countDocuments(['action' => 'TURMA_ARQUIVADA', 'entity_id' => (string)$classId]) === 1,
        'Arquivamento deve gerar auditoria.');
    $directory->updateGuardian($actor, $secondGuardian, 'Outro Responsável Atualizado',
        'responsavel@example.org', 'Pai', []);
    checkDirectory($database->student_guardians->countDocuments(['guardian_id' => $secondGuardian, 'student_id' => $studentId]) === 1,
        'Editar responsável deve preservar vínculo histórico com aluno arquivado.');
    $raceClass = $directory->createClass($actor, 'Turma concorrente', '5º ano', 2026, 'Manhã');
    $archiveSession = Mongo::client()->startSession();
    try {
        $archiveSession->startTransaction();
        checkDirectory($database->students->countDocuments(
            ['class_id' => $raceClass, 'active' => true], ['session' => $archiveSession]
        ) === 0, 'Snapshot de arquivamento deve começar sem alunos.');
        $raceStudent = $directory->createStudent($actor, 'Aluno simultâneo', 'RACE-1', $raceClass);
        $conflict = false;
        try {
            $database->classes->updateOne(
                ['_id' => $raceClass, 'active' => true], ['$set' => ['active' => false]],
                ['session' => $archiveSession]
            );
        } catch (Throwable $error) {
            $conflict = $error->getCode() === 112
                || (method_exists($error, 'hasErrorLabel') && $error->hasErrorLabel('TransientTransactionError'));
        }
        checkDirectory($conflict, 'Arquivamento com snapshot antigo deve sofrer conflito de escrita.');
    } finally {
        try {
            $archiveSession->abortTransaction();
        } catch (Throwable) {
            // O servidor pode já ter abortado a transação ao detectar o conflito.
        }
        $archiveSession->endSession();
    }
    checkDirectory($database->classes->countDocuments(['_id' => $raceClass, 'active' => true]) === 1
        && $database->students->countDocuments(['_id' => $raceStudent, 'class_id' => $raceClass]) === 1,
        'Disputa deve preservar turma ativa com o novo aluno.');
    $studentArchiveSession = Mongo::client()->startSession();
    try {
        $studentArchiveSession->startTransaction();
        checkDirectory($database->students->countDocuments(
            ['_id' => $raceStudent, 'active' => true], ['session' => $studentArchiveSession]
        ) === 1, 'Aluno deve estar ativo antes da disputa.');
        $raceGuardian = $directory->createGuardian(
            $actor, 'Responsável simultâneo', 'simultaneo@example.org', 'Pai', [$raceStudent]
        );
        $conflict = false;
        try {
            $database->students->updateOne(
                ['_id' => $raceStudent, 'active' => true], ['$set' => ['active' => false]],
                ['session' => $studentArchiveSession]
            );
        } catch (Throwable $error) {
            $conflict = $error->getCode() === 112
                || (method_exists($error, 'hasErrorLabel') && $error->hasErrorLabel('TransientTransactionError'));
        }
        checkDirectory($conflict, 'Arquivamento de aluno deve disputar o novo vínculo.');
    } finally {
        try {
            $studentArchiveSession->abortTransaction();
        } catch (Throwable) {
        }
        $studentArchiveSession->endSession();
    }
    checkDirectory($database->students->countDocuments(['_id' => $raceStudent, 'active' => true]) === 1
        && $database->student_guardians->countDocuments(
            ['student_id' => $raceStudent, 'guardian_id' => $raceGuardian]
        ) === 1, 'Disputa deve manter aluno ativo e vínculo confirmado.');
    fwrite(STDOUT, "MongoSchoolDirectoryTest: cadastros, vínculos e autorização passaram.\n");
} finally {
    $database->drop();
}
