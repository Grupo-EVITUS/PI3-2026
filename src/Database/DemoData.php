<?php
declare(strict_types=1);

namespace Saa\Database;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;
use Saa\Core\Clock;
use Saa\Core\Exceptions\ConflictException;
use Saa\Domain\AttendanceStatus;
use Saa\Domain\EmailStatus;
use Saa\Domain\JustificationReason;
use Saa\Domain\JustificationStatus;
use Saa\Domain\NotificationEventType;
use Saa\Domain\NotificationStatus;
use Saa\Domain\Relationship;
use Saa\Domain\Shift;
use Saa\Models\AcademicPeriodModel;
use Saa\Models\AttendanceModel;
use Saa\Models\AuditEventModel;
use Saa\Models\EmailNotificationModel;
use Saa\Models\EnrollmentModel;
use Saa\Models\GuardianModel;
use Saa\Models\MessageTemplateModel;
use Saa\Models\SchoolClassModel;
use Saa\Models\StudentModel;
use Saa\Models\UserModel;
use Saa\Services\AttendanceService;
use Saa\Services\AuditService;
use Saa\Services\MessageTemplateService;
use Saa\Support\Dates;
use Saa\Support\Email;
use Saa\Support\PasswordHasher;
use SensitiveParameter;

/**
 * Dados FICTÍCIOS de demonstração (issue #25), usados por bin/seed.php. Nunca rodar em produção.
 *
 * Tudo é gravado pelos Models existentes, respeitando os validators do Schema: usuários de cada perfil
 * (@escola.test), período letivo do ano corrente (somente se não houver um ativo), 3 turmas, 18 alunos (matrícula
 * escolar DEMO-*), 19 responsáveis (@exemplo.test, todos com telefone), vínculos (1 ou 2 por aluno, um principal),
 * frequência dos dias úteis dos últimos ~2 meses (o 7º Ano C falta bem mais), justificativas respondidas e pendentes
 * e e-mails de ausência (SENT/DELIVERED/OPENED e um FAILED).
 *
 * Idempotente: cada item é procurado pela sua chave única (e-mail, matrícula escolar, código da turma no período,
 * aluno + dia) e, se já existir, é reaproveitado e contado como "já existia" — rodar duas vezes não duplica nada.
 * Senhas de usuários que já existiam nunca são alteradas.
 *
 * Marcação dos dados de demonstração (usada por reset()): domínios reservados .test nos e-mails, prefixo DEMO- na
 * matrícula escolar, códigos 5A/6B/7C e o nome "Ano letivo AAAA". reset() é manutenção de banco (como o --drop do
 * bin/init_db.php) e por isso remove os documentos diretamente aqui, sem passar pelos Models (que nunca apagam).
 */
final class DemoData
{
    public const USER_DOMAIN = 'escola.test';
    public const GUARDIAN_DOMAIN = 'exemplo.test';
    public const STUDENT_PREFIX = 'DEMO-';
    public const PERIOD_PREFIX = 'Ano letivo ';
    /** Janela do histórico de frequência (dias corridos até hoje). */
    public const HISTORY_DAYS = 60;
    /** Faltas com pelo menos esta idade (dias) podem já ter sido justificadas. */
    public const RESPONSE_MIN_AGE_DAYS = 3;
    /** Percentual das faltas antigas (com responsável válido) que foram justificadas. */
    public const RESPONDED_PERCENT = 60;
    public const AUDIT_ACTION = 'seed.demo_data';
    /** Ordem das linhas do relatório. */
    public const CATEGORIES = [
        'usuários', 'período letivo', 'turmas', 'responsáveis', 'alunos', 'vínculos aluno-responsável', 'matrículas',
        'presenças', 'faltas', 'faltas justificadas', 'faltas pendentes', 'e-mails de ausência', 'e-mails com falha',
    ];
    public const FAILED_DETAIL = '550 5.1.1 Caixa postal inexistente no servidor do destinatário (simulação).';

    /** @var list<array{email: string, name: string, profile: string}> */
    public const USERS = [
        ['email' => 'admin@escola.test', 'name' => 'Carolina Prado Antunes', 'profile' => 'ADMIN'],
        ['email' => 'coordenacao@escola.test', 'name' => 'Ricardo Tavares Moura', 'profile' => 'COORDINATION'],
        ['email' => 'aoe@escola.test', 'name' => 'Juliana Campos Ferraz', 'profile' => 'AOE'],
        ['email' => 'secretaria@escola.test', 'name' => 'Patrícia Lemos Andrade', 'profile' => 'SECRETARY'],
    ];

    /** Turmas: absence_rate = chance (%) de falta por dia letivo; age = idade típica dos alunos. */
    public const CLASSES = [
        '5A' => ['name' => '5º Ano A', 'grade' => '5º ano', 'shift' => 'MORNING', 'absence_rate' => 5, 'age' => 10],
        '6B' => ['name' => '6º Ano B', 'grade' => '6º ano', 'shift' => 'AFTERNOON', 'absence_rate' => 8, 'age' => 11],
        '7C' => ['name' => '7º Ano C', 'grade' => '7º ano', 'shift' => 'MORNING', 'absence_rate' => 22, 'age' => 12],
    ];

