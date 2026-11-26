<?php
$pageTitle = 'Início';
$profile = $_SESSION['profile'] ?? '';
$firstName = trim(explode(' ', trim((string)($_SESSION['user_name'] ?? '')))[0] ?? '');
$canCall = in_array($profile, ['AOE', 'COORDENACAO'], true);
$isCoordination = $profile === 'COORDENACAO';
$canSeeFrequency = in_array($profile, ['COORDENACAO', 'DIRETOR'], true);

// [ícone, título, descrição, endereço, destaque?]
$daily = [];
if ($canCall) {
    $daily[] = ['📋', 'Fazer chamada', 'Escolha a turma, marque quem faltou e avise os responsáveis.', '/chamada', true];
}
$daily[] = ['⏳', 'Ocorrências pendentes', 'Faltas que ainda esperam a justificativa do responsável.', '/ocorrencias?status=PENDENTE', false];
$daily[] = ['🗂️', 'Todas as ocorrências', 'Busque por aluno, turma, período ou situação.', '/ocorrencias', false];
if ($canSeeFrequency) {
    $daily[] = ['📊', 'Frequência', 'Taxa de presença por turma e por aluno, na semana, no mês ou no semestre.', '/frequencia', false];
}
$registry = [];
if ($isCoordination) {
    $registry = [
        ['🏫', 'Turmas', 'Cadastre e organize as turmas do ano letivo.', '/cadastros?aba=turmas'],
        ['🎒', 'Alunos', 'Inclua alunos, corrija dados e troque de turma.', '/cadastros?aba=alunos'],
        ['👪', 'Responsáveis', 'Contatos, vínculos com os alunos e convite de notificações.', '/cadastros?aba=responsaveis'],
        ['👤', 'Funcionários', 'Consulte as contas da equipe ou crie um novo acesso.', '/funcionarios'],
    ];
}
?>
<section class="page-heading">
    <div>
        <span class="eyebrow">Início</span>
        <h1><?= $firstName !== '' ? 'Olá, ' . e($firstName) : 'Olá' ?></h1>
        <p>O que você quer fazer agora? Cada tarefa está a um clique daqui.</p>
    </div>
</section>

<section class="home-section" aria-labelledby="home-daily">
    <h2 id="home-daily">Rotina</h2>
    <div class="action-grid">
        <?php foreach ($daily as [$icon, $title, $description, $href, $highlight]): ?>
            <a class="action-card<?= $highlight ? ' action-card-primary' : '' ?>" href="<?= e($href) ?>">
                <span class="action-icon" aria-hidden="true"><?= $icon ?></span>
                <span><strong><?= e($title) ?></strong><small><?= e($description) ?></small></span>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<?php if ($registry): ?>
<section class="home-section" aria-labelledby="home-registry">
    <h2 id="home-registry">Cadastros</h2>
    <div class="action-grid">
        <?php foreach ($registry as [$icon, $title, $description, $href]): ?>
            <a class="action-card" href="<?= e($href) ?>">
                <span class="action-icon" aria-hidden="true"><?= $icon ?></span>
                <span><strong><?= e($title) ?></strong><small><?= e($description) ?></small></span>
            </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
