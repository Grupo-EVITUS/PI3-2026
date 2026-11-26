/* Melhorias de uso do SAA. Nada aqui é obrigatório: sem JavaScript, as páginas continuam funcionando. */
(function () {
  'use strict';
  document.documentElement.classList.add('js');

  var all = function (selector, root) { return Array.prototype.slice.call((root || document).querySelectorAll(selector)); };
  var plain = function (text) {
    return String(text).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim();
  };

  /* Abas: trocam o painel sem recarregar e mantêm ?aba= no endereço (o link funciona sem JavaScript). */
  all('[data-tabs]').forEach(function (bar) {
    var tabs = all('[data-tab]', bar);
    var panels = all('[data-panel]');
    var show = function (name) {
      tabs.forEach(function (tab) {
        tab.setAttribute('aria-selected', tab.getAttribute('data-tab') === name ? 'true' : 'false');
      });
      panels.forEach(function (panel) { panel.hidden = panel.getAttribute('data-panel') !== name; });
    };
    tabs.forEach(function (tab) {
      tab.addEventListener('click', function (event) {
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) { return; }
        event.preventDefault();
        var name = tab.getAttribute('data-tab');
        show(name);
        if (window.history && history.replaceState) {
          history.replaceState(null, '', '?aba=' + encodeURIComponent(name));
        }
      });
    });
  });

  /* Busca instantânea: <input data-filter="#lista"> esconde os itens [data-search] que não combinam. */
  all('[data-filter]').forEach(function (input) {
    var list = document.querySelector(input.getAttribute('data-filter'));
    if (!list) { return; }
    var items = all('[data-search]', list);
    var empty = list.parentNode.querySelector('[data-empty-search]');
    var counter = document.querySelector('[data-count-for="' + list.id + '"]');
    var apply = function () {
      var term = plain(input.value);
      var visible = 0;
      items.forEach(function (item) {
        var match = term === '' || plain(item.getAttribute('data-search')).indexOf(term) !== -1;
        item.hidden = !match;
        if (match) { visible += 1; }
      });
      if (empty) { empty.hidden = visible !== 0 || items.length === 0; }
      if (counter) {
        counter.textContent = term === '' ? String(items.length) : visible + ' de ' + items.length;
      }
    };
    input.addEventListener('input', apply);
    input.addEventListener('keydown', function (event) {
      if (event.key === 'Enter') { event.preventDefault(); }
    });
  });

  /* Chamada: contador ao vivo de ausentes e destaque de quem foi marcado. */
  all('[data-attendance]').forEach(function (form) {
    var boxes = all('input[type="checkbox"][name^="attendance["]', form);
    var absent = all('[data-absent-count]', form);
    var present = all('[data-present-count]', form);
    var update = function () {
      var marked = boxes.filter(function (box) { return box.checked; }).length;
      absent.forEach(function (node) { node.textContent = String(marked); });
      present.forEach(function (node) { node.textContent = String(boxes.length - marked); });
    };
    form.addEventListener('change', update);
    var clear = form.querySelector('[data-clear-absent]');
    if (clear) {
      clear.addEventListener('click', function () {
        boxes.forEach(function (box) { box.checked = false; });
        update();
      });
    }
    update();
  });

  /* Evita envio duplicado: depois de enviar, o botão fica bloqueado até a página responder. */
  var unlock = function () {
    all('form button[data-locked]').forEach(function (button) {
      button.disabled = false;
      button.removeAttribute('data-locked');
      if (button.getAttribute('data-label')) { button.textContent = button.getAttribute('data-label'); }
    });
  };
  all('form').forEach(function (form) {
    if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') { return; }
    form.addEventListener('submit', function () {
      all('button[type="submit"], button:not([type])', form).forEach(function (button) {
        if (button.hasAttribute('data-no-lock')) { return; }
        window.setTimeout(function () {
          button.setAttribute('data-locked', '1');
          button.setAttribute('data-label', button.textContent);
          button.disabled = true;
          button.textContent = 'Enviando…';
        }, 0);
      });
    });
  });
  window.addEventListener('pageshow', unlock); /* voltar pelo navegador não deixa botão travado */

  /* Avisos: botão de fechar; os de sucesso somem sozinhos. */
  all('[data-flash]').forEach(function (notice) {
    var close = notice.querySelector('[data-dismiss]');
    if (close) { close.addEventListener('click', function () { notice.hidden = true; }); }
    if (notice.classList.contains('notice-success')) {
      window.setTimeout(function () { notice.hidden = true; }, 8000);
    }
  });
})();
