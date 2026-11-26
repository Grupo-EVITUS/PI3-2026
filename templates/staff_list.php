<?php
$pageTitle = 'Funcionários';
$crumbs = [['Funcionários', null]];
$profileLabels = ['AOE' => 'AOE', 'COORDENACAO' => 'Coordenação', 'DIRETOR' => 'Diretor'];
$hasFilters = array_filter($filters, static fn(string $value): bool => $value !== '') !== [];
?>
<section class="page-heading">
    <div>
        <span class="eyebrow">Equipe</span>
        <h1>Funcionários</h1>
        <p>Consulte as contas de acesso ao sistema. Para criar uma conta, use o cadastro de funcionário.</p>
    </div>
    <a class="button button-primary" href="/cadastros?aba=funcionarios">Cadastrar funcionário <span aria-hidden="true">+</span></a>
</section>

<form class="card frequency-filters" method="get" action="/funcionarios">
    <label>Buscar por nome ou e-mail
        <input type="search" name="busca" maxlength="100" value="<?= e($filters['busca']) ?>" placeholder="Ex.: ana ou @escola">
    </label>
    <label>Perfil
        <select name="perfil">
            <option value="">Todos</option>
            <?php foreach ($profileLabels as $code => $label): ?>
                <option value="<?= e($code) ?>" <?= $filters['perfil'] === $code ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Situação
        <select name="situacao">
            <option value="">Todas</option>
            <option value="ativos" <?= $filters['situacao'] === 'ativos' ? 'selected' : '' ?>>Ativos</option>
            <option value="inativos" <?= $filters['situacao'] === 'inativos' ? 'selected' : '' ?>>Inativos</option>
        </select>
    </label>
    <button class="button button-primary" type="submit">Buscar</button>
    <?php if ($hasFilters): ?><a class="button button-quiet" href="/funcionarios">Limpar</a><?php endif; ?>
</form>

<section class="card">
    <div class="section-toolbar">
        <h2><?= $hasFilters ? 'Resultado da busca' : 'Contas cadastradas' ?></h2>
        <span class="muted"><?= count($staff) ?> funcionário(s)</span>
    </div>
    <?php if (!$staff): ?>
        <div class="empty-state"><span class="empty-icon">✓</span><strong>Nenhum funcionário encontrado</strong><span><?= $hasFilters ? 'Ajuste ou limpe os filtros para ver outras contas.' : 'Cadastre o primeiro funcionário.' ?></span></div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Situação</th><th>Cadastrado em</th></tr></thead>
                <tbody>
                <?php foreach ($staff as $member): ?>
                    <tr>
                        <td data-label="Nome"><strong><?= e($member['name']) ?></strong></td>
                        <td data-label="E-mail"><?= e($member['email']) ?></td>
                        <td data-label="Perfil"><?= e($profileLabels[$member['profile']] ?? $member['profile']) ?></td>
                        <td data-label="Situação"><span class="status status-<?= $member['active'] ? 'enviado' : 'falha' ?>"><?= $member['active'] ? 'Ativo' : 'Inativo' ?></span></td>
                        <td data-label="Cadastrado em"><?= $member['created_at'] ? e(date('d/m/Y', strtotime($member['created_at']))) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="list-note">Mostrando até <?= (int)$limit ?> contas, em ordem alfabética. Senhas nunca são exibidas.</p>
    <?php endif; ?>
</section>
