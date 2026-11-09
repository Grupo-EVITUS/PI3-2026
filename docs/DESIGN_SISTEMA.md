# Design system e estrutura do frontend — SAA

**Issues:** #16 (planejamento do design do sistema), #21 (protótipo da tela de login), #22 (protótipo da tela inicial)
**Arquivos:** [`public/assets/css/saa.css`](../public/assets/css/saa.css) · [`public/assets/js/app.js`](../public/assets/js/app.js) ·
[`public/assets/img/`](../public/assets/img/) · [`views/`](../views/) · [`config/navigation.php`](../config/navigation.php)

## 1. Objetivo e princípios

Dar ao SAA uma interface clara para a AOE e a coordenação usarem todos os dias, em computador, tablet ou celular,
inspirada na linguagem visual da plataforma **Sala do Futuro** (azul-marinho de base, azul vivo para ações, superfícies
claras e cartões arredondados) — sem usar logotipo nem arquivo proprietário dela.

1. **Legível antes de bonito:** todo texto atende ao contraste WCAG AA (tabela da seção 2).
2. **Funciona sem JavaScript:** o JS só melhora (menu no celular, mostrar senha, confirmações).
3. **Seguro por padrão:** nenhum estilo ou script inline e nenhum recurso externo (CSP `'self'`); fontes do sistema.
4. **Um componente, um jeito:** classes BEM leves (`.btn`, `.btn--primary`, `.field__error`) e parciais PHP reutilizáveis.
5. **Acessível:** rótulos visíveis, foco sempre visível, alvos de toque de 44 px, landmarks e mensagens com `role="alert"`.

## 2. Paleta

Valores de `:root` em `saa.css`. As razões de contraste foram **calculadas** com a fórmula do WCAG 2.x a partir desses
valores (texto normal exige 4,5:1; componentes de interface e texto grande, 3:1).

| Token | Cor | Uso |
|---|---|---|
| `--navy` / `--navy-2` | `#24376b` / `#192b5a` | barra lateral, cabeçalho de boas-vindas, marca |
| `--action` | `#2a68d4` | botões primários, controles ativos, anel de foco |
| `--link` / `--action-hover` | `#1f5bc4` | links e hover de botões |
| `--blue` | `#4b91ea` | acento decorativo sobre o azul-marinho (nunca como texto) |
| `--blue-soft` | `#eaf3ff` | fundos informativos |
| `--ink` | `#17233f` | texto principal |
| `--muted` | `#58647f` | texto secundário (escurecido em relação ao protótipo antigo, `#6d7891`, que não passava no AA) |
| `--line` / `--border-strong` | `#e5eaf3` / `#7d89a3` | bordas decorativas / bordas de campos |
| `--surface` / `--bg` | `#ffffff` / `#f5f7fb` | cartões / fundo da página |
| `--green*`, `--amber*`, `--red*`, `--purple*` | ver CSS | estados: sucesso/respondida, pendente, erro/falha, destaque (cada estado tem cor decorativa, cor de texto AA, fundo suave e borda) |
| `--danger` | `#c0283a` | botão de ação destrutiva |

| Primeiro plano × fundo | Uso | Razão | Mínimo | Resultado |
|---|---|---:|---:|:-:|
| `--ink` #17233f × `--surface` #ffffff | texto principal | 15.57:1 | 4.5:1 | ✓ |
| `--ink` #17233f × `--bg` #f5f7fb | texto principal no fundo da página | 14.52:1 | 4.5:1 | ✓ |
| `--muted` #58647f × `--surface` #ffffff | texto secundário | 5.93:1 | 4.5:1 | ✓ |
| `--muted` #58647f × `--bg` #f5f7fb | texto secundário no fundo | 5.52:1 | 4.5:1 | ✓ |
| `--link` #1f5bc4 × `--surface` #ffffff | links | 6.27:1 | 4.5:1 | ✓ |
| `--navy` #24376b × `--surface` #ffffff | títulos e marca | 11.45:1 | 4.5:1 | ✓ |
| #ffffff × `--navy` #24376b | texto na barra lateral | 11.45:1 | 4.5:1 | ✓ |
| #ffffff × `--action` #2a68d4 | botão primário | 5.21:1 | 4.5:1 | ✓ |
| #ffffff × `--action-hover` #1f5bc4 | botão primário (hover) | 6.27:1 | 4.5:1 | ✓ |
| #ffffff × `--danger` #c0283a | botão perigoso | 5.82:1 | 4.5:1 | ✓ |
| `--green-text` #16794a × `--green-soft` #e5f8ed | badge sucesso/respondida | 4.91:1 | 4.5:1 | ✓ |
| `--amber-text` #755000 × `--amber-soft` #fff4d7 | badge pendente | 6.60:1 | 4.5:1 | ✓ |
| `--red-text` #b3243a × `--red-soft` #ffe8ea | badge erro/falha | 5.57:1 | 4.5:1 | ✓ |
| `--purple-text` #6a2ec0 × `--purple-soft` #f2eafe | badge destaque | 6.50:1 | 4.5:1 | ✓ |
| `--info-text` #1b4b94 × `--blue-soft` #eaf3ff | badge/alerta informativo | 7.56:1 | 4.5:1 | ✓ |
| `--neutral-text` #44506b × `--neutral-soft` #eef1f6 | badge neutro | 7.12:1 | 4.5:1 | ✓ |
| `--green-ink` #0f5c34 × `--green-soft` #e5f8ed | alerta de sucesso | 7.30:1 | 4.5:1 | ✓ |
| `--red-ink` #8f1d2c × `--red-soft` #ffe8ea | alerta de erro | 7.56:1 | 4.5:1 | ✓ |
| `--amber-ink` #6d4a00 × `--amber-soft` #fff4d7 | alerta de aviso | 7.29:1 | 4.5:1 | ✓ |
| `--border-strong` #7d89a3 × `--surface` #ffffff | borda de campos (componente de UI) | 3.51:1 | 3:1 | ✓ |
| `--focus-ring` #2a68d4 × `--surface` #ffffff | anel de foco | 5.21:1 | 3:1 | ✓ |
| `--blue` #4b91ea × `--navy` #24376b | acento decorativo (não é texto) | 3.56:1 | 3:1 | ✓ |

