/* Tela de chamada (/chamada): carregamento automático da turma e marcação das faltas. */
(function () {
  'use strict';

  const turmaForm = document.querySelector('[data-chamada-turma]');
  const chamadaForm = document.querySelector('[data-chamada-form]');
  if (!turmaForm && !chamadaForm) return;

  const caixas = chamadaForm ? Array.from(chamadaForm.querySelectorAll('[data-chamada-caixa]')) : [];
  const marcadas = () => caixas.filter((caixa) => caixa.checked);
  const plural = (total, um, varios) => total + ' ' + (total === 1 ? um : varios);
  const semAcento = (texto) => texto.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase();
  const temDialogo = (dialogo) => dialogo !== null && typeof dialogo.showModal === 'function';
  let enviando = false;
  let saidaProtegida = false;

  function avisarSaida(event) {
    event.preventDefault();
    event.returnValue = '';
  }

  // Faltas marcadas e ainda não enviadas se perdem ao sair da página: o navegador pede confirmação.
  function protegerSaida(ligar) {
    if (ligar === saidaProtegida) return;
    saidaProtegida = ligar;
    if (ligar) window.addEventListener('beforeunload', avisarSaida);
    else window.removeEventListener('beforeunload', avisarSaida);
  }

  function abrir(dialogo, foco) {
    if (!dialogo.open) dialogo.showModal();
    if (foco) foco.focus();
  }

  function dataBrasileira(valor) {
    const partes = /^(\d{4})-(\d{2})-(\d{2})$/.exec(valor);
    return partes ? partes[3] + '/' + partes[2] + '/' + partes[1] : valor;
  }

  function hoje() {
    const agora = new Date();
    const doisDigitos = (numero) => String(numero).padStart(2, '0');
    return agora.getFullYear() + '-' + doisDigitos(agora.getMonth() + 1) + '-' + doisDigitos(agora.getDate());
  }

  function configurarTurma(form) {
    const seletor = form.elements.namedItem('turma_id');
    if (!(seletor instanceof HTMLSelectElement)) return;
    const dica = document.querySelector('[data-chamada-dica]');
    const troca = document.querySelector('[data-chamada-troca]');
    const padrao = Array.from(seletor.options).find((opcao) => opcao.defaultSelected);
    const turmaCarregada = padrao ? padrao.value : '';
    let trocaConfirmada = false;
    let ultimaTeclaDeNavegacao = -Infinity;

    const mostrarDica = (texto) => {
      if (dica && texto) dica.textContent = texto;
    };
    // Ao voltar pelo histórico, o navegador pode restaurar no seletor outra turma que não é a da lista exibida.
    const restaurar = () => {
      trocaConfirmada = false;
      seletor.value = turmaCarregada;
      mostrarDica(dica && dica.dataset.textoJs);
    };
    const carregar = () => {
      if (seletor.value === '' || seletor.value === turmaCarregada) return;
      if (typeof form.requestSubmit === 'function') form.requestSubmit();
      else form.submit();
    };

    form.classList.add('chamada-turma-automatica');
    restaurar();

    form.addEventListener('submit', (event) => {
      if (!trocaConfirmada && !enviando && marcadas().length > 0) {
        // Sem <dialog>, o aviso do próprio navegador ao sair da página protege as faltas marcadas.
        if (!temDialogo(troca)) return;
        event.preventDefault();
        if (seletor.value !== turmaCarregada) abrir(troca, troca.querySelector('[data-chamada-troca-voltar]'));
        return;
      }
      protegerSaida(false);
      mostrarDica('Carregando a lista de alunos…');
    });

    seletor.addEventListener('keydown', (event) => {
      const tecla = event.key || ''; // o preenchimento automático do Chrome dispara keydown sem key
      if (tecla === 'Enter') setTimeout(carregar, 0);
      else if (tecla.length === 1 || /^(Arrow|Page)|^(Home|End)$/.test(tecla)) ultimaTeclaDeNavegacao = performance.now();
    });
    // Com o seletor fechado, as setas trocam a turma a cada toque; aí a lista só carrega com Enter ou pelo botão.
    seletor.addEventListener('change', () => {
      if (performance.now() - ultimaTeclaDeNavegacao > 300) carregar();
    });

    if (temDialogo(troca)) {
      troca.querySelector('[data-chamada-troca-voltar]').addEventListener('click', () => troca.close());
      troca.querySelector('[data-chamada-troca-confirmar]').addEventListener('click', () => {
        trocaConfirmada = true;
        protegerSaida(false); // sem requestSubmit, form.submit() não passa pelo evento submit
        troca.close();
        carregar();
      });
      troca.addEventListener('close', () => {
        if (trocaConfirmada) return;
        seletor.value = turmaCarregada;
        seletor.focus();
      });
    }

    window.addEventListener('pageshow', (event) => {
      if (event.persisted) restaurar();
    });
  }

  function configurarChamada(form) {
    const ferramentas = form.querySelector('[data-chamada-ferramentas]');
    const contador = form.querySelector('[data-chamada-contador]');
    const desmarcar = form.querySelector('[data-chamada-desmarcar]');
    const desfazer = form.querySelector('[data-chamada-desfazer]');
    const busca = form.querySelector('[data-chamada-busca]');
    const resultado = form.querySelector('[data-chamada-resultado]');
    const enviar = form.querySelector('[data-chamada-enviar]');
    const data = form.querySelector('[name="absence_date"]');
    const confirmacao = document.querySelector('[data-chamada-confirmacao]');
    const confirmar = confirmacao ? confirmacao.querySelector('[data-chamada-confirmacao-enviar]') : null;
    if (!ferramentas || !contador || !desmarcar || !desfazer || !enviar || !data) return;
    let desmarcadas = [];
    let focoAnterior = null;

    function atualizar() {
      let total = 0;
      caixas.forEach((caixa) => {
        caixa.closest('.chamada-aluno').classList.toggle('chamada-aluno-ausente', caixa.checked);
        if (caixa.checked) total += 1;
      });
      contador.textContent = total === 0 ? 'Nenhuma falta marcada' : plural(total, 'falta marcada', 'faltas marcadas');
      contador.classList.toggle('chamada-contador-com-faltas', total > 0);
      desmarcar.setAttribute('aria-disabled', String(total === 0));
      if (!enviando) enviar.textContent = total === 0 ? enviar.dataset.textoPresentes : enviar.dataset.textoFaltas;
      protegerSaida(total > 0 && !enviando);
    }

    function esquecerDesmarcadas() {
      desmarcadas = [];
      desfazer.hidden = true;
    }

    form.addEventListener('change', (event) => {
      if (!event.target.matches('[data-chamada-caixa]')) return;
      esquecerDesmarcadas();
      atualizar();
    });

    desmarcar.addEventListener('click', () => {
      const atuais = marcadas();
      if (atuais.length === 0) return;
      atuais.forEach((caixa) => {
        caixa.checked = false;
      });
      desmarcadas = atuais;
      desfazer.hidden = false;
      atualizar();
    });

    desfazer.addEventListener('click', () => {
      desmarcadas.forEach((caixa) => {
        caixa.checked = true;
      });
      esquecerDesmarcadas();
      atualizar();
      desmarcar.focus();
    });

    if (busca && resultado) {
      const itens = caixas.map((caixa) => {
        const item = caixa.closest('[data-chamada-item]');
        const dados = item.querySelector('[data-chamada-dados]');
        return { item, caixa, texto: semAcento(dados ? dados.textContent : caixa.dataset.nome || '').replace(/\s+/g, ' ') };
      });
      busca.addEventListener('input', () => {
        const partes = semAcento(busca.value).split(/\s+/).filter(Boolean);
        let encontrados = 0;
        itens.forEach(({ item, texto }) => {
          item.hidden = !partes.every((parte) => texto.includes(parte));
          if (!item.hidden) encontrados += 1;
        });
        if (partes.length === 0) resultado.textContent = '';
        else resultado.textContent = encontrados === 0 ? 'Nenhum aluno encontrado.' : plural(encontrados, 'aluno encontrado.', 'alunos encontrados.');
      });
      // Enter na busca não envia a chamada: leva ao primeiro aluno encontrado.
      busca.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        const primeiro = itens.find(({ item }) => !item.hidden);
        if (primeiro) primeiro.caixa.focus();
      });
    }

    function prepararConfirmacao() {
      const faltas = marcadas();
      confirmacao.querySelector('[data-chamada-confirmacao-lista]').replaceChildren(...faltas.map((caixa) => {
        const item = document.createElement('li');
        item.textContent = caixa.dataset.nome || '';
        return item;
      }));
      confirmacao.querySelector('[data-chamada-confirmacao-total]').textContent =
        plural(faltas.length, 'aluno marcado como ausente', 'alunos marcados como ausentes');
      confirmacao.querySelector('[data-chamada-confirmacao-faltas]').hidden = faltas.length === 0;
      confirmacao.querySelector('[data-chamada-confirmacao-presentes]').hidden = faltas.length > 0;
      confirmacao.querySelector('[data-chamada-confirmacao-data]').textContent = dataBrasileira(data.value);
      confirmacao.querySelector('[data-chamada-confirmacao-outro-dia]').hidden = data.value === hoje();
      confirmar.textContent = faltas.length > 0 ? confirmar.dataset.textoFaltas : confirmar.dataset.textoPresentes;
    }

    function iniciarEnvio() {
      enviando = true;
      protegerSaida(false);
      form.setAttribute('aria-busy', 'true');
      enviar.textContent = 'Registrando a chamada…';
      // Desabilita só depois do evento submit, para não interferir no envio em andamento.
      setTimeout(() => {
        enviar.disabled = true;
      }, 0);
    }

    form.addEventListener('submit', (event) => {
      if (enviando) {
        event.preventDefault();
        return;
      }
      if (!temDialogo(confirmacao) || !confirmar) {
        iniciarEnvio();
        return;
      }
      event.preventDefault();
      prepararConfirmacao();
      focoAnterior = document.activeElement;
      abrir(confirmacao, confirmacao.querySelector('[data-chamada-confirmacao-voltar]'));
    });

    if (temDialogo(confirmacao) && confirmar) {
      confirmacao.querySelector('[data-chamada-confirmacao-voltar]').addEventListener('click', () => confirmacao.close());
      confirmar.addEventListener('click', () => {
        if (enviando) return;
        iniciarEnvio();
        confirmar.disabled = true;
        confirmacao.close();
        form.submit();
      });
      confirmacao.addEventListener('close', () => {
        if (!enviando && focoAnterior instanceof HTMLElement) focoAnterior.focus();
      });
    }

    // Página restaurada pelo botão Voltar depois do envio: libera o botão de novo.
    window.addEventListener('pageshow', (event) => {
      if (!event.persisted) return;
      enviando = false;
      enviar.disabled = false;
      if (confirmar) confirmar.disabled = false;
      form.removeAttribute('aria-busy');
      atualizar();
    });

    ferramentas.hidden = false;
    atualizar();
  }

  if (turmaForm) configurarTurma(turmaForm);
  if (chamadaForm && caixas.length > 0) configurarChamada(chamadaForm);
})();
