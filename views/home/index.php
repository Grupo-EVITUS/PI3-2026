<?php
declare(strict_types=1);

/**
 * Tela inicial (issues #22 e #28), dentro de layouts/app.
 * O <h1> ("Início") vem do layout; aqui ficam a saudação, o resumo do perfil, o período letivo ativo (ou o aviso de
 * que falta um), os números reais conforme o perfil (registry.view: alunos, responsáveis e turmas;
 * occurrences.view: ausências de hoje e justificativas pendentes), os atalhos e, somente para o administrador,
 * o card "Ambiente" com a conexão do MongoDB.
 *
 * @var array{period: array<string,mixed>|null, can_manage_periods: bool, registry: array<string,int>|null, occurrences: array<string,mixed>|null} $summary
 *
 * @var array{id?:string,name?:string,email?:string,profile?:string}                              $user
 * @var string                                                                                   $greeting  Bom dia | Boa tarde | Boa noite
 * @var list<array{label:string,path:string,icon:string,description:string}>                      $shortcuts itens de menu permitidos e existentes (exceto Início)
 * @var array{ok:bool,database?:?string,collections?:?int,server_version?:?string,driver_version?:?string}|null $environment somente ADMIN
 */
$homeUser = isset($user) && is_array($user) ? $user : [];
$homeName = trim((string)($homeUser['name'] ?? ''));
$homeFirstName = $homeName !== '' ? (string)strtok($homeName, ' ') : '';
$homeEmail = (string)($homeUser['email'] ?? '');
$homeProfile = (string)($homeUser['profile'] ?? '');
$homeGreeting = isset($greeting) && is_string($greeting) && $greeting !== '' ? $greeting : 'Olá';
$homeShortcuts = isset($shortcuts) && is_array($shortcuts) ? array_values($shortcuts) : [];
$homeEnvironment = isset($environment) && is_array($environment) ? $environment : null;
$homeEnvOk = $homeEnvironment !== null && !empty($homeEnvironment['ok']);
$homeSummary = isset($summary) && is_array($summary) ? $summary : [];
$homePeriod = isset($homeSummary['period']) && is_array($homeSummary['period']) ? $homeSummary['period'] : null;
$homeRegistry = isset($homeSummary['registry']) && is_array($homeSummary['registry']) ? $homeSummary['registry'] : null;
$homeOccurrences = isset($homeSummary['occurrences']) && is_array($homeSummary['occurrences']) ? $homeSummary['occurrences'] : null;
$homeCanManagePeriods = !empty($homeSummary['can_manage_periods']);
$homeNumber = static fn (mixed $value): string => number_format((int)$value, 0, ',', '.');
?>
<section class="hero" aria-labelledby="hero-title">
  <p class="hero__eyebrow">Ambiente interno</p>
  <h2 class="hero__title" id="hero-title"><?= e($homeGreeting . ($homeFirstName !== '' ? ', ' . $homeFirstName : '')) ?>.</h2>
  <p class="hero__text">
    O SAA ajuda a escola a registrar as ausências dos alunos, avisar os responsáveis por e-mail e acompanhar as justificativas em um só lugar.
  </p>
  <dl class="hero__meta">
    <div>
      <dt>Seu perfil</dt>
      <dd><?= $homeProfile !== '' ? badge('profile', $homeProfile) : '—' ?></dd>
    </div>
    <?php if ($homeEmail !== ''): ?>
      <div>
        <dt>E-mail</dt>
        <dd><?= e($homeEmail) ?></dd>
      </div>
    <?php endif; ?>
    <div>
      <dt>Áreas disponíveis</dt>
      <dd><?= e((string)count($homeShortcuts)) ?></dd>
    </div>
  </dl>
</section>

<section class="card" aria-labelledby="period-title">
  <div class="card__header">
    <div>
      <h2 class="card__title" id="period-title">Período letivo</h2>
      <p class="card__subtitle">As turmas, matrículas e ausências pertencem ao período letivo ativo.</p>
    </div>
    <?php if ($homePeriod !== null): ?>
      <span class="badge badge--success">Ativo</span>
    <?php endif; ?>
  </div>
  <?php if ($homePeriod !== null): ?>
    <dl class="kv">
      <dt>Período</dt><dd><?= e($homePeriod['name'] ?? '') ?></dd>
      <dt>Aulas</dt><dd><?= e(format_date($homePeriod['start_date'] ?? null)) ?> a <?= e(format_date($homePeriod['end_date'] ?? null)) ?></dd>
      <?php if (($homePeriod['min_attendance_percent'] ?? null) !== null): ?>
        <dt>Frequência mínima</dt><dd><?= e((string)$homePeriod['min_attendance_percent']) ?>%</dd>
      <?php endif; ?>
    </dl>
  <?php else: ?>
    <div class="alert alert--warning" role="status">
      <?= partial('icon', ['name' => 'alert', 'class' => 'alert__icon']) ?>
      <div class="alert__body">
        <span class="alert__title">Nenhum período letivo ativo</span>
        <?php if ($homeCanManagePeriods): ?>
          Cadastre o período letivo (ou ative um já cadastrado) para organizar turmas, matrículas e ausências.
          <a href="<?= e(url('/periods')) ?>">Ir para períodos letivos</a>
        <?php else: ?>
          Turmas, matrículas e ausências dependem de um período letivo ativo. Peça à secretaria ou ao administrador para cadastrá-lo.
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</section>

