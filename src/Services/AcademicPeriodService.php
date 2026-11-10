<?php
declare(strict_types=1);

namespace Saa\Services;

use MongoDB\BSON\ObjectId;
use Saa\Core\Exceptions\ConflictException;
use Saa\Core\Exceptions\NotFoundException;
use Saa\Core\Exceptions\ValidationException;
use Saa\Core\Validator;
use Saa\Database\Bson;
use Saa\Models\AcademicPeriodModel;

/**
 * Períodos letivos: cadastro, edição e ativação (um único período vigente — MD-14), com a regra de frequência
 * mínima e o limite de alerta embutidos em attendance_policy (MD-12). Todas as escritas são auditadas.
 */
final class AcademicPeriodService
{
    private const LABELS = [
        'name' => 'o nome do período',
        'start_date' => 'a data de início',
        'end_date' => 'a data de término',
        'min_attendance_percent' => 'a frequência mínima',
        'alert_below_percent' => 'o limite de alerta',
    ];

    public function __construct(
        private readonly AcademicPeriodModel $periods,
        private readonly AuditService $audit,
    ) {
    }

    /** @return list<array<string, mixed>> períodos prontos para exibição */
    public function list(): array
    {
        return array_map(self::present(...), $this->periods->all());
    }

    /** @return array<string, mixed>|null período vigente (para exibição) */
    public function active(): ?array
    {
        $period = $this->periods->findActive();
        return $period === null ? null : self::present($period);
    }

    /**
     * @return array<string, string> id => nome (para filtros e seletores)
     */
    public function options(): array
    {
        $options = [];
        foreach ($this->periods->all() as $period) {
            $options[(string)$period['_id']] = (string)$period['name'] . (($period['active'] ?? false) === true ? ' (ativo)' : '');
        }
        return $options;
    }

    /**
     * @return array<string, mixed>
     * @throws NotFoundException
     */
    public function get(string $id): array
    {
        return self::present($this->find($id));
    }

    /**
     * Cadastra um período. Se ainda não houver período ativo, o novo já nasce ativo.
     *
     * @param array<string, mixed> $input name, start_date, end_date, min_attendance_percent, alert_below_percent
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function create(array $input): array
    {
        $fields = $this->validate($input);
        $now = Bson::now();
        $document = $fields + [
            'active' => $this->periods->findActive() === null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        try {
            $period = $this->periods->insert($document);
        } catch (ConflictException) {
            if ($document['active'] === true && $this->periods->findActive() !== null) {
                // outro período foi ativado ao mesmo tempo: grava este como inativo
                $period = $this->insertInactive($document);
            } else {
                throw new ValidationException(['name' => 'Já existe um período letivo com este nome.']);
            }
        }
        $this->audit->record('period.create', 'academic_periods', $period['_id'], ['name' => $period['name'], 'active' => $period['active']]);
        return self::present($period);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     * @throws NotFoundException
     * @throws ValidationException
     */
    public function update(string $id, array $input): array
    {
        $period = $this->find($id);
        $fields = $this->validate($input);
        try {
            $this->periods->update($period['_id'], $fields);
        } catch (ConflictException) {
            throw new ValidationException(['name' => 'Já existe um período letivo com este nome.']);
        }
        $this->audit->record('period.update', 'academic_periods', $period['_id'], ['name' => $fields['name']]);
        return self::present($this->find($id));
    }

    /**
     * Torna o período o único vigente.
     *
     * @return array<string, mixed>
     * @throws NotFoundException
     * @throws ConflictException ativação simultânea
     */
    public function activate(string $id): array
    {
        $period = $this->find($id);
        if (($period['active'] ?? false) !== true) {
            $this->periods->activate($period['_id']);
            $this->audit->record('period.activate', 'academic_periods', $period['_id'], ['name' => $period['name']]);
        }
        return self::present($this->find($id));
    }

    /**
     * Documento MongoDB → dados de exibição.
     *
     * @param array<string, mixed> $period
     * @return array<string, mixed>
     */
    public static function present(array $period): array
    {
        $policy = is_array($period['attendance_policy'] ?? null) ? $period['attendance_policy'] : [];
        return [
            'id' => (string)$period['_id'],
            'name' => (string)($period['name'] ?? ''),
            'start_date' => Bson::ymd($period['start_date'] ?? null),
            'end_date' => Bson::ymd($period['end_date'] ?? null),
            'min_attendance_percent' => $policy['min_attendance_percent'] ?? null,
            'alert_below_percent' => $policy['alert_below_percent'] ?? null,
            'active' => ($period['active'] ?? false) === true,
        ];
    }

    // ------------------------------------------------------------------ internos

    /** @return array<string, mixed> */
    private function find(string $id): array
    {
        $oid = RegistrySupport::idOrFail($id, 'Período letivo não encontrado.');
        return $this->periods->findById($oid) ?? throw new NotFoundException('Período letivo não encontrado.');
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function insertInactive(array $document): array
    {
        $document['active'] = false;
        try {
            return $this->periods->insert($document);
        } catch (ConflictException) {
            throw new ValidationException(['name' => 'Já existe um período letivo com este nome.']);
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{name: string, start_date: \MongoDB\BSON\UTCDateTime, end_date: \MongoDB\BSON\UTCDateTime, attendance_policy: array{min_attendance_percent: int, alert_below_percent: int}}
     * @throws ValidationException
     */
    private function validate(array $input): array
    {
        $validator = Validator::make($input, self::LABELS)
            ->required('name', 'start_date', 'end_date', 'min_attendance_percent', 'alert_below_percent')
            ->string('name', 50, 1)
            ->date('start_date')
            ->date('end_date')
            ->integer('min_attendance_percent', 0, 100)
            ->integer('alert_below_percent', 0, 100);
        $data = $validator->validated();
        if (isset($data['start_date'], $data['end_date']) && $data['end_date'] <= $data['start_date']) {
            $validator->addError('end_date', 'A data de término deve ser depois da data de início.');
        }
        if (isset($data['min_attendance_percent'], $data['alert_below_percent']) && $data['alert_below_percent'] < $data['min_attendance_percent']) {
            $validator->addError('alert_below_percent', 'O limite de alerta não pode ser menor que a frequência mínima.');
        }
        $data = $validator->validate();
        return [
            'name' => $data['name'],
            'start_date' => Bson::day($data['start_date']),
            'end_date' => Bson::day($data['end_date']),
            'attendance_policy' => [
                'min_attendance_percent' => $data['min_attendance_percent'],
                'alert_below_percent' => $data['alert_below_percent'],
            ],
        ];
    }

    /** Usado por outros serviços: período vigente como documento MongoDB. */
    public function activeDocument(): ?array
    {
        return $this->periods->findActive();
    }

    /** Usado por outros serviços. */
    public function document(ObjectId $id): ?array
    {
        return $this->periods->findById($id);
    }
}
