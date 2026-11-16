/* Ativa o Web Push no aparelho do responsável (painel data-push-panel). */
(function () {
  'use strict';

  function keyBytes(base64Url) {
    const base64 = (base64Url + '='.repeat((4 - (base64Url.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    return Uint8Array.from(atob(base64), (char) => char.charCodeAt(0));
  }

  function sameKey(buffer, expected) {
    if (!buffer) return false;
    const current = new Uint8Array(buffer);
    return current.length === expected.length && current.every((byte, index) => byte === expected[index]);
  }

  function setUp(panel) {
    const button = panel.querySelector('[data-push-subscribe]');
    const status = panel.querySelector('[data-push-status]');
    const say = (message, kind) => {
      status.textContent = message;
      status.dataset.kind = kind || '';
    };
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
      button.disabled = true;
      say('Este navegador não aceita notificações. Abra o link no Google Chrome do Android.', 'error');
      return;
    }
    if (!window.isSecureContext) {
      button.disabled = true;
      say('As notificações exigem endereço seguro (HTTPS).', 'error');
      return;
    }
    if (Notification.permission === 'denied') {
      say('As notificações estão bloqueadas para este site. Libere em Configurações do Chrome › Notificações.', 'error');
    }

    button.addEventListener('click', async () => {
      button.disabled = true;
      say('Ativando…');
      try {
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
          throw new Error('Permissão negada. Toque em “Permitir” quando o Android perguntar.');
        }
        await navigator.serviceWorker.register('/sw.js', { scope: '/' });
        const registration = await navigator.serviceWorker.ready;
        const key = keyBytes(panel.dataset.vapidKey);
        let subscription = await registration.pushManager.getSubscription();
        if (subscription && !sameKey(subscription.options.applicationServerKey, key)) {
          await subscription.unsubscribe();
          subscription = null;
        }
        if (!subscription) {
          subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
        }
        const response = await fetch(panel.dataset.endpoint, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
          body: new URLSearchParams({ csrf_token: panel.dataset.csrf, subscription: JSON.stringify(subscription) }),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
          throw new Error(data.error || 'Não foi possível concluir a ativação. Recarregue a página e tente novamente.');
        }
        say(data.message, 'success');
        button.hidden = true;
      } catch (error) {
        say(error instanceof Error && error.message ? error.message : 'Falha ao ativar as notificações.', 'error');
        button.disabled = false;
      }
    });
  }

  document.querySelectorAll('[data-push-panel]').forEach(setUp);
})();