    /** Responsáveis: chave => nome, parte local do e-mail, telefone e situação do e-mail. */
    public const GUARDIANS = [
        'g01' => ['name' => 'Márcia Santos Oliveira', 'local' => 'marcia.oliveira', 'phone' => '(11) 98765-1001', 'status' => 'VALID'],
        'g02' => ['name' => 'Roberto Oliveira', 'local' => 'roberto.oliveira', 'phone' => '(11) 98765-1002', 'status' => 'VALID'],
        'g03' => ['name' => 'Fernanda Costa Lima', 'local' => 'fernanda.lima', 'phone' => '(11) 98765-1003', 'status' => 'VALID'],
        'g04' => ['name' => 'Anderson Ribeiro Gomes', 'local' => 'anderson.gomes', 'phone' => '(11) 3456-1004', 'status' => 'VALID'],
        'g05' => ['name' => 'Luciana Martins Rocha', 'local' => 'luciana.rocha', 'phone' => '(11) 98765-1005', 'status' => 'VALID'],
        'g06' => ['name' => 'Paulo Henrique Martins', 'local' => 'paulo.martins', 'phone' => '(11) 98765-1006', 'status' => 'VALID'],
        'g07' => ['name' => 'Simone Carvalho Dias', 'local' => 'simone.dias', 'phone' => '(11) 98765-1007', 'status' => 'VALID'],
        'g08' => ['name' => 'Marcos Vinícius Teixeira', 'local' => 'marcos.teixeira', 'phone' => '(11) 98765-1008', 'status' => 'VALID'],
        'g09' => ['name' => 'Cláudia Alves Teixeira', 'local' => 'claudia.teixeira', 'phone' => '(11) 98765-1009', 'status' => 'VALID'],
        'g10' => ['name' => 'José Ricardo Nunes', 'local' => 'jose.nunes', 'phone' => '(11) 98765-1010', 'status' => 'UNVERIFIED'],
        'g11' => ['name' => 'Adriana Barros Nunes', 'local' => 'adriana.nunes', 'phone' => '(11) 98765-1011', 'status' => 'VALID'],
        'g12' => ['name' => 'Eduardo Moreira Pinto', 'local' => 'eduardo.pinto', 'phone' => '(11) 98765-1012', 'status' => 'VALID'],
        'g13' => ['name' => 'Vanessa Araújo Correia', 'local' => 'vanessa.correia', 'phone' => '(11) 98765-1013', 'status' => 'VALID'],
        'g14' => ['name' => 'Gilberto Farias Monteiro', 'local' => 'gilberto.monteiro', 'phone' => '(11) 98765-1014', 'status' => 'BOUNCED'],
        'g15' => ['name' => 'Renata Cunha Ramos', 'local' => 'renata.ramos', 'phone' => '(11) 98765-1015', 'status' => 'VALID'],
        'g16' => ['name' => 'Sérgio Lopes Vieira', 'local' => 'sergio.vieira', 'phone' => '(11) 98765-1016', 'status' => 'VALID'],
        'g17' => ['name' => 'Tatiane Duarte Campos', 'local' => 'tatiane.campos', 'phone' => '(11) 98765-1017', 'status' => 'VALID'],
        'g18' => ['name' => 'Wagner Nogueira Reis', 'local' => 'wagner.reis', 'phone' => '(11) 98765-1018', 'status' => 'VALID'],
        'g19' => ['name' => 'Rosana Ferreira Batista', 'local' => 'rosana.batista', 'phone' => '(11) 98765-1019', 'status' => 'UNVERIFIED'],
    ];

