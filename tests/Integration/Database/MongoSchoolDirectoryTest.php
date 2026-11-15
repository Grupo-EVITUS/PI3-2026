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
    fwrite(STDOUT, "MongoSchoolDirectoryTest: cadastros, vínculos e autorização passaram.\n");
} finally {
    $database->drop();
}
