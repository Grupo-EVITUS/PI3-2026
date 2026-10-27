<?php $pageTitle = 'Entrar'; ?>
<section class="login-layout">
    <div class="login-intro">
        <span class="eyebrow">Sistema escolar</span>
        <h1>Comunicação de ausências, com cuidado e clareza.</h1>
        <p>Registre faltas, acompanhe os avisos aos responsáveis e consulte justificativas em um só lugar.</p>
        <div class="intro-points">
            <span>01 <b>Registre a ocorrência</b></span>
            <span>02 <b>Comunique os responsáveis</b></span>
            <span>03 <b>Acompanhe a justificativa</b></span>
        </div>
    </div>
    <form class="card login-card" method="post" action="/login">
        <div>
            <span class="eyebrow">Área da equipe</span>
            <h2>Acessar o sistema</h2>
        </div>
        <?php if ($error): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
        <input type="hidden" name="next" value="<?= e($next ?? '') ?>">
        <label for="email">E-mail institucional</label>
        <input id="email" name="email" type="email" autocomplete="username" required value="<?= e($email) ?>">
        <label for="password">Senha</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <button class="button button-primary button-full" type="submit">Entrar</button>
        <p class="form-hint">O acesso é exclusivo para funcionários autorizados.</p>
    </form>
</section>
