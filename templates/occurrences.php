<?php
$pageTitle = 'Ocorrências';
$hasFilters = array_filter($filters, static fn(string $value): bool => $value !== '') !== [];
?>
<section class="page-heading">
    <div>
        <span class="eyebrow">Acompanhamento</span>
        <h1>Ocorrências de ausência</h1>
        <p>Consulte o andamento das comunicações e as justificativas enviadas.</p>
    </div>
    <?php if (in_array($_SESSION['profile'] ?? '', ['AOE', 'COORDENACAO'], true)): ?>
        <a class="button button-primary" href="/chamada">Fazer chamada <span aria-hidden="true">+</span></a>
    <?php endif; ?>
</section>

<form class="card frequency-filters" method="get" action="/ocorrencias">
    <label>Status
        <select name="status">
            <option value="">Todas</option>
            <option value="PENDENTE" <?= $filters['status'] === 'PENDENTE' ? 'selected' : '' ?>>Pendentes</option>
            <option value="RESPONDIDA" <?= $filters['status'] === 'RESPONDIDA' ? 'selected' : '' ?>>Respondidas</option>
        </select>
    </label>
    <label>Turma
        <select name="turma_id">
            <option value="">Todas</option>
            <?php foreach ($classes as $class): ?>
                <option value="<?= e((string)$class['_id']) ?>" <?= $filters['turma_id'] === (string)$class['_id'] ? 'selected' : '' ?>><?= e($class['name']) ?> · <?= e($class['academic_year']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Aluno
        <select name="aluno_id">
            <option value="">Todos</option>
            <?php foreach ($students as $student): ?>
                <option value="<?= e((string)$student['_id']) ?>" <?= $filters['aluno_id'] === (string)$student['_id'] ? 'selected' : '' ?>><?= e($student['name']) ?><?= isset($student['registration_no']) ? ' · ' . e($student['registration_no']) : '' ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>De <input type="date" name="de" value="<?= e($filters['de']) ?>"></label>
    <label>Até <input type="date" name="ate" value="<?= e($filters['ate']) ?>"></label>
    <button class="button button-primary" type="submit">Aplicar filtros</button>
    <?php if ($hasFilters): ?><a class="button button-quiet" href="/ocorrencias">Limpar</a><?php endif; ?>
</form>

<section class="card">
    <div class="section-toolbar">
        <h2><?= $hasFilters ? 'Resultado da busca' : 'Registros recentes' ?></h2>
        <span class="muted"><?= count($occurrences) ?> ocorrência(s)</span>
    </div>
    <?php if (!$occurrences): ?>
        <div class="empty-state"><span class="empty-icon">✓</span><strong>Nenhuma ocorrência nesta lista</strong><span><?= $hasFilters ? 'Ajuste ou limpe os filtros para ver outros registros.' : 'Novos registros aparecerão aqui.' ?></span></div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Aluno</th><th>Turma</th><th>Data</th><th>Status</th><th>Comunicações</th><th>Justificativa</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($occurrences as $row): ?>
                    <tr>
                        <td data-label="Aluno"><strong><?= e($row['student_name']) ?></strong></td>
                        <td data-label="Turma"><?= e($row['class_name']) ?></td>
                        <td data-label="Data"><?= e(date('d/m/Y', strtotime($row['absence_date']))) ?></td>
                        <td data-label="Status"><span class="status status-<?= e(strtolower($row['status'])) ?>"><?= e(ucfirst(strtolower($row['status']))) ?></span></td>
                        <td data-label="Comunicações">
                            <?= (int)$row['communication_count'] ?>
                            <?php if ((int)$row['failed_count']): ?><span class="inline-warning"><?= (int)$row['failed_count'] ?> falha(s)</span><?php endif; ?>
                        </td>
                        <td data-label="Justificativa">
                            <?php if ($row['reason']): ?>
                                <?= e($reasons[$row['reason']] ?? $row['reason']) ?>
                                <?php if ($row['reason'] === 'OUTRO' && $row['explanation']): ?><small class="subline"><?= e($row['explanation']) ?></small><?php endif; ?>
                            <?php else: ?><span class="muted">Aguardando</span><?php endif; ?>
                        </td>
                        <td data-label="Detalhes"><a class="text-link" href="/ausencias/<?= e($row['id']) ?>">Ver detalhes <span aria-hidden="true">→</span></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="list-note">Mostrando até <?= (int)$limit ?> registros, dos mais recentes aos mais antigos. Turmas e alunos arquivados continuam disponíveis para consulta.</p>
    <?php endif; ?>
</section>
