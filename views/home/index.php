<?php
declare(strict_types=1);

/**
 * Protótipo da tela inicial (issue #22), dentro de layouts/app.
 * O <h1> ("Início") vem do layout; aqui ficam a saudação, o resumo do perfil, os atalhos e,
 * somente para o administrador, o card "Ambiente" com a conexão do MongoDB.
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