<?php if ($homeRegistry !== null || $homeOccurrences !== null): ?>
  <section aria-labelledby="summary-title">
    <div class="section__header">
      <h2 id="summary-title">Resumo</h2>
      <p class="section__subtitle">Números atualizados agora, direto do banco de dados.</p>
    </div>
    <div class="grid grid--auto">
      <?php if ($homeOccurrences !== null): ?>
        <?= partial('stat', ['label' => 'Ausências hoje', 'value' => $homeNumber($homeOccurrences['absences_today'] ?? 0), 'note' => 'Registradas em ' . format_date($homeOccurrences['today'] ?? null) . '.', 'accent' => 'red']) ?>
        <?= partial('stat', ['label' => 'Justificativas pendentes', 'value' => $homeNumber($homeOccurrences['pending'] ?? 0), 'note' => 'Ausências aguardando a resposta do responsável.', 'accent' => 'amber']) ?>
      <?php endif; ?>
      <?php if ($homeRegistry !== null): ?>
        <?= partial('stat', ['label' => 'Alunos ativos', 'value' => $homeNumber($homeRegistry['students'] ?? 0), 'note' => 'Cadastros não arquivados.']) ?>
        <?= partial('stat', ['label' => 'Responsáveis ativos', 'value' => $homeNumber($homeRegistry['guardians'] ?? 0), 'note' => 'Contatos que podem receber os avisos.', 'accent' => 'purple']) ?>
        <?= partial('stat', ['label' => 'Turmas ativas', 'value' => $homeNumber($homeRegistry['classes'] ?? 0), 'note' => $homePeriod !== null ? 'No período ' . ($homePeriod['name'] ?? '') . '.' : 'Sem período letivo ativo.', 'accent' => 'green']) ?>
      <?php endif; ?>
    </div>
  </section>
<?php endif; ?>

<section aria-labelledby="shortcuts-title">
  <div class="section__header">
    <h2 id="shortcuts-title">Acesso rápido</h2>
    <p class="section__subtitle">As áreas do sistema liberadas para o seu perfil.</p>
  </div>

  <?php if ($homeShortcuts === []): ?>
    <?= partial('empty_state', [
        'icon' => 'shield',
        'title' => 'Nenhuma área disponível por enquanto',
        'text' => 'Seu perfil ainda não tem atalhos liberados. Novas áreas aparecem aqui conforme o sistema evolui; em caso de dúvida, procure o administrador.',
        'action_href' => '/me',
        'action_label' => 'Ver o que meu perfil permite',
    ]) ?>
  <?php else: ?>
    <div class="grid grid--auto">
      <?php foreach ($homeShortcuts as $shortcut): ?>
        <a class="shortcut" href="<?= e(url((string)($shortcut['path'] ?? '/'))) ?>">
          <span class="shortcut__icon"><?= partial('icon', ['name' => (string)($shortcut['icon'] ?? 'info'), 'class' => 'icon--lg']) ?></span>
          <span class="shortcut__title"><?= e($shortcut['label'] ?? '') ?></span>
          <span class="shortcut__text"><?= e($shortcut['description'] ?? '') ?></span>
          <span class="shortcut__arrow"><?= partial('icon', ['name' => 'arrow-right']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<?php if ($homeEnvironment !== null): ?>
  <section class="card" aria-labelledby="env-title">
    <div class="card__header">
      <div>
        <h2 class="card__title" id="env-title">Ambiente</h2>
        <p class="card__subtitle">Conexão do SAA com o MongoDB. Esta informação aparece só para administradores.</p>
      </div>
      <?php if ($homeEnvOk): ?>
        <span class="badge badge--success">Conectado</span>
      <?php else: ?>
        <span class="badge badge--danger">Sem conexão</span>
      <?php endif; ?>
    </div>

    <?php if (!$homeEnvOk): ?>
      <div class="alert alert--error" role="alert">
        <?= partial('icon', ['name' => 'alert', 'class' => 'alert__icon']) ?>
        <div class="alert__body">
          <span class="alert__title">Não foi possível falar com o MongoDB</span>
          Confira a variável MONGODB_URI e se o serviço do banco está em execução. As telas que dependem de dados não funcionam sem essa conexão.
        </div>
      </div>
    <?php endif; ?>

    <div class="grid grid--4">
      <?= partial('stat', ['label' => 'Banco de dados', 'value' => $homeEnvironment['database'] ?? '—', 'compact' => true]) ?>
      <?= partial('stat', ['label' => 'Coleções', 'value' => $homeEnvironment['collections'] ?? '—', 'compact' => true]) ?>
      <?= partial('stat', ['label' => 'Servidor MongoDB', 'value' => $homeEnvironment['server_version'] ?? '—', 'compact' => true]) ?>
      <?= partial('stat', ['label' => 'Driver PHP', 'value' => $homeEnvironment['driver_version'] ?? '—', 'compact' => true]) ?>
    </div>
  </section>
<?php endif; ?>
