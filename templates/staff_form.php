        <form method="post" action="/cadastros/usuario" class="stack-form">
            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
            <div class="field"><label for="user-name">Nome</label><input id="user-name" name="name" required maxlength="120"></div>
            <div class="field"><label for="user-email">E-mail</label><input id="user-email" name="email" type="email" required maxlength="254"></div>
            <div class="field"><label for="user-profile">Perfil</label><select id="user-profile" name="profile" required><option value="AOE">AOE</option><option value="COORDENACAO">Coordenação</option><option value="DIRETOR">Diretor</option></select></div>
            <div class="field"><label for="user-password">Senha inicial</label><input id="user-password" name="password" type="password" minlength="10" required><small class="form-hint">Mínimo de 10 caracteres.</small></div>
            <button class="button button-primary" type="submit">Criar usuário</button>
        </form>
