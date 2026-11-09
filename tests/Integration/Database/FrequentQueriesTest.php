<?php
declare(strict_types=1);

namespace Saa\Tests\Integration\Database;

use DateTimeImmutable;
use DateTimeZone;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Driver\Monitoring\CommandFailedEvent;
use MongoDB\Driver\Monitoring\CommandStartedEvent;
use MongoDB\Driver\Monitoring\CommandSubscriber;
use MongoDB\Driver\Monitoring\CommandSucceededEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Saa\Database\Bson;
use Saa\Database\Collections;
use Saa\Database\Mongo;
use Saa\Domain\AttendanceStatus;
use Saa\Tests\Support\MongoTestCase;
use stdClass;
use Throwable;

/**
 * As três consultas frequentes da atividade de modelagem (seção 5), executadas com dados reais.
 *
 *   (1) localizar o aluno + responsáveis com e-mail válido + faltas ........ UMA agregação com $lookup
 *   (2) registrar uma falta ................................................. UMA leitura + UMA gravação
 *   (3) dashboard por turma / mês / ano ..................................... UMA agregação ($facet), sem $lookup
 *
 * O número de buscas não é suposição: um CommandSubscriber do driver registra os comandos enviados ao MongoDB
 * e os testes conferem a lista exata (['aggregate'], ['find', 'insert'], ['aggregate']).
 * Os pipelines abaixo são os mesmos que docs/MODELAGEM_MONGODB.md apresenta em mongosh.
 */
