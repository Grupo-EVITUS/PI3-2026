<?php $pageTitle = 'Ocorrências'; ?>
<section class="page-heading">
    <div>
        <span class="eyebrow">Acompanhamento</span>
        <h1>Ocorrências de ausência</h1>
        <p>Consulte o andamento das comunicações e as justificativas enviadas.</p>
    </div>
    <?php if (in_array($_SESSION['profile'] ?? '', ['AOE', 'COORDENACAO'], true)): ?>
        <a class="button button-primary" href="/ausencias/registrar">Registrar ausência <span aria-hidden="true">+</span></a>
    <?php endif; ?>
</section>

<section class="card">
    <div class="section-toolbar">
        <h2>Registros recentes</h2>
        <nav class="filter-tabs" aria-label="Filtrar por status">
            <a class="<?= $activeStatus === '' ? 'selected' : '' ?>" href="/ocorrencias">Todas</a>
            <a class="<?= $activeStatus === 'PENDENTE' ? 'selected' : '' ?>" href="/ocorrencias?status=PENDENTE">Pendentes</a>
            <a class="<?= $activeStatus === 'RESPONDIDA' ? 'selected' : '' ?>" href="/ocorrencias?status=RESPONDIDA">Respondidas</a>
        </nav>
    </div>
    <?php if (!$occurrences): ?>
        <div class="empty-state"><span class="empty-icon">✓</span><strong>Nenhuma ocorrência nesta lista</strong><span>Novos registros aparecerão aqui.</span></div>
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
                                <?= e(Services::REASONS[$row['reason']] ?? $row['reason']) ?>
                                <?php if ($row['reason'] === 'OUTRO' && $row['explanation']): ?><small class="subline"><?= e($row['explanation']) ?></small><?php endif; ?>
                            <?php else: ?><span class="muted">Aguardando</span><?php endif; ?>
                        </td>
                        <td data-label="Detalhes"><a class="text-link" href="/ausencias/<?= (int)$row['id'] ?>">Ver detalhes <span aria-hidden="true">→</span></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="list-note">A busca avançada por aluno, turma ou data fica para uma versão futura.</p>
    <?php endif; ?>
</section>
