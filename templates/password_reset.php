<?php $pageTitle = 'Nova senha'; ?>
<section class="login-layout">
    <div class="login-intro">
        <span class="eyebrow">Área da equipe</span>
        <h1>Crie uma nova senha.</h1>
        <p>Olá, <?= e($name) ?>. Escolha uma senha com pelo menos <?= (int)$minLength ?> caracteres. Depois de salvar, entre com a nova senha.</p>
    </div>
    <form class="card login-card" method="post">
        <div>
            <span class="eyebrow">Redefinição</span>
            <h2>Nova senha</h2>
        </div>
        <?php if ($error): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
        <label for="password">Nova senha</label>
        <input id="password" name="password" type="password" autocomplete="new-password" minlength="<?= (int)$minLength ?>" maxlength="72" required>
        <label for="password_confirmation">Confirme a nova senha</label>
        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="<?= (int)$minLength ?>" maxlength="72" required>
        <button class="button button-primary button-full" type="submit">Salvar nova senha</button>
    </form>
</section>