final class FrequentQueriesTest extends MongoTestCase
{
    private ObjectId $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $now = Bson::now();
        $this->userId = $this->insert(Collections::USERS, [
            'name' => 'Mariana Lima', 'email' => 'aoe@escola.com', 'email_normalized' => 'aoe@escola.com',
            'password_hash' => '$2y$10$' . str_repeat('a', 53), 'profile' => 'AOE', 'active' => true,
            'password_changed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    // ================================================================================== os pipelines (exatos)

    /**
     * Consulta 1. Uma agregação em students; os dois $lookup rodam no servidor.
     * O primeiro traz só os responsáveis que recebem aviso, estão ativos e têm e-mail VALID; o segundo, as 30 faltas
     * mais recentes (ABSENT ou EXCUSED), pelo índice uq_attendance_student_date lido de trás para frente.
     *
     * @return list<array<string, mixed>>
     */
    public static function studentContextPipeline(ObjectId $studentId): array
    {
        return [
            ['$match' => ['_id' => $studentId, 'active' => true]],
            ['$addFields' => [
                'notify_ids' => [
                    '$map' => [
                        'input' => ['$filter' => [
                            'input' => '$guardians',
                            'as' => 'g',
                            'cond' => ['$eq' => ['$$g.receives_absence_notifications', true]],
                        ]],
                        'as' => 'g',
                        'in' => '$$g.guardian_id',
                    ],
                ],
            ]],
            ['$lookup' => [
                'from' => Collections::GUARDIANS,
                'localField' => 'notify_ids',
                'foreignField' => '_id',
                'pipeline' => [
                    ['$match' => ['active' => true, 'email_status' => 'VALID']],
                    ['$project' => ['name' => 1, 'email' => 1]],
                ],
                'as' => 'guardians_to_notify',
            ]],
            ['$lookup' => [
                'from' => Collections::ATTENDANCE_RECORDS,
                'localField' => '_id',
                'foreignField' => 'student_id',
                'pipeline' => [
                    ['$match' => ['status' => ['$in' => AttendanceStatus::absenceValues()]]],
                    ['$sort' => ['date' => -1]],
                    ['$limit' => 30],
                    ['$project' => ['date' => 1, 'status' => 1, 'class_name' => 1, 'justification' => 1]],
                ],
                'as' => 'absences',
            ]],
            ['$project' => ['name' => 1, 'student_code' => 1, 'guardians_to_notify' => 1, 'absences' => 1]],
        ];
    }

    /**
     * Consulta 3. Uma agregação em attendance_records e nenhum $lookup: a turma já vem copiada no registro.
     * EXCUSED conta como falta; $addToSet conta cada aluno uma vez; $$REMOVE tira as presenças da lista de faltantes.
     * O intervalo é [$from, $until): meia-noite UTC do primeiro dia até meia-noite UTC do dia seguinte ao último.
     *
     * @return list<array<string, mixed>>
     */
    public static function dashboardPipeline(UTCDateTime $from, UTCDateTime $until): array
    {
        $absentStudentsBy = static fn (string $format): array => [
            ['$match' => ['is_absence' => true]],
            ['$group' => [
                '_id' => ['$dateToString' => ['format' => $format, 'date' => '$date']],
                'faltas' => ['$sum' => 1],
                'alunos' => ['$addToSet' => '$student_id'],
            ]],
            ['$project' => [
                'faltas' => 1,
                'alunos_faltantes' => ['$size' => '$alunos'],
                'media_faltas_por_aluno' => ['$round' => [['$divide' => ['$faltas', ['$size' => '$alunos']]], 2]],
            ]],
            ['$sort' => ['_id' => 1]],
        ];

        return [
            ['$match' => ['date' => ['$gte' => $from, '$lt' => $until]]],
            ['$addFields' => ['is_absence' => ['$in' => ['$status', AttendanceStatus::absenceValues()]]]],
            ['$facet' => [
                'por_turma' => [
                    ['$group' => [
                        '_id' => '$class_id',
                        'class_name' => ['$first' => '$class_name'],
                        'registros' => ['$sum' => 1],
                        'faltas' => ['$sum' => ['$cond' => ['$is_absence', 1, 0]]],
                        'faltantes' => ['$addToSet' => ['$cond' => ['$is_absence', '$student_id', '$$REMOVE']]],
                    ]],
                    ['$project' => [
                        'class_name' => 1,
                        'faltas' => 1,
                        'alunos_faltantes' => ['$size' => '$faltantes'],
                        'percentual_presenca' => ['$round' => [
                            ['$multiply' => [
                                ['$divide' => [['$subtract' => ['$registros', '$faltas']], '$registros']],
                                100,
                            ]],
                            1,
                        ]],
                    ]],
                    ['$sort' => ['faltas' => -1, 'class_name' => 1]],
                ],
                'por_mes' => $absentStudentsBy('%Y-%m'),
                'por_ano' => $absentStudentsBy('%Y'),
            ]],
        ];
    }

    // ================================================================================== as três operações

    /** Consulta 1: devolve null se o aluno não existe ou está arquivado. @return array<string, mixed>|null */
    private function findStudentContext(ObjectId $studentId): ?array
    {
        $rows = Mongo::collection(Collections::STUDENTS)->aggregate(self::studentContextPipeline($studentId))->toArray();
        return $rows[0] ?? null;
    }

    /**
     * Consulta 2: UMA leitura (a matrícula ativa traz turma e período) e UMA gravação (a frequência).
     * O nome da turma chega da tela: a AOE escolhe a turma antes do aluno, então não há leitura em classes.
     * Devolve null (sem gravar nada) se o aluno não tem matrícula ativa. A duplicidade explode no índice único.
     */
    private function registerAbsence(ObjectId $studentId, string $ymd, string $className, ObjectId $userId, string $note): ?ObjectId
    {
        $enrollment = Mongo::collection(Collections::ENROLLMENTS)->findOne(
            ['student_id' => $studentId, 'active' => true],
            ['projection' => ['class_id' => 1, 'academic_period_id' => 1]]
        );
        if ($enrollment === null) {
            return null;
        }

        $now = Bson::now();
        $result = Mongo::collection(Collections::ATTENDANCE_RECORDS)->insertOne([
            'enrollment_id' => $enrollment['_id'],
            'student_id' => $studentId,
            'class_id' => $enrollment['class_id'],
            'class_name' => $className,
            'academic_period_id' => $enrollment['academic_period_id'],
            'date' => Bson::day($ymd),
            'status' => AttendanceStatus::ABSENT->value,
            'registered_by' => $userId,
            'details' => ['source' => 'INTERNAL_MESSAGE', 'note' => $note],
            'justification' => [
                'status' => 'PENDING',
                'guardian_id' => null,
                'reason_code' => null,
                'explanation' => null,
                'submitted_at' => null,
                'notification_id' => null,
                'status_history' => [['status' => 'PENDING', 'at' => $now, 'by_user_id' => $userId, 'by_guardian_id' => null]],
            ],
            'created_at' => $now,
        ]);
        $id = $result->getInsertedId();
        return $id instanceof ObjectId ? $id : null;
    }

    /** Consulta 3. @return array{por_turma: list<array<string, mixed>>, por_mes: list<array<string, mixed>>, por_ano: list<array<string, mixed>>} */
    private function dashboard(string $from, string $until): array
    {
        $rows = Mongo::collection(Collections::ATTENDANCE_RECORDS)
            ->aggregate(self::dashboardPipeline(Bson::day($from), Bson::day($until)))
            ->toArray();
        self::assertCount(1, $rows, '$facet devolve sempre exatamente um documento');
        return $rows[0];
    }

    // ================================================================================== consulta 1

    #[Group('MD-20')]
    #[Group('MD-04')]
    public function testStudentContextComesFromOneAggregationWithTwoLookups(): void
    {
        $ana = $this->seedStudentWithGuardiansAndAbsences();

        [$context, $commands] = $this->recorded(fn (): ?array => $this->findStudentContext($ana));

        // UMA busca: uma única agregação em students, com os dois $lookup dentro dela
        self::assertSame([['aggregate', Collections::STUDENTS]], array_map(static fn (array $c): array => [$c[0], $c[1]], $commands));
        self::assertSame(2, substr_count($commands[0][2], '"$lookup"'));
        self::assertNotNull($context);

        self::assertSame('Ana Beatriz Souza', $context['name']);
        self::assertSame('RA-2026-0001', $context['student_code']);
        // a projeção final só deixa passar o que a tela usa
        self::assertEqualsCanonicalizing(['_id', 'name', 'student_code', 'guardians_to_notify', 'absences'], array_keys($context));

        // responsáveis: Carlos (VALID, recebe aviso). Marina não recebe aviso; Pedro tem e-mail BOUNCED.
        self::assertCount(1, $context['guardians_to_notify']);
        self::assertSame('Carlos Souza', $context['guardians_to_notify'][0]['name']);
        self::assertSame('Carlos.Souza@exemplo.com', $context['guardians_to_notify'][0]['email']);
        self::assertEqualsCanonicalizing(['_id', 'name', 'email'], array_keys($context['guardians_to_notify'][0]));

        // faltas: as 30 mais recentes, da mais nova para a mais antiga, sem presenças e sem faltas de outros alunos
        $absences = $context['absences'];
        self::assertCount(30, $absences);
        $dates = array_map(static fn (array $a): string => (string)Bson::ymd($a['date']), $absences);
        self::assertSame('2026-03-08', $dates[0]);
        self::assertSame('2026-02-07', $dates[29]);
        $descending = $dates;
        rsort($descending);
        self::assertSame($descending, $dates);
        self::assertSame(['ABSENT', 'EXCUSED'], $this->sorted(array_values(array_unique(array_column($absences, 'status')))));
        self::assertSame(6, count(array_filter($absences, static fn (array $a): bool => $a['status'] === 'EXCUSED')));
        self::assertSame(['6º Ano A'], array_values(array_unique(array_column($absences, 'class_name'))));
        // a justificativa embutida vem junto, sem outra busca
        self::assertSame('RESPONDED', $absences[0]['justification']['status']);
        self::assertSame('MEDICAL', $absences[0]['justification']['reason_code']);
        self::assertSame('PENDING', $absences[1]['justification']['status']);
    }

    #[Group('MD-04')]
    public function testGuardiansToNotifyAreOnlyActiveValidEmailsOfGuardiansWhoReceiveNotices(): void
    {
        $period = $this->period('2026', '2026-02-02', '2026-12-18', true);
        $class = $this->classroom($period, '6A', '6º Ano A');
        $carlos = $this->guardian('Carlos Souza', 'carlos@exemplo.com');
        $joana = $this->guardian('Joana Prado', 'joana@exemplo.com', 'UNVERIFIED');
        $pedro = $this->guardian('Pedro Prado', 'pedro@exemplo.com', 'BOUNCED');
        $lucia = $this->guardian('Lúcia Prado', 'lucia@exemplo.com');
        $ex = $this->guardian('Ex-responsável', 'ex@exemplo.com', 'VALID', false);

        $bruno = $this->student('Bruno Prado', 'RA-2026-0002', [
            [$joana, 'MAE', true, true],          // e-mail não verificado: fica de fora
            [$ex, 'PAI', false, true],            // cadastro arquivado: fica de fora
            [$lucia, 'RESPONSAVEL', false, true], // o único que recebe
        ]);
        $dario = $this->student('Dário Prado', 'RA-2026-0003', [[$carlos, 'PAI', true, false]]); // não recebe avisos
        $eva = $this->student('Eva Prado', 'RA-2026-0004', []);                                   // sem responsáveis
        $fabio = $this->student('Fábio Prado', 'RA-2026-0005', [
            [$carlos, 'PAI', true, true], [$lucia, 'MAE', false, true], [$pedro, 'RESPONSAVEL', false, true],
        ]);
        foreach ([$bruno, $dario, $eva, $fabio] as $student) {
            $this->enroll($student, $class, $period, '2026-02-02');
        }

        $names = fn (ObjectId $student): array => $this->sorted(array_column($this->findStudentContext($student)['guardians_to_notify'] ?? [], 'name'));
        self::assertSame(['Lúcia Prado'], $names($bruno));
        self::assertSame([], $names($dario));
        self::assertSame([], $names($eva));
        // dois irmãos compartilham os mesmos responsáveis sem duplicar o contato (guardians é referenciado)
        self::assertSame(['Carlos Souza', 'Lúcia Prado'], $names($fabio));
        self::assertSame(
            ['Carlos Souza'],
            $this->sorted(array_column(
                Mongo::collection(Collections::GUARDIANS)->find(['email_normalized' => 'carlos@exemplo.com'])->toArray(),
                'name'
            )),
            'Carlos existe uma única vez em guardians, apesar de aparecer em dois alunos'
        );
    }

    #[Group('MD-11')]
    public function testStudentContextIsEmptyForUnknownAndArchivedStudents(): void
    {
        $period = $this->period('2026', '2026-02-02', '2026-12-18', true);
        $class = $this->classroom($period, '6A', '6º Ano A');
        $archived = $this->student('Aluna Arquivada', 'RA-2026-0009', [], false);
        $this->enroll($archived, $class, $period, '2026-02-02');

        self::assertNull($this->findStudentContext($archived));
        self::assertNull($this->findStudentContext(new ObjectId()));
        // arquivar não apaga: o cadastro continua lá, só fica fora das buscas operacionais
        self::assertSame(1, Mongo::collection(Collections::STUDENTS)->countDocuments(['_id' => $archived, 'active' => false]));
    }

    #[Group('MD-10')]
    public function testStudentContextLookupsUseIndexesWithoutCollectionScans(): void
    {
        $ana = $this->seedStudentWithGuardiansAndAbsences();

        $reply = $this->db()->command([
            'explain' => ['aggregate' => Collections::STUDENTS, 'pipeline' => self::studentContextPipeline($ana), 'cursor' => new stdClass()],
            'verbosity' => 'executionStats',
        ])->toArray()[0];

        $lookups = array_values(array_filter($reply['stages'] ?? [], static fn (mixed $stage): bool => is_array($stage) && array_key_exists('$lookup', $stage)));
        self::assertCount(2, $lookups, 'a explicação deve trazer os dois $lookup');
        if (!array_key_exists('indexesUsed', $lookups[0])) {
            self::markTestSkipped('esta versão do MongoDB não informa indexesUsed no $lookup do explain');
        }
        foreach ($lookups as $stage) {
            self::assertSame(0, $stage['collectionScans'], '$lookup sem varredura da coleção inteira');
            self::assertNotEmpty($stage['indexesUsed'], '$lookup resolvido por índice');
        }
        // responsáveis: com poucos documentos o planejador pode preferir (active, name) ao _id; ambos são índices
        self::assertContains($lookups[0]['indexesUsed'][0], ['_id_', 'ix_guardians_active_name']);
        // faltas: só o índice único (aluno, dia) serve, lido de trás para frente para trazer as mais recentes
        self::assertSame(['uq_attendance_student_date'], $lookups[1]['indexesUsed']);
    }

    // ================================================================================== consulta 2

    #[Group('MD-20')]
    public function testRegisterAbsenceTakesOneReadOfTheActiveEnrollmentAndOneWrite(): void
    {
        [$ana, $enrollment, $class, $period] = $this->seedEnrolledStudent();

        [$recordId, $commands] = $this->recorded(fn (): ?ObjectId => $this->registerAbsence($ana, '2026-10-09', '6º Ano A', $this->userId, 'Informada pela professora.'));

        // UMA leitura (matrícula ativa) e UMA gravação (frequência); nada em classes, students ou guardians
        self::assertSame(
            [['find', Collections::ENROLLMENTS], ['insert', Collections::ATTENDANCE_RECORDS]],
            array_map(static fn (array $c): array => [$c[0], $c[1]], $commands)
        );
        self::assertStringContainsString('"active":true', $commands[0][2]);
        self::assertNotNull($recordId);

        $stored = Mongo::collection(Collections::ATTENDANCE_RECORDS)->findOne(['_id' => $recordId]);
        self::assertIsArray($stored);
        self::assertSame((string)$enrollment, (string)$stored['enrollment_id']);
        self::assertSame((string)$class, (string)$stored['class_id']);
        self::assertSame((string)$period, (string)$stored['academic_period_id']);
        self::assertSame('6º Ano A', $stored['class_name']);
        self::assertSame('2026-10-09', Bson::ymd($stored['date']));
        self::assertSame('ABSENT', $stored['status']);
        self::assertSame((string)$this->userId, (string)$stored['registered_by']);
        self::assertSame('Informada pela professora.', $stored['details']['note']);
        self::assertSame('PENDING', $stored['justification']['status']);
        self::assertCount(1, $stored['justification']['status_history']);
    }

    #[Group('MD-03')]
    public function testDuplicateAbsenceIsRejectedAndTheFirstRecordIsKept(): void
    {
        [$ana, , $class, $period] = $this->seedEnrolledStudent();
        $bia = $this->student('Bia Souza', 'RA-2026-0010', []);
        $this->enroll($bia, $class, $period, '2026-02-02');

        $first = $this->registerAbsence($ana, '2026-10-09', '6º Ano A', $this->userId, 'primeiro lançamento');
        self::assertNotNull($first);

        [$second, $commands, $error] = $this->recorded(fn (): ?ObjectId => $this->registerAbsence($ana, '2026-10-09', '6º Ano A', $this->userId, 'lançamento repetido'));

        self::assertNull($second);
        self::assertInstanceOf(BulkWriteException::class, $error);
        self::assertSame(11000, $error->getWriteResult()->getWriteErrors()[0]->getCode(), 'chave duplicada (aluno, dia)');
        self::assertSame(['find', 'insert'], array_column($commands, 0), 'a duplicidade só aparece na gravação: o índice único decide, sem checar antes');

        $records = Mongo::collection(Collections::ATTENDANCE_RECORDS);
        self::assertSame(1, $records->countDocuments(['student_id' => $ana]));
        $kept = $records->findOne(['student_id' => $ana]);
        self::assertSame((string)$first, (string)$kept['_id']);
        self::assertSame('primeiro lançamento', $kept['details']['note']);

        // o mesmo dia para OUTRO aluno e outro dia para o MESMO aluno são lançamentos legítimos
        self::assertNotNull($this->registerAbsence($bia, '2026-10-09', '6º Ano A', $this->userId, 'outra aluna'));
        self::assertNotNull($this->registerAbsence($ana, '2026-10-10', '6º Ano A', $this->userId, 'outro dia'));
        self::assertSame(3, $records->countDocuments([]));
    }

    #[Group('MD-02')]
    public function testNoAbsenceIsWrittenWithoutAnActiveEnrollment(): void
    {
        $period = $this->period('2026', '2026-02-02', '2026-12-18', true);
        $class = $this->classroom($period, '6A', '6º Ano A');
        $transferred = $this->student('Aluno Transferido', 'RA-2026-0011', []);
        $this->enroll($transferred, $class, $period, '2026-02-02', '2026-04-30', 'TRANSFER'); // só matrícula encerrada
        $neverEnrolled = $this->student('Aluno Sem Matrícula', 'RA-2026-0012', []);

        foreach ([$transferred, $neverEnrolled, new ObjectId()] as $student) {
            [$result, $commands] = $this->recorded(fn (): ?ObjectId => $this->registerAbsence($student, '2026-10-09', '6º Ano A', $this->userId, 'x'));
            self::assertNull($result);
            self::assertSame(['find'], array_column($commands, 0), 'sem matrícula ativa a leitura termina a operação: nenhuma gravação');
        }
        self::assertSame(0, Mongo::collection(Collections::ATTENDANCE_RECORDS)->countDocuments([]));
    }

    #[Group('MD-02')]
    #[Group('MD-13')]
    public function testClassChangeKeepsHistoricalAbsencesWithTheirOriginalClass(): void
    {
        $period = $this->period('2026', '2026-02-02', '2026-12-18', true);
        $classA = $this->classroom($period, '6A', '6º Ano A');
        $classB = $this->classroom($period, '6B', '6º Ano B', 'AFTERNOON');
        $ana = $this->student('Ana Beatriz Souza', 'RA-2026-0001', []);
        $firstEnrollment = $this->enroll($ana, $classA, $period, '2026-02-02');

        // 1) falta na 6A
        self::assertNotNull($this->registerAbsence($ana, '2026-03-02', '6º Ano A', $this->userId, 'na 6A'));

        // 2) matricular na 6B com a matrícula da 6A ainda ativa é impossível (uma ativa por aluno)
        try {
            $this->enroll($ana, $classB, $period, '2026-03-10');
            self::fail('a segunda matrícula ativa deveria ser recusada');
        } catch (BulkWriteException $e) {
            self::assertSame(11000, $e->getWriteResult()->getWriteErrors()[0]->getCode());
        }

        // 3) troca de turma = encerrar a matrícula atual e criar outra (nada é apagado ou sobrescrito)
        Mongo::collection(Collections::ENROLLMENTS)->updateOne(
            ['_id' => $firstEnrollment],
            ['$set' => ['active' => false, 'end_date' => Bson::day('2026-03-09'), 'end_reason' => 'CLASS_CHANGE']]
        );
        $secondEnrollment = $this->enroll($ana, $classB, $period, '2026-03-10');

        // 4) a 6A é renomeada depois
        Mongo::collection(Collections::CLASSES)->updateOne(['_id' => $classA], ['$set' => ['name' => '6º Ano A (renomeada)']]);

        // 5) falta já na 6B
        $recordB = $this->registerAbsence($ana, '2026-03-12', '6º Ano B', $this->userId, 'na 6B');
        self::assertNotNull($recordB);
        $stored = Mongo::collection(Collections::ATTENDANCE_RECORDS)->findOne(['_id' => $recordB]);
        self::assertSame((string)$classB, (string)$stored['class_id']);
        self::assertSame((string)$secondEnrollment, (string)$stored['enrollment_id']);

        // a consulta 1 mostra cada falta com a turma DAQUELE dia, inclusive depois da renomeação
        $absences = $this->findStudentContext($ana)['absences'] ?? [];
        self::assertSame(
            [['2026-03-12', '6º Ano B'], ['2026-03-02', '6º Ano A']],
            array_map(static fn (array $a): array => [(string)Bson::ymd($a['date']), $a['class_name']], $absences)
        );

        // o painel agrupa pela turma do dia, sem $lookup: uma falta em cada turma
        $dashboard = $this->dashboard('2026-03-01', '2026-04-01');
        self::assertSame(
            [['6º Ano A', 1], ['6º Ano B', 1]],
            array_map(static fn (array $row): array => [$row['class_name'], $row['faltas']], $dashboard['por_turma'])
        );

        // histórico de matrículas do aluno: da mais recente para a mais antiga, as duas preservadas
        $history = Mongo::collection(Collections::ENROLLMENTS)
            ->find(['student_id' => $ana], ['sort' => ['start_date' => -1]])
            ->toArray();
        self::assertSame(
            [[(string)$classB, true, null], [(string)$classA, false, 'CLASS_CHANGE']],
            array_map(static fn (array $e): array => [(string)$e['class_id'], $e['active'], $e['end_reason']], $history)
        );
    }

    // ================================================================================== consulta 3

    #[Group('MD-20')]
    #[Group('MD-05')]
    public function testDashboardCountsByClassMonthAndYearWithOneAggregationAndNoLookup(): void
    {
        $this->seedDashboardData();

        [$dashboard, $commands] = $this->recorded(fn (): array => $this->dashboard('2026-02-01', '2027-01-01'));

        // UMA busca, só em attendance_records, sem $lookup; o $facet calcula os três gráficos de uma vez
        self::assertSame([['aggregate', Collections::ATTENDANCE_RECORDS]], array_map(static fn (array $c): array => [$c[0], $c[1]], $commands));
        self::assertStringNotContainsString('$lookup', $commands[0][2]);
        self::assertStringContainsString('"$facet"', $commands[0][2]);
        self::assertStringContainsString('"$addToSet"', $commands[0][2]);
        self::assertEqualsCanonicalizing(['por_turma', 'por_mes', 'por_ano'], array_keys($dashboard));

        // por turma, da que mais falta para a que menos falta (24 registros no intervalo)
        $turmas = $dashboard['por_turma'];
        self::assertSame(['6º Ano A', '6º Ano B', '6º Ano C'], array_column($turmas, 'class_name'));
        self::assertSame([4, 2, 0], array_column($turmas, 'faltas'));
        self::assertSame([2, 2, 0], array_column($turmas, 'alunos_faltantes'));
        foreach ([66.7, 77.8, 100.0] as $i => $percent) {
            self::assertEqualsWithDelta($percent, $turmas[$i]['percentual_presenca'], 0.0001, $turmas[$i]['class_name']);
        }

        // por mês
        $meses = $dashboard['por_mes'];
        self::assertSame(['2026-02', '2026-03'], array_column($meses, '_id'));
        self::assertSame([4, 2], array_column($meses, 'faltas'));
        self::assertSame([3, 2], array_column($meses, 'alunos_faltantes'));
        self::assertEqualsWithDelta(1.33, $meses[0]['media_faltas_por_aluno'], 0.0001);
        self::assertEqualsWithDelta(1.0, $meses[1]['media_faltas_por_aluno'], 0.0001);

        // por ano
        $anos = $dashboard['por_ano'];
        self::assertSame(['2026'], array_column($anos, '_id'));
        self::assertSame([6], array_column($anos, 'faltas'));
        self::assertSame([4], array_column($anos, 'alunos_faltantes'));
        self::assertEqualsWithDelta(1.5, $anos[0]['media_faltas_por_aluno'], 0.0001);
    }

    #[Group('MD-05')]
    public function testDashboardCountsEachStudentOnceAndExcusedStillCountsAsAbsence(): void
    {
        $ids = $this->seedDashboardData();
        $classA = $this->dashboard('2026-02-01', '2027-01-01')['por_turma'][0];

        // A1 faltou 3 vezes (ABSENT, EXCUSED, ABSENT) e A2 uma: são 4 faltas de 2 alunos distintos
        self::assertSame('6º Ano A', $classA['class_name']);
        self::assertSame(4, $classA['faltas']);
        self::assertSame(2, $classA['alunos_faltantes'], '$addToSet conta o aluno uma vez, não uma vez por falta');

        // a falta EXCUSED de A1 (02-03) vira presença: o painel passa a ter UMA falta a menos
        Mongo::collection(Collections::ATTENDANCE_RECORDS)->updateOne(
            ['student_id' => $ids['A1'], 'date' => Bson::day('2026-02-03')],
            ['$set' => ['status' => 'PRESENT', 'justification' => null]]
        );
        $after = $this->dashboard('2026-02-01', '2027-01-01');
        self::assertSame(3, $after['por_turma'][0]['faltas'], 'EXCUSED contava como falta');
        self::assertSame(2, $after['por_turma'][0]['alunos_faltantes']);
        self::assertEqualsWithDelta(75.0, $after['por_turma'][0]['percentual_presenca'], 0.0001);
        self::assertSame([3, 2], array_column($after['por_mes'], 'faltas'));
        self::assertEqualsWithDelta(1.0, $after['por_mes'][0]['media_faltas_por_aluno'], 0.0001);
    }

    #[Group('MD-05')]
    public function testDashboardCanSpanSeveralYearsAndIgnoresRecordsOutsideTheRange(): void
    {
        $this->seedDashboardData();

        // 2025-11-10 e 2027-02-08 ficam de fora do intervalo de 2026...
        $only2026 = $this->dashboard('2026-02-01', '2027-01-01');
        self::assertSame(['2026'], array_column($only2026['por_ano'], '_id'));
        self::assertNotContains('5º Ano A', array_column($only2026['por_turma'], 'class_name'));

        // ...e entram quando o intervalo cobre os três anos
        $wide = $this->dashboard('2025-01-01', '2028-01-01');
        self::assertSame(['2025', '2026', '2027'], array_column($wide['por_ano'], '_id'));
        self::assertSame([1, 6, 1], array_column($wide['por_ano'], 'faltas'));
        self::assertSame([1, 4, 1], array_column($wide['por_ano'], 'alunos_faltantes'));
        self::assertEqualsWithDelta(1.5, $wide['por_ano'][1]['media_faltas_por_aluno'], 0.0001);
        self::assertSame(['2025-11', '2026-02', '2026-03', '2027-02'], array_column($wide['por_mes'], '_id'));
        self::assertContains('5º Ano A', array_column($wide['por_turma'], 'class_name'));
        self::assertContains('7º Ano A', array_column($wide['por_turma'], 'class_name'));
        // a turma que só tem uma falta e nenhuma presença registrada fica com 0% de presença
        $byName = array_column($wide['por_turma'], null, 'class_name');
        self::assertEqualsWithDelta(0.0, $byName['5º Ano A']['percentual_presenca'], 0.0001);
    }

    #[Group('MD-20')]
    public function testDashboardWithoutRecordsReturnsThreeEmptyLists(): void
    {
        $dashboard = $this->dashboard('2026-02-01', '2027-01-01');

        self::assertSame(['por_turma' => [], 'por_mes' => [], 'por_ano' => []], $dashboard);
    }

    #[Group('MD-10')]
    public function testDashboardMatchUsesTheDateStatusIndex(): void
    {
        $this->seedDashboardData();

        $plan = $this->queryPlan(Collections::ATTENDANCE_RECORDS, [
            'date' => ['$gte' => Bson::day('2026-02-01'), '$lt' => Bson::day('2027-01-01')],
        ]);
        self::assertContains('ix_attendance_date_status', $plan['indexes']);
        self::assertNotContains('COLLSCAN', $plan['stages']);

        // o mesmo $match, dentro da agregação do painel: o índice é escolhido antes do $facet
        $reply = $this->db()->command([
            'explain' => [
                'aggregate' => Collections::ATTENDANCE_RECORDS,
                'pipeline' => self::dashboardPipeline(Bson::day('2026-02-01'), Bson::day('2027-01-01')),
                'cursor' => new stdClass(),
            ],
            'verbosity' => 'queryPlanner',
        ])->toArray()[0];
        $json = (string)json_encode($reply);
        self::assertStringContainsString('"indexName":"ix_attendance_date_status"', $json);
        self::assertStringNotContainsString('COLLSCAN', $json);
    }

    #[Group('MD-11')]
    public function testArchivingAStudentRemovesHimFromQuery1ButKeepsHisAbsencesInTheDashboard(): void
    {
        $ids = $this->seedDashboardData();
        $before = $this->dashboard('2026-02-01', '2027-01-01');

        Mongo::collection(Collections::STUDENTS)->updateOne(['_id' => $ids['A1']], ['$set' => ['active' => false]]);

        self::assertNull($this->findStudentContext($ids['A1']), 'arquivado: não aparece mais na busca operacional');
        self::assertSame(
            Bson::toPlain($before),
            Bson::toPlain($this->dashboard('2026-02-01', '2027-01-01')),
            'a história de faltas continua nos indicadores'
        );
        self::assertSame(
            5,
            Mongo::collection(Collections::ATTENDANCE_RECORDS)->countDocuments(['student_id' => $ids['A1']]),
            'nenhuma frequência é apagada ao arquivar'
        );
    }

    // ================================================================================== índices das demais operações

    /** @return array<string, array{string, array<string, mixed>, array<string, int>, string}> */
    public static function indexedQueries(): array
    {
        $id = new ObjectId('507f1f77bcf86cd799439011');
        $day = Bson::day('2026-10-09');
        $range = ['$gte' => Bson::day('2026-02-01'), '$lt' => Bson::day('2027-01-01')];
        return [
            // consultas frequentes e operações 7 e 9 da atividade
            'faltas do aluno, da mais recente para a mais antiga' => [Collections::ATTENDANCE_RECORDS, ['student_id' => $id], ['date' => -1], 'uq_attendance_student_date'],
            'faltas por período, com filtro de status' => [Collections::ATTENDANCE_RECORDS, ['date' => $range, 'status' => ['$in' => ['ABSENT', 'EXCUSED']]], [], 'ix_attendance_date_status'],
            'faltas de uma turma em um período' => [Collections::ATTENDANCE_RECORDS, ['class_id' => $id, 'date' => $range], [], 'ix_attendance_class_date'],
            'justificativas pendentes, da mais recente' => [Collections::ATTENDANCE_RECORDS, ['justification.status' => 'PENDING'], ['date' => -1], 'ix_attendance_justification_status_date'],
            'frequência de uma matrícula' => [Collections::ATTENDANCE_RECORDS, ['enrollment_id' => $id], [], 'ix_attendance_enrollment'],
            'matrícula ativa do aluno' => [Collections::ENROLLMENTS, ['student_id' => $id, 'active' => true], [], 'uq_enrollments_student_active'],
            'histórico de matrículas do aluno' => [Collections::ENROLLMENTS, ['student_id' => $id], ['start_date' => -1], 'ix_enrollments_student_start'],
            'alunos ativos de uma turma' => [Collections::ENROLLMENTS, ['class_id' => $id, 'active' => true], [], 'ix_enrollments_class_active'],
            // notificações (operações 4, 5 e 6)
            'link de justificativa pelo hash do token' => [Collections::EMAIL_NOTIFICATIONS, ['justification_link.token_hash' => str_repeat('a', 64)], [], 'uq_notifications_token_hash'],
            'e-mails de uma falta' => [Collections::EMAIL_NOTIFICATIONS, ['attendance_id' => $id], [], 'ix_notifications_attendance'],
            'histórico de e-mails de um responsável' => [Collections::EMAIL_NOTIFICATIONS, ['guardian_id' => $id], ['created_at' => -1], 'ix_notifications_guardian_created'],
            'fila de envio (pendentes prontos para tentar)' => [Collections::EMAIL_NOTIFICATIONS, ['status' => 'PENDING', 'next_attempt_at' => ['$lte' => $day]], [], 'ix_notifications_status_next_attempt'],
            'evento do provedor pelo id da mensagem' => [Collections::EMAIL_NOTIFICATIONS, ['provider_message_id' => 'provider-123456'], [], 'ix_notifications_provider_message'],
            // cadastros
            'aluno pelo RA' => [Collections::STUDENTS, ['student_code' => 'RA-2026-0001'], [], 'uq_students_student_code'],
            'alunos ativos por nome' => [Collections::STUDENTS, ['active' => true], ['name' => 1], 'ix_students_active_name'],
            'alunos de um responsável' => [Collections::STUDENTS, ['guardians.guardian_id' => $id], [], 'ix_students_guardian'],
            'responsável pelo e-mail' => [Collections::GUARDIANS, ['email_normalized' => 'carlos@exemplo.com'], [], 'uq_guardians_email_normalized'],
            'usuário pelo e-mail (login)' => [Collections::USERS, ['email_normalized' => 'aoe@escola.com'], [], 'uq_users_email_normalized'],
            'turmas de um período e turno (filtro do painel)' => [Collections::CLASSES, ['academic_period_id' => $id, 'shift' => 'MORNING'], [], 'ix_classes_period_shift'],
            'turma pelo código no período' => [Collections::CLASSES, ['academic_period_id' => $id, 'code' => '6A'], [], 'uq_classes_period_code'],
            'período letivo ativo' => [Collections::ACADEMIC_PERIODS, ['active' => true], [], 'uq_academic_periods_single_active'],
            // apoio
            'auditoria mais recente' => [Collections::AUDIT_EVENTS, [], ['at' => -1], 'ix_audit_at'],
            'auditoria de um registro' => [Collections::AUDIT_EVENTS, ['entity_type' => 'students', 'entity_id' => $id], ['at' => -1], 'ix_audit_entity_at'],
            'auditoria de um usuário' => [Collections::AUDIT_EVENTS, ['actor_user_id' => $id], ['at' => -1], 'ix_audit_actor_at'],
            'tentativas de login na janela' => [Collections::LOGIN_ATTEMPTS, ['key' => 'hash', 'at' => ['$gte' => $day]], [], 'ix_login_attempts_key_at'],
            'redefinição pelo hash do token' => [Collections::PASSWORD_RESETS, ['token_hash' => str_repeat('b', 64)], [], 'uq_password_resets_token_hash'],
            'redefinições de um usuário' => [Collections::PASSWORD_RESETS, ['user_id' => $id], ['created_at' => -1], 'ix_password_resets_user_created'],
            'modelo de mensagem pelo código' => [Collections::MESSAGE_TEMPLATES, ['code' => 'ABSENCE_NOTICE'], [], 'uq_message_templates_code'],
        ];
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, int> $sort
     */
    #[DataProvider('indexedQueries')]
    #[Group('MD-10')]
    public function testDeclaredIndexesServeTheMainQueries(string $collection, array $filter, array $sort, string $expectedIndex): void
    {
        $plan = $this->queryPlan($collection, $filter, $sort);

        self::assertNotContains('COLLSCAN', $plan['stages'], 'o plano vencedor não pode varrer a coleção inteira');
        self::assertContains(
            $expectedIndex,
            [...$plan['indexes'], ...$plan['rejected']],
            "o índice {$expectedIndex} deveria servir esta consulta (usado: " . implode(', ', $plan['indexes']) . ')'
        );
        if ($sort !== []) {
            self::assertNotContains('SORT', $plan['stages'], 'o índice entrega a ordem pedida: sem ordenação em memória');
        }
    }

    // ================================================================================== dados de apoio

    /** @return ObjectId o aluno Ana, com 3 responsáveis e 37 registros de frequência (35 faltas + 2 presenças recentes) */
    private function seedStudentWithGuardiansAndAbsences(): ObjectId
    {
        $period = $this->period('2026', '2026-02-02', '2026-12-18', true);
        $class = $this->classroom($period, '6A', '6º Ano A');
        $carlos = $this->guardian('Carlos Souza', 'Carlos.Souza@exemplo.com');
        $marina = $this->guardian('Marina Souza', 'marina@exemplo.com');
        $pedro = $this->guardian('Pedro Souza', 'pedro@exemplo.com', 'BOUNCED');
        $ana = $this->student('Ana Beatriz Souza', 'RA-2026-0001', [
            [$carlos, 'PAI', true, true],
            [$marina, 'MAE', false, false],       // não recebe aviso de falta
            [$pedro, 'RESPONSAVEL', false, true], // e-mail devolvido (BOUNCED)
        ]);
        $enrollment = $this->enroll($ana, $class, $period, '2026-02-02');

        $responded = [
            'status' => 'RESPONDED', 'guardian_id' => $carlos, 'reason_code' => 'MEDICAL', 'explanation' => 'Consulta médica.',
            'submitted_at' => Bson::date('2026-03-08T18:30:00Z'), 'notification_id' => new ObjectId(),
            'status_history' => [
                ['status' => 'PENDING', 'at' => Bson::date('2026-03-08T12:00:00Z'), 'by_user_id' => $this->userId, 'by_guardian_id' => null],
                ['status' => 'RESPONDED', 'at' => Bson::date('2026-03-08T18:30:00Z'), 'by_user_id' => null, 'by_guardian_id' => $carlos],
            ],
        ];
        $pending = [
            'status' => 'PENDING', 'guardian_id' => null, 'reason_code' => null, 'explanation' => null,
            'submitted_at' => null, 'notification_id' => null,
            'status_history' => [['status' => 'PENDING', 'at' => Bson::now(), 'by_user_id' => $this->userId, 'by_guardian_id' => null]],
        ];
        $start = new DateTimeImmutable('2026-02-02', new DateTimeZone('UTC'));
        for ($i = 0; $i < 35; $i++) {
            $status = $i % 5 === 4 ? 'EXCUSED' : 'ABSENT';
            $this->attendance($enrollment, $ana, $class, '6º Ano A', $period, $start->modify("+{$i} days")->format('Y-m-d'), $status, $i === 34 ? $responded : $pending);
        }
        $this->attendance($enrollment, $ana, $class, '6º Ano A', $period, '2026-03-09', 'PRESENT', null);
        $this->attendance($enrollment, $ana, $class, '6º Ano A', $period, '2026-03-10', 'PRESENT', null);

        // as faltas de outro aluno nunca aparecem na consulta de Ana
        $bruno = $this->student('Bruno Souza', 'RA-2026-0002', []);
        $brunoEnrollment = $this->enroll($bruno, $class, $period, '2026-02-02');
        $this->attendance($brunoEnrollment, $bruno, $class, '6º Ano A', $period, '2026-03-02', 'ABSENT', $pending);
        $this->attendance($brunoEnrollment, $bruno, $class, '6º Ano A', $period, '2026-03-03', 'ABSENT', $pending);

        return $ana;
    }

    /** @return array{ObjectId, ObjectId, ObjectId, ObjectId} aluno, matrícula ativa, turma e período */
    private function seedEnrolledStudent(): array
    {
        $period = $this->period('2026', '2026-02-02', '2026-12-18', true);
        $class = $this->classroom($period, '6A', '6º Ano A');
        $ana = $this->student('Ana Beatriz Souza', 'RA-2026-0001', []);
        return [$ana, $this->enroll($ana, $class, $period, '2026-02-02'), $class, $period];
    }

    /**
     * Conjunto com respostas conhecidas. Intervalo de 2026: 24 registros em 3 turmas.
     *   6º Ano A (A1..A4):  02-02 A1 falta | 02-03 A1 justificada, A2 falta | 03-02 A1 falta   -> 4 faltas, 2 alunos, 12 registros
     *   6º Ano B (B1..B3):  02-02 B2 falta | 03-02 B1 justificada                              -> 2 faltas, 2 alunos,  9 registros
     *   6º Ano C (C1..C3):  02-02 todos presentes                                              -> 0 faltas, 0 alunos,  3 registros
     * Fora do intervalo: A1 faltou em 2025-11-10 (5º Ano A, período 2025) e em 2027-02-08 (7º Ano A, período 2027).
     *
     * @return array<string, ObjectId> aluno => id (A1, A2, ...)
     */
    private function seedDashboardData(): array
    {
        $p2025 = $this->period('2025', '2025-02-03', '2025-12-19', false);
        $p2026 = $this->period('2026', '2026-02-02', '2026-12-18', true);
        $p2027 = $this->period('2027', '2027-02-01', '2027-12-17', false);
        $classes = [
            '6A' => $this->classroom($p2026, '6A', '6º Ano A'),
            '6B' => $this->classroom($p2026, '6B', '6º Ano B', 'AFTERNOON'),
            '6C' => $this->classroom($p2026, '6C', '6º Ano C', 'NIGHT'),
            '5A' => $this->classroom($p2025, '5A', '5º Ano A'),
            '7A' => $this->classroom($p2027, '7A', '7º Ano A'),
        ];
        $ids = [];
        $enrollments = [];
        $classOf = ['A' => '6A', 'B' => '6B', 'C' => '6C'];
        $sizes = ['A' => 4, 'B' => 3, 'C' => 3];
        foreach ($sizes as $letter => $count) {
            for ($n = 1; $n <= $count; $n++) {
                $key = "{$letter}{$n}";
                $ids[$key] = $this->student("Aluno {$key}", "RA-2026-{$key}", []);
                $enrollments[$key] = $this->enroll($ids[$key], $classes[$classOf[$letter]], $p2026, '2026-02-02');
            }
        }
        $label = ['A' => '6º Ano A', 'B' => '6º Ano B', 'C' => '6º Ano C'];
        $record = function (string $key, string $ymd, string $status) use ($ids, $enrollments, $classes, $classOf, $label, $p2026): void {
            $letter = $key[0];
            $this->attendance($enrollments[$key], $ids[$key], $classes[$classOf[$letter]], $label[$letter], $p2026, $ymd, $status, null);
        };

        $day = [
            '2026-02-02' => ['A1' => 'ABSENT', 'A2' => 'PRESENT', 'A3' => 'PRESENT', 'A4' => 'PRESENT', 'B1' => 'PRESENT', 'B2' => 'ABSENT', 'B3' => 'PRESENT', 'C1' => 'PRESENT', 'C2' => 'PRESENT', 'C3' => 'PRESENT'],
            '2026-02-03' => ['A1' => 'EXCUSED', 'A2' => 'ABSENT', 'A3' => 'PRESENT', 'A4' => 'PRESENT', 'B1' => 'PRESENT', 'B2' => 'PRESENT', 'B3' => 'PRESENT'],
            '2026-03-02' => ['A1' => 'ABSENT', 'A2' => 'PRESENT', 'A3' => 'PRESENT', 'A4' => 'PRESENT', 'B1' => 'EXCUSED', 'B2' => 'PRESENT', 'B3' => 'PRESENT'],
        ];
        foreach ($day as $ymd => $statuses) {
            foreach ($statuses as $key => $status) {
                $record($key, $ymd, $status);
            }
        }
        // 12 + 9 + 3 = 24 registros em 2026 (A: 3 dias x 4, B: 3 dias x 3, C: 1 dia x 3)
        self::assertSame(24, Mongo::collection(Collections::ATTENDANCE_RECORDS)->countDocuments([]));

        // fora do intervalo de 2026: A1 em outra turma/período (o histórico do aluno atravessa anos)
        $this->attendance($this->enroll($ids['A1'], $classes['5A'], $p2025, '2025-02-03', '2025-12-19', 'PERIOD_END'), $ids['A1'], $classes['5A'], '5º Ano A', $p2025, '2025-11-10', 'ABSENT', null);
        $this->attendance($this->enroll($ids['A1'], $classes['7A'], $p2027, '2027-02-01', '2027-12-17', 'PERIOD_END'), $ids['A1'], $classes['7A'], '7º Ano A', $p2027, '2027-02-08', 'ABSENT', null);

        return $ids;
    }

    // ================================================================================== construtores de documentos

    /** @param array<string, mixed> $document */
    private function insert(string $collection, array $document): ObjectId
    {
        $id = $document['_id'] ?? new ObjectId();
        $document['_id'] = $id;
        Mongo::collection($collection)->insertOne($document);
        return $id;
    }

    private function period(string $name, string $start, string $end, bool $active): ObjectId
    {
        $now = Bson::now();
        return $this->insert(Collections::ACADEMIC_PERIODS, [
            'name' => $name, 'start_date' => Bson::day($start), 'end_date' => Bson::day($end),
            'attendance_policy' => ['min_attendance_percent' => 75, 'alert_below_percent' => 80],
            'active' => $active, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function classroom(ObjectId $period, string $code, string $name, string $shift = 'MORNING'): ObjectId
    {
        $now = Bson::now();
        return $this->insert(Collections::CLASSES, [
            'academic_period_id' => $period, 'code' => $code, 'name' => $name, 'grade' => mb_substr($name, 0, 6),
            'shift' => $shift, 'active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function guardian(string $name, string $email, string $status = 'VALID', bool $active = true): ObjectId
    {
        $now = Bson::now();
        return $this->insert(Collections::GUARDIANS, [
            'name' => $name, 'email' => $email, 'email_normalized' => strtolower($email), 'email_status' => $status,
            'active' => $active, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** @param list<array{ObjectId, string, bool, bool}> $links [responsável, parentesco, principal, recebe aviso] */
    private function student(string $name, string $code, array $links, bool $active = true): ObjectId
    {
        $now = Bson::now();
        return $this->insert(Collections::STUDENTS, [
            'name' => $name, 'student_code' => $code, 'birth_date' => Bson::day('2015-03-14'),
            'guardians' => array_map(static fn (array $link): array => [
                'guardian_id' => $link[0], 'relationship' => $link[1], 'is_primary' => $link[2], 'receives_absence_notifications' => $link[3],
            ], $links),
            'active' => $active, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function enroll(ObjectId $student, ObjectId $class, ObjectId $period, string $start, ?string $end = null, ?string $reason = null): ObjectId
    {
        return $this->insert(Collections::ENROLLMENTS, [
            'student_id' => $student, 'class_id' => $class, 'academic_period_id' => $period,
            'start_date' => Bson::day($start), 'end_date' => $end === null ? null : Bson::day($end),
            'end_reason' => $reason, 'active' => $end === null,
        ]);
    }

    /** @param array<string, mixed>|null $justification */
    private function attendance(ObjectId $enrollment, ObjectId $student, ObjectId $class, string $className, ObjectId $period, string $ymd, string $status, ?array $justification): ObjectId
    {
        $document = [
            'enrollment_id' => $enrollment, 'student_id' => $student, 'class_id' => $class, 'class_name' => $className,
            'academic_period_id' => $period, 'date' => Bson::day($ymd), 'status' => $status,
            'registered_by' => $this->userId, 'created_at' => Bson::now(),
        ];
        if ($justification !== null) {
            $document['justification'] = $justification;
        }
        return $this->insert(Collections::ATTENDANCE_RECORDS, $document);
    }

    // ================================================================================== utilitários

    /**
     * Executa a operação e devolve [resultado, comandos de dados enviados ao MongoDB, exceção ou null].
     * Cada comando é [nome, coleção, JSON do comando]. Exceções da operação são capturadas para o teste inspecioná-las.
     *
     * @return array{0: mixed, 1: list<array{string, string, string}>, 2: Throwable|null}
     */
    private function recorded(callable $operation): array
    {
        $recorder = new class (self::databaseName()) implements CommandSubscriber {
            /** @var list<array{string, string, string}> */
            public array $commands = [];

            public function __construct(private readonly string $database)
            {
            }

            public function commandStarted(CommandStartedEvent $event): void
            {
                $name = $event->getCommandName();
                if ($event->getDatabaseName() !== $this->database || !in_array($name, ['find', 'aggregate', 'insert', 'update', 'delete', 'findAndModify', 'getMore', 'count', 'distinct'], true)) {
                    return;
                }
                $command = $event->getCommand();
                $this->commands[] = [$name, (string)($command->{$name} ?? ''), (string)json_encode($command)];
            }

            public function commandSucceeded(CommandSucceededEvent $event): void
            {
            }

            public function commandFailed(CommandFailedEvent $event): void
            {
            }
        };

        $client = Mongo::client();
        $client->addSubscriber($recorder);
        $result = null;
        $error = null;
        try {
            $result = $operation();
        } catch (Throwable $e) {
            $error = $e;
        } finally {
            $client->removeSubscriber($recorder);
        }
        return [$result, $recorder->commands, $error];
    }

    /**
     * Plano vencedor (e rejeitados) de um find, sem executá-lo.
     *
     * @param array<string, mixed> $filter
     * @param array<string, int> $sort
     * @return array{indexes: list<string>, stages: list<string>, rejected: list<string>}
     */
    private function queryPlan(string $collection, array $filter, array $sort = []): array
    {
        $find = ['find' => $collection, 'filter' => $filter === [] ? new stdClass() : $filter];
        if ($sort !== []) {
            $find['sort'] = $sort;
        }
        $reply = $this->db()->command(['explain' => $find, 'verbosity' => 'queryPlanner'])->toArray()[0];
        $planner = $reply['queryPlanner'];

        $collect = static function (mixed $node, string $key) use (&$collect): array {
            $found = [];
            if (is_array($node)) {
                foreach ($node as $name => $child) {
                    if ($name === $key && is_string($child)) {
                        $found[] = $child;
                    }
                    array_push($found, ...$collect($child, $key));
                }
            }
            return $found;
        };
        return [
            'indexes' => array_values(array_unique($collect($planner['winningPlan'], 'indexName'))),
            'stages' => array_values(array_unique($collect($planner['winningPlan'], 'stage'))),
            'rejected' => array_values(array_unique($collect($planner['rejectedPlans'] ?? [], 'indexName'))),
        ];
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);
        return $values;
    }
}