    /**
     * Alunos: matrícula escolar => nome, turma, dia/mês de nascimento e vínculos [responsável, parentesco, principal].
     * DEMO-0006 (só responsável com e-mail não verificado) e DEMO-0017 (só responsável com e-mail que retornou)
     * são os alunos SEM responsável válido para receber avisos.
     */
    public const STUDENTS = [
        'DEMO-0001' => ['name' => 'Lucas Santos Oliveira', 'class' => '5A', 'birth' => '03-14', 'links' => [['g01', 'MAE', true], ['g02', 'PAI', false]]],
        'DEMO-0002' => ['name' => 'Gabriel Costa Lima', 'class' => '5A', 'birth' => '07-02', 'links' => [['g03', 'MAE', true]]],
        'DEMO-0003' => ['name' => 'Sofia Ribeiro Gomes', 'class' => '5A', 'birth' => '01-25', 'links' => [['g04', 'PAI', true]]],
        'DEMO-0004' => ['name' => 'Mariana Carvalho Dias', 'class' => '5A', 'birth' => '09-30', 'links' => [['g07', 'MAE', true]]],
        'DEMO-0005' => ['name' => 'Helena Araújo Correia', 'class' => '5A', 'birth' => '11-11', 'links' => [['g13', 'MAE', true]]],
        'DEMO-0006' => ['name' => 'Miguel Ferreira Batista', 'class' => '5A', 'birth' => '05-19', 'links' => [['g19', 'RESPONSAVEL', true]]],
        'DEMO-0007' => ['name' => 'Pedro Martins Rocha', 'class' => '6B', 'birth' => '02-08', 'links' => [['g05', 'MAE', true], ['g06', 'RESPONSAVEL', false]]],
        'DEMO-0008' => ['name' => 'Rafael Carvalho Dias', 'class' => '6B', 'birth' => '06-21', 'links' => [['g07', 'MAE', true]]],
        'DEMO-0009' => ['name' => 'Laura Teixeira Alves', 'class' => '6B', 'birth' => '10-04', 'links' => [['g08', 'PAI', true], ['g09', 'MAE', false]]],
        'DEMO-0010' => ['name' => 'Valentina Moreira Pinto', 'class' => '6B', 'birth' => '12-17', 'links' => [['g12', 'PAI', true]]],
        'DEMO-0011' => ['name' => 'Isabela Cunha Ramos', 'class' => '6B', 'birth' => '04-09', 'links' => [['g15', 'MAE', true]]],
        'DEMO-0012' => ['name' => 'Heitor Lopes Vieira', 'class' => '6B', 'birth' => '08-27', 'links' => [['g16', 'PAI', true]]],
        'DEMO-0013' => ['name' => 'Beatriz Santos Oliveira', 'class' => '7C', 'birth' => '03-03', 'links' => [['g01', 'MAE', true], ['g02', 'PAI', false]]],
        'DEMO-0014' => ['name' => 'Enzo Nunes Barros', 'class' => '7C', 'birth' => '07-15', 'links' => [['g11', 'MAE', true], ['g10', 'PAI', false]]],
        'DEMO-0015' => ['name' => 'Davi Araújo Correia', 'class' => '7C', 'birth' => '01-06', 'links' => [['g13', 'MAE', true]]],
        'DEMO-0016' => ['name' => 'Alice Duarte Campos', 'class' => '7C', 'birth' => '09-12', 'links' => [['g17', 'MAE', true]]],
        'DEMO-0017' => ['name' => 'Arthur Farias Monteiro', 'class' => '7C', 'birth' => '05-28', 'links' => [['g14', 'PAI', true]]],
        'DEMO-0018' => ['name' => 'Bernardo Nogueira Reis', 'class' => '7C', 'birth' => '11-23', 'links' => [['g18', 'PAI', true]]],
    ];

    /** Alunos que faltam HOJE (quando hoje é dia útil), para a tela inicial ter o que mostrar. */
    private const ABSENT_TODAY = ['DEMO-0003', 'DEMO-0014', 'DEMO-0016'];
    /** Aluno cujo único responsável tem e-mail que retornou: a falta mais recente da janela gera o e-mail FAILED. */
    private const BOUNCED_STUDENT = 'DEMO-0017';

    /** @var list<array{0: string, 1: string|null}> motivo e explicação das justificativas (em rodízio) */
    private const REASONS = [
        ['ILLNESS', 'Febre e dor de garganta; ficou em repouso.'],
        ['MEDICAL', 'Consulta com o pediatra marcada para a manhã.'],
        ['FAMILY', null],
        ['TRANSPORT', 'O ônibus escolar não passou por causa da chuva.'],
        ['ILLNESS', null],
        ['OTHER', 'Viagem para o casamento de um familiar em outra cidade.'],
        ['MEDICAL', 'Exame de sangue em jejum no posto de saúde.'],
    ];

    private readonly UserModel $users;
    private readonly AcademicPeriodModel $periods;
    private readonly SchoolClassModel $classes;
    private readonly GuardianModel $guardians;
    private readonly StudentModel $students;
    private readonly EnrollmentModel $enrollments;
    private readonly AttendanceModel $attendance;
    private readonly EmailNotificationModel $notifications;
    private readonly MessageTemplateService $templates;
    private readonly AuditService $audit;

    /** @var array<string, array{created: int, existing: int}> */
    private array $counts = [];
    /** @var list<string> */
    private array $notes = [];

    public function __construct(private readonly Database $database)
    {
        $this->users = new UserModel($database);
        $this->periods = new AcademicPeriodModel($database);
        $this->classes = new SchoolClassModel($database);
        $this->guardians = new GuardianModel($database);
        $this->students = new StudentModel($database);
        $this->enrollments = new EnrollmentModel($database);
        $this->attendance = new AttendanceModel($database);
        $this->notifications = new EmailNotificationModel($database);
        $this->templates = new MessageTemplateService(new MessageTemplateModel($database));
        $this->audit = new AuditService(new AuditEventModel($database));
    }