## 3. Tipografia, espaçamento e forma

- **Fonte:** pilha do sistema (`system-ui, "Segoe UI", Roboto, Helvetica, Arial, sans-serif`) — rápida e sem pedido externo.
- **Escala:** `--text-xs` 0,75rem · `sm` 0,875 · `base` 1 · `lg` 1,125 · `xl` 1,25 · `2xl` 1,5 · `3xl` 1,875; entrelinha 1,55.
- **Espaçamento:** base de 4 px (`--space-1` = 0,25rem … `--space-12` = 3rem).
- **Raios:** 0,5 / 0,75 / 1 / 1,5rem e `pill`; **sombras:** `--shadow-sm`, `--shadow`, `--shadow-lg` (azuladas e suaves).
- **Controles:** altura mínima `--control-h` = 2,75rem (44 px), o tamanho mínimo recomendado para toque.

## 4. Grade e pontos de quebra

| Largura | Comportamento |
|---|---|
| > 1000 px | barra lateral fixa (16,25rem) + conteúdo até 72rem; grades de 2 a 4 colunas |
| ≤ 1000 px | grades de indicadores passam a 2 colunas |
| ≤ 760 px | a barra lateral vira gaveta (botão ☰ na barra superior, fundo escurecido, fecha com Esc ou clique fora); tudo em 1 coluna |

Também há regras para `prefers-reduced-motion` (sem animações), `forced-colors` (alto contraste do Windows) e impressão.

## 5. Componentes

| Componente | Classes | Observações |
|---|---|---|
| Botões | `.btn` + `--primary`, `--secondary`, `--quiet`, `--danger`, `--sm`, `--lg`, `--block`, `--icon` | `<button>` para ações; `<a class="btn">` só para navegação |
| Campos | parcial `field` (`.field`, `.field__label`, `.field__hint`, `.field__error`, `.input`, `.select`, `.textarea`) | rótulo visível, `aria-describedby`, `aria-invalid`; "(opcional)" automático |
| Alertas/flash | `.alert` + `--success`, `--error`, `--info`, `--warning` | erros com `role="alert"`, demais com `role="status"` |
| Badges de status | `.badge` + `--pending`, `--responded`, `--success`, `--danger`, `--info`, `--neutral`, `--accent` | gerados por `badge($tipo, $codigo)` a partir dos enums |
| Cartões | `.card` (`__header`, `__title`, `__subtitle`, `__footer`), `.card--flat`, `.card--accent` | |
| Indicadores | parcial `stat` (`.stat`, `__label`, `__value`, `__note`; tons `--green`, `--amber`, `--red`, `--purple`) | usados no card "Ambiente" e no dashboard |
| Tabelas | `.table-wrap` + `.table` | rolagem horizontal no celular, cabeçalho legível |
| Estado vazio | parcial `empty_state` (`.empty`) | sempre com texto que explica o próximo passo |
| Paginação | parcial `pagination` | |
| Navegação | parcial `nav` (`.nav`, `.nav__link`) alimentada por `nav_items()` | só mostra áreas permitidas ao perfil **e** que já existem |
| Ícones | parcial `icon` → `<svg><use href="/assets/img/icons.svg#icon-NOME">` | sprite próprio, traço 2 px, `currentColor` |

Exemplos:

