<?php $pageTitle = 'Registro realizado'; ?>
<section class="page-heading">
    <div>
        <span class="eyebrow">Operação concluída</span>
        <h1>Ausências registradas</h1>
        <p><?= count($result['absences']) ?> ocorrência(s) registrada(s) para <?= e(date('d/m/Y', strtotime($date))) ?>.</p>
    </div>
    <a class="button button-primary" href="/ausencias/registrar">Novo registro</a>
</section>

<?php if ($result['without_guardians']): ?>
    <div class="notice notice-warning"><strong>Sem comunicação:</strong> não há responsável cadastrado para <?= e(implode(', ', $result['without_guardians'])) ?>. Cadastre o contato para futuros avisos.</div>
<?php endif; ?>

<section class="card">
    <h2>Resumo por aluno</h2>
    <div class="result-list">
        <?php foreach ($result['absences'] as $absence): ?>
            <article class="result-row">
                <div>
                    <strong><?= e($absence['student_name']) ?></strong>
                    <a class="text-link result-detail-link" href="/ausencias/<?= (int)$absence['id'] ?>">Detalhes da ocorrência <span aria-hidden="true">→</span></a>
                </div>
                <?php
                $studentCommunications = array_values(array_filter(
                    $communications,
                    static fn(array $communication): bool => (int)$communication['absence_id'] === (int)$absence['id']
                ));
                ?>
                <?php if (!$studentCommunications): ?>
                    <span class="no-recipient">Sem responsável cadastrado</span>
                <?php else: ?>
                    <div class="communication-summary">
                        <?php foreach ($studentCommunications as $communication): ?>
                            <div class="communication-summary-row">
                                <span><strong><?= e($communication['guardian_name']) ?></strong><small><?= e($communication['email_to']) ?></small></span>
                                <span class="status status-<?= e(strtolower($communication['status'])) ?>"><?= e(ucfirst(strtolower($communication['status']))) ?></span>
                                <?php if ($communication['status'] === 'FALHA'): ?>
                                    <form method="post" action="/comunicacoes/reenvio">
                                        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                                        <input type="hidden" name="communication_id" value="<?= (int)$communication['id'] ?>">
                                        <button class="button button-small button-outline" type="submit">Reenviar</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
    <p class="list-note">O envio acontece em segundo plano. Enquanto aguarda o processamento, a comunicação aparece como Pendente; atualize os detalhes para consultar o resultado.</p>
</section>