    /**
     * Cria (ou reaproveita) todos os dados de demonstração.
     *
     * @param string|null $password senha comum dos usuários (mínimo de 10 caracteres); null = senha aleatória forte por usuário
     * @return array{
     *     counts: array<string, array{created: int, existing: int}>,
     *     credentials: list<array{name: string, email: string, profile: string, password: string|null}>,
     *     period: array{name: string, start: string, end: string, status: string},
     *     history: array{from: string|null, to: string|null},
     *     notes: list<string>
     * }
     * @throws InvalidArgumentException senha com menos de 10 caracteres
     */
    public function seed(#[SensitiveParameter] ?string $password = null): array
    {
        if ($password !== null && mb_strlen($password, 'UTF-8') < 10) {
            throw new InvalidArgumentException('SEED_PASSWORD precisa ter pelo menos 10 caracteres.');
        }
        $this->counts = array_fill_keys(self::CATEGORIES, ['created' => 0, 'existing' => 0]);
        $this->notes = [];

        $credentials = $this->seedUsers($password);
        $registrar = $this->registrarId();
        [$period, $periodStatus] = $this->seedPeriod();
        $classes = $this->seedClasses($period['_id']);
        $guardians = $this->seedGuardians();
        $students = $this->seedStudents($guardians);
        $enrollments = $this->seedEnrollments($students, $classes, $period);
        $history = $this->seedAttendance($students, $enrollments, $guardians, $period, $registrar);

        $this->audit->record(self::AUDIT_ACTION, 'database', null, [
            'created' => array_map(static fn (array $c): int => $c['created'], $this->counts),
        ]);

        return [
            'counts' => array_filter($this->counts, static fn (array $c): bool => $c['created'] + $c['existing'] > 0),
            'credentials' => $credentials,
            'period' => [
                'name' => (string)$period['name'],
                'start' => (string)Bson::ymd($period['start_date']),
                'end' => (string)Bson::ymd($period['end_date']),
                'status' => $periodStatus,
            ],
            'history' => $history,
            'notes' => $this->notes,
        ];
    }

    /**
     * Remove SOMENTE os dados de demonstração: alunos DEMO-* (com matrículas, frequências e e-mails deles),
     * responsáveis @exemplo.test (os vínculos de outros alunos com eles são desfeitos), os 4 usuários @escola.test
     * do seed e, se ficarem sem nenhum uso, as turmas 5A/6B/7C e o período "Ano letivo AAAA".
     * A trilha de auditoria (audit_events) e os demais cadastros não são tocados.
     *
     * @return array<string, int> coleção => documentos removidos
     */
    public function reset(): array
    {
        $db = $this->database;
        $removed = [];
        $studentIds = array_map(
            static fn (array $doc): ObjectId => $doc['_id'],
            $db->selectCollection(Collections::STUDENTS)->find(
                ['student_code' => new Regex('^' . preg_quote(self::STUDENT_PREFIX, '/'))],
                ['projection' => ['_id' => 1]]
            )->toArray()
        );
        $byStudent = ['student_id' => ['$in' => $studentIds]];
        $removed[Collections::EMAIL_NOTIFICATIONS] = $db->selectCollection(Collections::EMAIL_NOTIFICATIONS)->deleteMany($byStudent)->getDeletedCount() ?? 0;
        $removed[Collections::ATTENDANCE_RECORDS] = $db->selectCollection(Collections::ATTENDANCE_RECORDS)->deleteMany($byStudent)->getDeletedCount() ?? 0;
        $removed[Collections::ENROLLMENTS] = $db->selectCollection(Collections::ENROLLMENTS)->deleteMany($byStudent)->getDeletedCount() ?? 0;
        $removed[Collections::STUDENTS] = $db->selectCollection(Collections::STUDENTS)->deleteMany(['_id' => ['$in' => $studentIds]])->getDeletedCount() ?? 0;

        $guardianFilter = ['email_normalized' => new Regex('@' . preg_quote(self::GUARDIAN_DOMAIN, '/') . '$')];
        $guardianIds = array_map(
            static fn (array $doc): ObjectId => $doc['_id'],
            $db->selectCollection(Collections::GUARDIANS)->find($guardianFilter, ['projection' => ['_id' => 1]])->toArray()
        );
        if ($guardianIds !== []) {
            // alunos que não são de demonstração, mas foram ligados a um responsável de demonstração
            $db->selectCollection(Collections::STUDENTS)->updateMany(
                ['guardians.guardian_id' => ['$in' => $guardianIds]],
                ['$pull' => ['guardians' => ['guardian_id' => ['$in' => $guardianIds]]], '$set' => ['updated_at' => Bson::now()]]
            );
        }
        $removed[Collections::GUARDIANS] = $db->selectCollection(Collections::GUARDIANS)->deleteMany(['_id' => ['$in' => $guardianIds]])->getDeletedCount() ?? 0;

        $emails = array_map(static fn (array $user): string => Email::normalize($user['email']), self::USERS);
        $removed[Collections::USERS] = $db->selectCollection(Collections::USERS)->deleteMany(['email_normalized' => ['$in' => $emails]])->getDeletedCount() ?? 0;

        $removed[Collections::CLASSES] = 0;
        foreach ($db->selectCollection(Collections::CLASSES)->find(['code' => ['$in' => array_keys(self::CLASSES)]], ['projection' => ['_id' => 1]]) as $class) {
            $inUse = $db->selectCollection(Collections::ENROLLMENTS)->countDocuments(['class_id' => $class['_id']], ['limit' => 1])
                + $db->selectCollection(Collections::ATTENDANCE_RECORDS)->countDocuments(['class_id' => $class['_id']], ['limit' => 1]);
            if ($inUse === 0) {
                $removed[Collections::CLASSES] += $db->selectCollection(Collections::CLASSES)->deleteOne(['_id' => $class['_id']])->getDeletedCount() ?? 0;
            }
        }

        $removed[Collections::ACADEMIC_PERIODS] = 0;
        $periodFilter = ['name' => new Regex('^' . preg_quote(self::PERIOD_PREFIX, '/') . '\d{4}$')];
        foreach ($db->selectCollection(Collections::ACADEMIC_PERIODS)->find($periodFilter, ['projection' => ['_id' => 1]]) as $period) {
            $inUse = $db->selectCollection(Collections::CLASSES)->countDocuments(['academic_period_id' => $period['_id']], ['limit' => 1])
                + $db->selectCollection(Collections::ENROLLMENTS)->countDocuments(['academic_period_id' => $period['_id']], ['limit' => 1])
                + $db->selectCollection(Collections::ATTENDANCE_RECORDS)->countDocuments(['academic_period_id' => $period['_id']], ['limit' => 1]);
            if ($inUse === 0) {
                $removed[Collections::ACADEMIC_PERIODS] += $db->selectCollection(Collections::ACADEMIC_PERIODS)->deleteOne(['_id' => $period['_id']])->getDeletedCount() ?? 0;
            }
        }
        return $removed;
    }

    /** Senha aleatória forte (20 caracteres, sem caracteres ambíguos). */
    public static function generatePassword(int $length = 20): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789-_@#%';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $password;
    }

