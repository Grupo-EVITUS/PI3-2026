/* Painel de frequência (/frequencia): abre o gráfico de pizza da turma clicada. */
'use strict';

(function () {
  const cards = document.querySelectorAll('a[data-pie-dialog]');
  // Sem <dialog> nativo o cartão continua sendo um link para a página filtrada pela turma.
  if (cards.length === 0 || typeof HTMLDialogElement !== 'function'
      || typeof HTMLDialogElement.prototype.showModal !== 'function') {
    return;
  }

  function setUp(card) {
    const dialog = document.getElementById(card.dataset.pieDialog);
    if (!(dialog instanceof HTMLDialogElement)) {
      return;
    }
    const closeButton = dialog.querySelector('[data-pie-close]');
    const title = document.getElementById(dialog.getAttribute('aria-labelledby') || '');

    // Com JS o cartão passa a ser um botão que abre o gráfico; o href fica para abrir em nova aba.
    card.setAttribute('role', 'button');
    card.setAttribute('aria-haspopup', 'dialog');
    card.setAttribute('aria-controls', dialog.id);

    const open = () => {
      if (dialog.open) {
        return;
      }
      dialog.showModal();
      // Foco no título (tabindex="-1"): em tela baixa o diálogo rola e o topo continua à vista,
      // e um Enter por engano não sai da página.
      if (title) {
        title.focus();
      }
    };

    card.addEventListener('click', (event) => {
      if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
        return;
      }
      event.preventDefault();
      open();
    });
    // Botões respondem ao Espaço (links não); sem o preventDefault a página rolaria.
    card.addEventListener('keydown', (event) => {
      if (event.key === ' ') {
        event.preventDefault();
      }
    });
    card.addEventListener('keyup', (event) => {
      if (event.key === ' ') {
        event.preventDefault();
        open();
      }
    });

    if (closeButton) {
      closeButton.addEventListener('click', () => dialog.close());
    }

    // Clique no fundo escurecido fecha; arrastar a seleção de texto para fora não fecha.
    const outside = (event) => {
      const box = dialog.getBoundingClientRect();
      return event.clientX < box.left || event.clientX > box.right
        || event.clientY < box.top || event.clientY > box.bottom;
    };
    let pressedOutside = false;
    dialog.addEventListener('pointerdown', (event) => {
      pressedOutside = event.target === dialog && outside(event);
    });
    dialog.addEventListener('click', (event) => {
      if (pressedOutside && event.target === dialog && outside(event)) {
        dialog.close();
      }
      pressedOutside = false;
    });

    // Esc, "Fechar" ou clique no fundo: o foco volta ao cartão de origem.
    dialog.addEventListener('close', () => card.focus());
  }

  cards.forEach(setUp);
  document.querySelectorAll('[data-pie-hint]').forEach((hint) => {
    hint.hidden = false;
  });
})();
