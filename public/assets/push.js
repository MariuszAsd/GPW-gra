/* Włączanie/wyłączanie powiadomień push (Web Push). Element [data-push] dostaje status i przyciski. */
(function () {
  const box = document.querySelector('[data-push]');
  if (!box) return;
  const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
  const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  const b64ToU8 = b => { const p = '='.repeat((4 - b.length % 4) % 4); const s = (b + p).replace(/-/g, '+').replace(/_/g, '/'); const r = atob(s); return Uint8Array.from([...r].map(c => c.charCodeAt(0))); };
  const api = (data) => fetch('api_push.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) }).then(r => r.json());
  const html = s => { box.innerHTML = s; };
  async function render() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
      html('<b>🔔 Powiadomienia push</b><p class="muted" style="margin:6px 0 0">Ta przeglądarka nie obsługuje powiadomień push.' + (isIOS && !standalone ? ' Na iPhonie: dodaj Maklerię do ekranu początkowego (Udostępnij → „Do ekranu początkowego”), otwórz ją stamtąd i wróć tutaj.' : '') + '</p>');
      return;
    }
    let st; try { st = await (await fetch('api_push.php')).json(); } catch (e) { st = { ok: false }; }
    if (!st.ok || !st.available || !st.key) { html('<b>🔔 Powiadomienia push</b><p class="muted" style="margin:6px 0 0">Push jest niedostępny na tym serwerze (brak wymaganych funkcji PHP). Powiadomienia w grze działają normalnie.</p>'); return; }
    const reg = await navigator.serviceWorker.ready;
    const sub = await reg.pushManager.getSubscription();
    const on = !!sub && Notification.permission === 'granted';
    html('<b>🔔 Powiadomienia push</b> <span class="tag" style="' + (on ? 'color:var(--up);border-color:var(--up)' : 'color:var(--faint)') + '">' + (on ? 'włączone w tej przeglądarce' : 'wyłączone') + '</span>'
      + '<p class="muted" style="margin:6px 0 10px">Wyzwolone stopy, dywidendy, wyniki lig, wyzwania i tygodniowe podsumowanie — także gdy gra jest zamknięta. Każda przeglądarka/telefon włącza je osobno.'
      + (isIOS && !standalone ? ' Na iPhonie najpierw dodaj Maklerię do ekranu początkowego i otwórz ją stamtąd.' : '') + '</p>'
      + '<div class="row">' + (on ? '<button class="btn sm ghost" data-off>Wyłącz</button><button class="btn sm" data-test>Wyślij test</button>' : '<button class="btn sm" data-on>Włącz powiadomienia</button>') + ' <span class="muted" data-msg style="font-size:12px"></span></div>');
    const msg = t => { const m = box.querySelector('[data-msg]'); if (m) m.textContent = t; };
    box.querySelector('[data-on]')?.addEventListener('click', async () => {
      try {
        const perm = await Notification.requestPermission();
        if (perm !== 'granted') { msg('Przeglądarka nie dała zgody na powiadomienia.'); return; }
        const s = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToU8(st.key) });
        const j = await api({ action: 'subscribe', subscription: s.toJSON() });
        if (!j.ok) { msg(j.err || 'Błąd zapisu subskrypcji.'); return; }
        await api({ action: 'test' });
        render();
      } catch (e) { msg('Nie udało się włączyć: ' + (e && e.message ? e.message : e)); }
    });
    box.querySelector('[data-off]')?.addEventListener('click', async () => {
      try { const ep = sub ? sub.endpoint : null; if (sub) await sub.unsubscribe(); await api({ action: 'unsubscribe', endpoint: ep }); render(); } catch (e) { msg('Błąd: ' + e); }
    });
    box.querySelector('[data-test]')?.addEventListener('click', async () => { const j = await api({ action: 'test' }); msg(j.ok ? 'Wysłano test — powiadomienie powinno się pojawić za chwilę.' : (j.err || 'Nie udało się.')); });
  }
  render();
})();
