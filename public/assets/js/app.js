/*!
 * SAA — comportamentos mínimos da interface (issue #16).
 * Sem dependências e sem código inline (a CSP só permite script-src 'self').
 *
 * Progressive enhancement: tudo aqui é opcional. Sem JavaScript o menu fica visível
 * (no celular, no topo da página), a senha continua oculta, os formulários enviam
 * normalmente e as mensagens podem ser lidas sem fechá-las.
 *
 *   [data-nav-toggle]        botão que abre/fecha o menu lateral no celular
 *   [data-nav-close]         botão de fechar dentro do menu
 *   [data-nav-backdrop]      área escurecida atrás do menu (clique fecha)
 *   [data-password-toggle]   botão mostrar/ocultar senha (aria-controls = id do campo)
 *   [data-confirm="texto"]   pede confirmação antes de enviar o formulário ou executar o botão
 *   [data-dismiss]           botão que fecha uma mensagem (.alert)
 *
 * data-confirm é só conveniência: o servidor nunca deve depender dele para proteger uma ação.
 */
(function () {
  'use strict';

  var root = document.documentElement;

  // Marca imediatamente que há JavaScript: o CSS usa .js para ativar a gaveta do menu no celular.
  root.classList.add('js');

  /* ------------------------------------------------------------------ Menu lateral (celular) */
  function initNav() {
    var toggle = document.querySelector('[data-nav-toggle]');
    if (!toggle) {
      return;
    }
    var sidebar = document.getElementById(toggle.getAttribute('aria-controls') || 'sidebar');
    if (!sidebar) {
      return;
    }

    var closeButton = document.querySelector('[data-nav-close]');
    var backdrop = document.querySelector('[data-nav-backdrop]');
    var main = document.getElementById('app-main');
    var mobile = window.matchMedia('(max-width: 760px)');

    function isOpen() {
      return sidebar.classList.contains('is-open');
    }

    function setOpen(open, restoreFocus) {
      if (open && !mobile.matches) {
        return;
      }
      sidebar.classList.toggle('is-open', open);
      root.classList.toggle('nav-open', open);
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (main) {
        // Enquanto a gaveta está aberta, o conteúdo atrás dela não recebe foco nem leitor de tela.
        main.inert = open;
      }
      if (open) {
        var first = closeButton || sidebar.querySelector('a[href], button');
        if (first) {
          first.focus();
        }
      } else if (restoreFocus) {
        toggle.focus();
      }
    }

    toggle.addEventListener('click', function () {
      setOpen(!isOpen(), true);
    });

    if (closeButton) {
      closeButton.addEventListener('click', function () {
        setOpen(false, true);
      });
    }

    if (backdrop) {
      backdrop.addEventListener('click', function () {
        setOpen(false, true);
      });
    }

    // Clicar em um link do menu fecha a gaveta (a navegação segue normalmente).
    sidebar.addEventListener('click', function (event) {
      if (event.target.closest && event.target.closest('a[href]')) {
        setOpen(false, false);
      }
    });

    document.addEventListener('keydown', function (event) {
      if ((event.key === 'Escape' || event.key === 'Esc') && isOpen()) {
        setOpen(false, true);
      }
    });

    // Ao ampliar a janela, volta ao menu fixo e limpa o estado da gaveta.
    var onChange = function () {
      if (!mobile.matches && isOpen()) {
        setOpen(false, false);
      }
    };
    if (mobile.addEventListener) {
      mobile.addEventListener('change', onChange);
    } else if (mobile.addListener) {
      mobile.addListener(onChange);
    }
  }

  /* ------------------------------------------------------------------ Mostrar/ocultar senha */
  function initPasswordToggles() {
    var buttons = document.querySelectorAll('[data-password-toggle]');
    Array.prototype.forEach.call(buttons, function (button) {
      var input = document.getElementById(button.getAttribute('aria-controls') || '');
      if (!input) {
        return;
      }
      button.hidden = false; // o botão só aparece quando o JavaScript está ativo
      button.addEventListener('click', function () {
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.setAttribute('aria-pressed', show ? 'true' : 'false');
      });
    });
  }

  /* ------------------------------------------------------------------ Fechar mensagens */
  function initDismiss() {
    var buttons = document.querySelectorAll('[data-dismiss]');
    Array.prototype.forEach.call(buttons, function (button) {
      var alert = button.closest('.alert');
      if (!alert) {
        return;
      }
      button.hidden = false;
      button.addEventListener('click', function () {
        alert.parentNode.removeChild(alert);
      });
    });
  }

  /* ------------------------------------------------------------------ Confirmação (data-confirm) */
  function initConfirm() {
    // Botões e links: confirma no clique; se o usuário cancelar, nada acontece.
    document.addEventListener('click', function (event) {
      var target = event.target.closest ? event.target.closest('[data-confirm]') : null;
      if (!target || target.tagName === 'FORM') {
        return;
      }
      if (!window.confirm(target.getAttribute('data-confirm'))) {
        event.preventDefault();
        event.stopPropagation();
      }
    });

    // Formulários com data-confirm (quando o botão que enviou não tem a própria confirmação).
    document.addEventListener('submit', function (event) {
      var form = event.target;
      if (!form || form.tagName !== 'FORM' || !form.hasAttribute('data-confirm')) {
        return;
      }
      var submitter = event.submitter;
      if (submitter && submitter.hasAttribute('data-confirm')) {
        return; // já confirmado no clique do botão
      }
      if (!window.confirm(form.getAttribute('data-confirm'))) {
        event.preventDefault();
      }
    });
  }

  function init() {
    initNav();
    initPasswordToggles();
    initDismiss();
    initConfirm();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