    // ------------------------------------------------------------------ etapas

    /** @return list<array{name: string, email: string, profile: string, password: string|null}> */
    private function seedUsers(#[SensitiveParameter] ?string $password): array
    {
        $credentials = [];
        foreach (self::USERS as $user) {
            if ($this->users->findByEmail($user['email']) !== null) {
                $this->count('usuários', false);
                $credentials[] = $user + ['password' => null];
                continue;
            }
            $plain = $password ?? self::generatePassword();
            try {
                $this->users->create($user['name'], $user['email'], PasswordHasher::hash($plain), $user['profile']);
            } catch (ConflictException) { // criado por outro processo no mesmo instante
                $this->count('usuários', false);
                $credentials[] = $user + ['password' => null];
                continue;
            }
            $this->count('usuários', true);
            $credentials[] = $user + ['password' => $plain];
        }
        return $credentials;
    }

    /** Quem "registrou" as frequências: a AOE de demonstração (ou o administrador). */
    private function registrarId(): ObjectId
    {
        foreach (['aoe@escola.test', 'admin@escola.test'] as $email) {
            $user = $this->users->findByEmail($email);
            if ($user !== null) {
                return $user['_id'];
            }
        }
        throw new InvalidArgumentException('Nenhum usuário de demonstração disponível para registrar a frequência.');
    }

    /** @return array{0: array<string, mixed>, 1: string} período e situação (já existia | ativado | criado) */
    private function seedPeriod(): array
    {
        $active = $this->periods->findActive();
        if ($active !== null) {
            $this->count('período letivo', false);
            return [$active, 'já existia (período ativo reaproveitado)'];
        }
        $today = Clock::today();
        $year = substr($today, 0, 4);
        $name = self::PERIOD_PREFIX . $year;
        foreach ($this->periods->all() as $period) {
            if (($period['name'] ?? null) === $name) {
                $this->periods->activate($period['_id']);
                $this->count('período letivo', false);
                return [$this->periods->findById($period['_id']) ?? $period, 'já existia (reativado)'];
            }
        }
        // ano corrente; se hoje estiver perto da virada do ano, o período se estende para cobrir o histórico
        $start = min($year . '-02-02', self::shift($today, -(self::HISTORY_DAYS + 30)));
        $end = max($year . '-12-18', self::shift($today, 30));
        $now = Bson::now();
        $period = $this->periods->insert([
            'name' => $name,
            'start_date' => Bson::day($start),
            'end_date' => Bson::day($end),
            'attendance_policy' => ['min_attendance_percent' => 75, 'alert_below_percent' => 80],
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->count('período letivo', true);
        return [$period, 'criado'];
    }

    /** @return array<string, array<string, mixed>> código => turma do período */
    private function seedClasses(ObjectId $periodId): array
    {
        $existing = [];
        foreach ($this->classes->listWithCounts($periodId, null, null) as $class) {
            $existing[(string)$class['code']] = $class;
        }
        $result = [];
        foreach (self::CLASSES as $code => $spec) {
            if (isset($existing[$code])) {
                $this->count('turmas', false);
                $result[$code] = $existing[$code];
                continue;
            }
            $now = Bson::now();
            $result[$code] = $this->classes->insert([
                'academic_period_id' => $periodId,
                'code' => $code,
                'name' => $spec['name'],
                'grade' => $spec['grade'],
                'shift' => Shift::from($spec['shift'])->value,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->count('turmas', true);
        }
        return $result;
    }

    /** @return array<string, array<string, mixed>> chave (g01...) => responsável */
    private function seedGuardians(): array
    {
        $existing = [];
        $domain = Bson::contains('@' . self::GUARDIAN_DOMAIN);
        foreach ([true, false] as $active) {
            foreach ($this->guardians->search($domain, $active, 0, 500)['items'] as $guardian) {
                $existing[(string)$guardian['email_normalized']] = $guardian;
            }
        }
        $result = [];
        foreach (self::GUARDIANS as $key => $spec) {
            $email = $spec['local'] . '@' . self::GUARDIAN_DOMAIN;
            if (isset($existing[$email])) {
                $this->count('responsáveis', false);
                $result[$key] = $existing[$email];
                continue;
            }
            $now = Bson::now();
            $result[$key] = $this->guardians->insert([
                'name' => $spec['name'],
                'email' => $email,
                'email_normalized' => Email::normalize($email),
                'email_status' => EmailStatus::from($spec['status'])->value,
                'phone' => $spec['phone'],
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->count('responsáveis', true);
        }
        return $result;
    }

    /**
     * @param array<string, array<string, mixed>> $guardians
     * @return array<string, array<string, mixed>> matrícula escolar => aluno
     */
    private function seedStudents(array $guardians): array
    {
        $year = (int)substr(Clock::today(), 0, 4);
        $result = [];
        foreach (self::STUDENTS as $code => $spec) {
            $student = $this->students->findByCode($code);
            if ($student !== null) {
                $this->count('alunos', false);
                foreach ((array)($student['guardians'] ?? []) as $ignored) {
                    $this->count('vínculos aluno-responsável', false);
                }
                $result[$code] = $student;
                continue;
            }
            $links = [];
            foreach ($spec['links'] as [$key, $relationship, $primary]) {
                $links[] = [
                    'guardian_id' => $guardians[$key]['_id'],
                    'relationship' => Relationship::from($relationship)->value,
                    'is_primary' => $primary,
                    'receives_absence_notifications' => true,
                ];
                $this->count('vínculos aluno-responsável', true);
            }
            $now = Bson::now();
            $result[$code] = $this->students->insert([
                'name' => $spec['name'],
                'student_code' => $code,
                'birth_date' => Bson::day(($year - self::CLASSES[$spec['class']]['age'] - 1) . '-' . $spec['birth']),
                'guardians' => $links,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->count('alunos', true);
        }
        return $result;
    }

    /**
     * Matrícula ativa de cada aluno (cria na turma de demonstração quando o aluno não tem nenhuma).
     *
     * @param array<string, array<string, mixed>> $students
     * @param array<string, array<string, mixed>> $classes
     * @param array<string, mixed> $period
     * @return array<string, array{enrollment: array<string, mixed>, class_name: string}> matrícula escolar => matrícula
     */
    private function seedEnrollments(array $students, array $classes, array $period): array
    {
        $today = Clock::today();
        $periodStart = (string)Bson::ymd($period['start_date']);
        $start = min($periodStart, $today);
        $classNames = [];
        foreach ($classes as $class) {
            $classNames[(string)$class['_id']] = (string)$class['name'];
        }

        $result = [];
        foreach ($students as $code => $student) {
            if (($student['active'] ?? false) !== true) {
                $this->notes[] = "O aluno {$code} está arquivado: ficou sem matrícula e sem frequência de demonstração.";
                continue;
            }
            $enrollment = $this->enrollments->findActiveByStudent($student['_id']);
            if ($enrollment !== null) {
                $this->count('matrículas', false);
            } else {
                $class = $classes[self::STUDENTS[$code]['class']] ?? null;
                if ($class === null) {
                    continue;
                }
                try {
                    $enrollment = $this->enrollments->insertActive($student['_id'], $class['_id'], $period['_id'], Bson::day($start));
                    $this->count('matrículas', true);
                } catch (ConflictException) {
                    $enrollment = $this->enrollments->findActiveByStudent($student['_id']);
                    if ($enrollment === null) {
                        continue;
                    }
                    $this->count('matrículas', false);
                }
            }
            $classId = (string)$enrollment['class_id'];
            if (!isset($classNames[$classId])) {
                $classNames[$classId] = (string)($this->classes->findById($enrollment['class_id'])['name'] ?? 'Turma');
            }
            $result[$code] = ['enrollment' => $enrollment, 'class_name' => $classNames[$classId]];
        }
        return $result;
    }

    /**
     * Frequência dos dias úteis da janela (presenças e faltas), justificativas e e-mails de aviso.
     *
     * @param array<string, array<string, mixed>> $students
     * @param array<string, array{enrollment: array<string, mixed>, class_name: string}> $enrollments
     * @param array<string, array<string, mixed>> $guardians
     * @param array<string, mixed> $period
     * @return array{from: string|null, to: string|null}
     */
    private function seedAttendance(array $students, array $enrollments, array $guardians, array $period, ObjectId $registrar): array
    {
        $today = Clock::today();
        $from = max(self::shift($today, -self::HISTORY_DAYS), (string)Bson::ymd($period['start_date']));
        $to = min($today, (string)Bson::ymd($period['end_date']));
        $days = self::businessDays($from, $to);
        if ($days === []) {
            $this->notes[] = 'Nenhum dia útil do período letivo ativo cabe na janela do histórico: a frequência não foi gerada.';
            return ['from' => null, 'to' => null];
        }

        $guardiansById = [];
        foreach ($guardians as $guardian) {
            $guardiansById[(string)$guardian['_id']] = $guardian;
        }
        $failedDone = false;

        foreach ($enrollments as $code => $item) {
            $student = $students[$code];
            $enrollment = $item['enrollment'];
            $enrolledFrom = (string)Bson::ymd($enrollment['start_date']);
            $rate = self::CLASSES[self::STUDENTS[$code]['class'] ?? '5A']['absence_rate'] ?? 5;
            [$notify, $bounced] = self::recipients($student, $guardiansById);
            $studentDays = array_values(array_filter($days, static fn (string $day): bool => $day >= $enrolledFrom));
            $forcedFailedDay = $code === self::BOUNCED_STUDENT && count($studentDays) >= 3 ? $studentDays[count($studentDays) - 3] : null;

            foreach ($studentDays as $day) {
                $absent = self::roll($code, $day, 'absent') < $rate
                    || ($day === $today && in_array($code, self::ABSENT_TODAY, true))
                    || $day === $forcedFailedDay;
                $this->insertDay($student, $enrollment, $item['class_name'], $day, $absent, $notify, $bounced, $registrar, $day === $forcedFailedDay && !$failedDone);
                if ($day === $forcedFailedDay) {
                    $failedDone = true;
                }
            }
        }
        return ['from' => $days[0], 'to' => $days[count($days) - 1]];
    }

    /**
     * Grava o registro do dia (e, para faltas, a justificativa e o e-mail). Registro já existente = "já existia".
     *
     * @param array<string, mixed>|null $notify  responsável principal que recebe avisos (e-mail VALID), se houver
     * @param array<string, mixed>|null $bounced responsável cujo e-mail retornou (para o e-mail FAILED)
     */
    private function insertDay(
        array $student,
        array $enrollment,
        string $className,
        string $day,
        bool $absent,
        ?array $notify,
        ?array $bounced,
        ObjectId $registrar,
        bool $failedNotification,
    ): void {
        $now = Clock::now();
        $created = self::instant($day, '11:10:00', $now);
        $code = (string)$student['student_code'];
        $record = [
            '_id' => new ObjectId(),
            'enrollment_id' => $enrollment['_id'],
            'student_id' => $student['_id'],
            'class_id' => $enrollment['class_id'],
            'class_name' => mb_substr($className, 0, 100),
            'academic_period_id' => $enrollment['academic_period_id'],
            'date' => Bson::day($day),
            'status' => $absent ? AttendanceStatus::ABSENT->value : AttendanceStatus::PRESENT->value,
            'registered_by' => $registrar,
            'created_at' => $created,
        ];

        $notification = null;
        if ($absent) {
            $age = (int)(new DateTimeImmutable($day, new DateTimeZone('UTC')))->diff(new DateTimeImmutable(Clock::today(), new DateTimeZone('UTC')))->days;
            $responded = $notify !== null && $age >= self::RESPONSE_MIN_AGE_DAYS && self::roll($code, $day, 'responded') < self::RESPONDED_PERCENT;
            $history = [[
                'status' => JustificationStatus::PENDING->value,
                'at' => $created,
                'by_user_id' => $registrar,
                'by_guardian_id' => null,
            ]];
            $justification = [
                'status' => JustificationStatus::PENDING->value,
                'guardian_id' => null,
                'reason_code' => null,
                'explanation' => null,
                'submitted_at' => null,
                'notification_id' => null,
                'status_history' => $history,
            ];
            $recipient = $failedNotification ? $bounced : $notify;
            if ($recipient !== null) {
                $notification = ['_id' => new ObjectId(), 'guardian' => $recipient];
            }
            if ($responded) {
                [$reason, $explanation] = self::REASONS[self::roll($code, $day, 'reason') % count(self::REASONS)];
                $submitted = self::instant(self::shift($day, 1), '19:30:00', $now);
                $justification = [
                    'status' => JustificationStatus::RESPONDED->value,
                    'guardian_id' => $notify['_id'],
                    'reason_code' => JustificationReason::from($reason)->value,
                    'explanation' => $explanation,
                    'submitted_at' => $submitted,
                    'notification_id' => $notification !== null ? $notification['_id'] : null,
                    'status_history' => [...$history, [
                        'status' => JustificationStatus::RESPONDED->value,
                        'at' => $submitted,
                        'by_user_id' => null,
                        'by_guardian_id' => $notify['_id'],
                    ]],
                ];
            }
            $record['details'] = ['source' => AttendanceService::SOURCE, 'note' => null];
            $record['justification'] = $justification;
        }

        try {
            $this->attendance->insert($record);
        } catch (ConflictException) {
            $this->count($absent ? 'faltas' : 'presenças', false);
            return;
        }
        $this->count($absent ? 'faltas' : 'presenças', true);
        if ($absent) {
            $this->count($record['justification']['status'] === JustificationStatus::RESPONDED->value ? 'faltas justificadas' : 'faltas pendentes', true);
        }
        if ($notification !== null) {
            $this->insertNotification($notification['_id'], $record, $student, $notification['guardian'], $registrar, $failedNotification);
        }
    }

    /**
     * E-mail de aviso coerente com o schema: corpo com o marcador {{justification_link}}, hash de um token aleatório
     * (nunca o token) e eventos conforme o status (SENT → DELIVERED → OPENED; FAILED com 3 tentativas).
     *
     * @param array<string, mixed> $record
     * @param array<string, mixed> $student
     * @param array<string, mixed> $guardian
     */
    private function insertNotification(ObjectId $id, array $record, array $student, array $guardian, ObjectId $createdBy, bool $failed): void
    {
        $now = Clock::now();
        $created = Bson::dateTime($record['created_at']) ?? $now;
        $at = static fn (string $offset): UTCDateTime => new UTCDateTime(min($created->modify($offset), $now));
        $rendered = $this->templates->renderAbsenceNotice([
            'guardian_name' => (string)$guardian['name'],
            'student_name' => (string)$student['name'],
            'class_name' => (string)$record['class_name'],
            'absence_date' => Dates::formatDate($record['date']),
            'note' => '',
        ]);
        $justification = $record['justification'];
        $responded = $justification['status'] === JustificationStatus::RESPONDED->value;

        $event = static fn (NotificationEventType $type, UTCDateTime $when, ?string $detail = null): array => [
            'type' => $type->value, 'occurred_at' => $when, 'detail' => $detail,
        ];
        if ($failed) {
            $status = NotificationStatus::FAILED;
            $events = [
                $event(NotificationEventType::FAILED, $at('+1 minute'), self::FAILED_DETAIL),
                $event(NotificationEventType::FAILED, $at('+6 minutes'), self::FAILED_DETAIL),
                $event(NotificationEventType::FAILED, $at('+36 minutes'), self::FAILED_DETAIL),
            ];
        } else {
            $events = [$event(NotificationEventType::SENT, $at('+1 minute'))];
            $status = NotificationStatus::SENT;
            if (Bson::ymd($record['date']) !== Clock::today()) {
                $events[] = $event(NotificationEventType::DELIVERED, $at('+2 minutes'));
                $status = NotificationStatus::DELIVERED;
            }
            if ($responded) {
                $submitted = Bson::dateTime($justification['submitted_at']) ?? $now;
                $events[] = $event(NotificationEventType::OPENED, new UTCDateTime($submitted->modify('-20 minutes')));
                $events[] = $event(NotificationEventType::LINK_ACCESSED, new UTCDateTime($submitted->modify('-15 minutes')));
                $status = NotificationStatus::OPENED;
            }
        }

        $this->notifications->insert([
            '_id' => $id,
            'attendance_id' => $record['_id'],
            'student_id' => $record['student_id'],
            'guardian_id' => $guardian['_id'],
            'recipient_email' => (string)$guardian['email'],
            'subject' => $rendered['subject'],
            'body' => $rendered['body'],
            'status' => $status->value,
            'attempts' => $failed ? Schema::MAX_NOTIFICATION_ATTEMPTS : 1,
            'provider_message_id' => $failed ? null : 'demo-' . bin2hex(random_bytes(12)),
            'justification_link' => [
                'token_hash' => hash('sha256', random_bytes(32)),
                'expires_at' => new UTCDateTime($created->add(new DateInterval('P30D'))),
                'used_at' => $responded ? $justification['submitted_at'] : null,
            ],
            'events' => $events,
            'resend_of' => null,
            'locked_at' => null,
            'next_attempt_at' => null, // FAILED com 3 tentativas: definitivo, a fila não tenta de novo
            'last_error' => $failed ? self::FAILED_DETAIL : null,
            'created_by' => $createdBy,
            'created_at' => $at('+30 seconds'),
            'updated_at' => Bson::now(),
        ]);
        $this->count('e-mails de ausência', true);
        if ($failed) {
            $this->count('e-mails com falha', true);
        }
    }

    // ------------------------------------------------------------------ utilitários

    /**
     * Responsável principal que recebe avisos (ativo, e-mail VALID) e responsável com e-mail que retornou.
     *
     * @param array<string, mixed> $student
     * @param array<string, array<string, mixed>> $guardiansById
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    private static function recipients(array $student, array $guardiansById): array
    {
        $links = array_values(array_filter((array)($student['guardians'] ?? []), 'is_array'));
        usort($links, static fn (array $a, array $b): int => (int)($b['is_primary'] ?? false) <=> (int)($a['is_primary'] ?? false));
        $notify = null;
        $bounced = null;
        foreach ($links as $link) {
            $guardian = $guardiansById[(string)($link['guardian_id'] ?? '')] ?? null;
            if ($guardian === null || ($guardian['active'] ?? false) !== true || ($link['receives_absence_notifications'] ?? false) !== true) {
                continue;
            }
            if ($notify === null && ($guardian['email_status'] ?? null) === EmailStatus::VALID->value) {
                $notify = $guardian;
            }
            if ($bounced === null && ($guardian['email_status'] ?? null) === EmailStatus::BOUNCED->value) {
                $bounced = $guardian;
            }
        }
        return [$notify, $bounced];
    }

    /** Sorteio determinístico 0..99 (a mesma entrada sempre dá o mesmo resultado). */
    private static function roll(string $code, string $day, string $salt): int
    {
        return (int)(crc32($code . '|' . $day . '|' . $salt) % 100);
    }

    /** @return list<string> dias de segunda a sexta entre $from e $to (inclusive), AAAA-MM-DD */
    private static function businessDays(string $from, string $to): array
    {
        $days = [];
        $utc = new DateTimeZone('UTC');
        for ($day = new DateTimeImmutable($from, $utc), $end = new DateTimeImmutable($to, $utc); $day <= $end; $day = $day->modify('+1 day')) {
            if ((int)$day->format('N') <= 5) {
                $days[] = $day->format('Y-m-d');
            }
        }
        return $days;
    }

    private static function shift(string $ymd, int $days): string
    {
        return (new DateTimeImmutable($ymd, new DateTimeZone('UTC')))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    /** Instante "dia + hora UTC", nunca depois de agora. */
    private static function instant(string $day, string $time, DateTimeImmutable $now): UTCDateTime
    {
        $at = new DateTimeImmutable($day . 'T' . $time . 'Z');
        return new UTCDateTime($at > $now ? $now : $at);
    }

    private function count(string $category, bool $created): void
    {
        $this->counts[$category] ??= ['created' => 0, 'existing' => 0];
        $this->counts[$category][$created ? 'created' : 'existing']++;
    }
}