```php
<?= partial('field', ['name' => 'email', 'label' => 'E-mail', 'type' => 'email',
                      'autocomplete' => 'username', 'required' => true]) ?>

<button type="submit" class="btn btn--primary">Salvar</button>
<?= badge('justification_status', 'PENDING') ?>   <!-- <span class="badge badge--pending">Pendente</span> -->
<?= partial('icon', ['name' => 'students']) ?>
```

## 6. Estrutura do frontend

```text
views/
  layouts/   app.php (área interna: barra lateral + topo), auth.php (cartão central), public.php (link do responsável)
  partials/  brand, nav, flash, field, icon, avatar, stat, empty_state, pagination
  auth/      login.php, forgot.php
  home/      index.php
  profile/   me.php, matrix.php
  errors/    error.php (403, 404, 405, 429, 500 com a mesma página)
public/assets/
  css/saa.css   tokens → base → layouts → componentes → responsivo → acessibilidade/impressão
  js/app.js     comportamentos por atributo data-*: data-nav-toggle, data-nav-close, data-nav-backdrop,
                data-password-toggle, data-confirm, data-dismiss
  img/          logo.svg, logo-mark.svg, favicon.svg, icons.svg
config/navigation.php   itens do menu (rótulo, caminho, ícone, permissão, ordem, descrição dos atalhos)
```

Regras: templates só exibem dados prontos (sem consultas); todo valor dinâmico passa por `e()`; formulários POST
sempre com `csrf_field()`; nenhum `style=""`, `<script>` sem `src` ou atributo `on*` (o CI verifica).

## 7. Acessibilidade — checklist

- [x] Link "Pular para o conteúdo" e landmarks (`header`, `nav`, `main`, `footer`).
- [x] Rótulos visíveis ligados aos campos (`for`/`id`); erros por campo com `aria-describedby` e `aria-invalid`.
- [x] Foco visível em todos os controles (`:focus-visible`), inclusive sobre o azul-marinho.
- [x] Contraste AA em todos os pares de cores usados (seção 2).
- [x] Menu do celular com `aria-expanded`/`aria-controls`, fechamento por Esc e retorno do foco.
- [x] Botão "mostrar senha" com `aria-pressed`, criado só quando há JavaScript.
- [x] Alvos de toque de 44 px; layout sem rolagem horizontal; respeita `prefers-reduced-motion` e `forced-colors`.

## 8. Protótipos

### 8.1 Tela de login (#21) — `GET /login`

```text
+--------------------------------------------------+
|  [S] SAA                                         |
|      Sistema de Ausência de Alunos               |
|                                                  |
|  Entrar                                          |
|  Bem-vindo de volta! Entre para registrar ...    |
|  [cadeado] Ambiente interno · acesso restrito    |
|                                                  |
|  E-mail                                          |
|  [ nome@escola.com.br                         ]  |
|  Senha                                           |
|  [ ••••••••••                           (olho) ] |
|                              Esqueceu a senha?   |
|  [               Entrar                       ]  |
|  ----------------------------------------------  |
|  É responsável por um aluno? Use o link do e-mail|
+--------------------------------------------------+
```

Estados: **erro de credenciais** (mensagem genérica "E-mail ou senha inválidos.", sem dizer qual campo errou, HTTP 401);
**campos vazios** (erro por campo, HTTP 422); **muitas tentativas** (mensagem com o tempo de espera, HTTP 429);
**sessão expirada** (aviso ao voltar ao login). Após entrar, o usuário vai para `/home` ou para o endereço interno que
tentou abrir (`next`).

### 8.2 Tela inicial (#22) — `GET /home`

```text
+-----------+----------------------------------------------------------+
| [S] SAA   |  VISÃO GERAL                                             |
|           |  Início                                                  |
| (A) Ana   |  +----------------------------------------------------+  |
|   Admin.  |  | AMBIENTE INTERNO                                   |  |
|           |  | Boa noite, Ana.                                    |  |
| > Início  |  | Seu perfil: Administrador · E-mail · Áreas: N      |  |
|   ...     |  +----------------------------------------------------+  |
|           |  Acesso rápido                                           |
|           |  [ atalho ] [ atalho ] [ atalho ]   (só o que o perfil   |
|           |                                      pode usar)          |
|  [ Sair ] |  Ambiente (só administradores): banco, coleções,         |
|           |  versão do MongoDB e do driver PHP — "Conectado"         |
+-----------+----------------------------------------------------------+
```

Estados: **sem atalhos** (estado vazio explicando que as áreas aparecem conforme são liberadas); **MongoDB indisponível**
(o card Ambiente mostra "Sem conexão" em vez de quebrar a página).

## 9. Como visualizar

```bash
composer serve            # http://127.0.0.1:8000/login
```

Crie um usuário com `php bin/create_user.php` (veja o [README](../README.md)). Para conferir a responsividade, use o
modo de dispositivo do navegador (F12) em 360, 768 e 1280 px.
