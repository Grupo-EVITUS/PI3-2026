<?php
declare(strict_types=1);

/**
 * Protótipo da tela de login (issue #21), dentro de layouts/auth (cartão central com a marca).
 * O <h1> ("Entrar") vem do layout; aqui ficam boas-vindas, aviso de ambiente interno e o formulário.
 *
 * @var string                $email   valor anterior do campo e-mail
 * @var string|null           $next    destino após o login (somente caminho interno; validado no servidor)
 * @var string|null           $error   mensagem genérica (credenciais inválidas ou limite de tentativas)
 * @var array<string,string>  $errors  campo => mensagem de validação
 */
$loginEmail = isset($email) && is_string($email) ? $email : (string)old('email', '');
$loginNext = isset($next) && is_string($next) ? $next : '';
$loginError = isset($error) && is_string($error) && $error !== '' ? $error : null;
$loginErrors = isset($errors) && is_array($errors) ? $errors : [];
// Foco inicial: primeiro campo com erro; sem erros, o e-mail (vazio) ou a senha (e-mail já preenchido).
$loginFocus = isset($loginErrors['email']) ? 'email'
    : (isset($loginErrors['password']) ? 'password' : ($loginEmail === '' ? 'email' : 'password'));
?>
<p class="auth-card__lead">Bem-vindo de volta! Entre para registrar ausências, avisar os responsáveis e acompanhar as justificativas da escola.</p>

<p class="pill">
  <?= partial('icon', ['name' => 'lock', 'class' => 'icon--sm']) ?>
  <span>Ambiente interno · acesso restrito à equipe</span>
</p>

<?php if ($loginError !== null): ?>
  <div class="alert alert--error" role="alert">
    <?= partial('icon', ['name' => 'alert', 'class' => 'alert__icon']) ?>
    <div class="alert__body"><?= e($loginError) ?></div>
  </div>
<?php endif; ?>

<form class="form" method="post" action="<?= e(url('/login')) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="next" value="<?= e($loginNext) ?>">

  <?= partial('field', [
      'name' => 'email',
      'label' => 'E-mail',
      'type' => 'email',
      'value' => $loginEmail,
      'error' => $loginErrors['email'] ?? null,
      'required' => true,
      'autocomplete' => 'username',
      'placeholder' => 'nome@escola.com.br',
      'autofocus' => $loginFocus === 'email',
      'attrs' => ['maxlength' => '254', 'inputmode' => 'email', 'autocapitalize' => 'none', 'spellcheck' => 'false'],
  ]) ?>

  <?= partial('field', [
      'name' => 'password',
      'label' => 'Senha',
      'type' => 'password',
      'error' => $loginErrors['password'] ?? null,
      'required' => true,
      'autocomplete' => 'current-password',
      'toggle' => true,
      'autofocus' => $loginFocus === 'password',
      'attrs' => ['maxlength' => '200'],
  ]) ?>

  <div class="cluster cluster--end">
    <a class="link-action" href="<?= e(url('/forgot-password')) ?>">Esqueceu a senha?</a>
  </div>

  <button class="btn btn--primary btn--lg btn--block" type="submit">Entrar</button>
</form>

<p class="auth-card__note">
  É responsável por um aluno? Você não precisa de conta: use o link que recebeu por e-mail para justificar a ausência.
</p>
