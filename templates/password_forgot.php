<?php $pageTitle = 'Recuperar senha'; ?>
<section class="login-layout">
    <div class="login-intro">
        <span class="eyebrow">Área da equipe</span>
        <h1>Recupere o acesso à sua conta.</h1>
        <p>Informe o e-mail cadastrado e enviaremos um link para criar uma nova senha. O link vale por 1 hora e só pode ser usado uma vez.</p>
    </div>
    <form class="card login-card" method="post" action="/recuperar-senha">
        <div>
            <span class="eyebrow">Recuperar senha</span>
            <h2>Esqueci minha senha</h2>
        </div>
        <?php if ($sent): ?>
            <div class="notice notice-success" role="status">Se o e-mail informado estiver cadastrado, você receberá em instantes as instruções para redefinir a senha. Verifique também a caixa de spam.</div>
        <?php endif; ?>
        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
        <label for="email">E-mail institucional</label>
        <input id="email" name="email" type="email" autocomplete="username" required value="<?= e($email) ?>">
        <button class="button button-primary button-full" type="submit">Enviar link de redefinição</button>
        <p class="form-hint">Disponível para AOE e Coordenação. Em caso de dúvida, procure a Coordenação. <a class="text-link" href="/login">Voltar ao acesso</a></p>
    </form>
</section>
