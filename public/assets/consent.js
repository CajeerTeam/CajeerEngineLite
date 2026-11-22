/* CajeerEngine Lite 1.0.3: necessary-by-default and explicit opt-in for optional categories.
 * No trackers or external scripts are loaded by this module. */
(() => {
  'use strict';
  const root = document.getElementById('cookie-consent');
  if (!root) return;

  const name = 'cajeer_lite_consent';
  const revision = Number(root.dataset.revision);
  const days = Number(root.dataset.days);
  if (!Number.isSafeInteger(revision) || revision < 1 ||
      !Number.isSafeInteger(days) || days < 1 || days > 365) return;

  const bits = Object.freeze({ analytics: 1, marketing: 2, recordings: 4 });
  const summary = root.querySelector('[data-consent-summary]');
  const editor = root.querySelector('[data-consent-editor]');
  const error = root.querySelector('[data-consent-error]');
  const checks = Object.fromEntries(Object.keys(bits).map(key => [
    key, root.querySelector(`[data-consent-category="${key}"]`)
  ]));
  let lastChoice = null;
  let previousFocus = null;
  let channel = null;

  function cookieValue() {
    const pair = document.cookie.split(';').map(v => v.trim()).find(v => v.startsWith(`${name}=`));
    if (!pair) return null;
    try { return decodeURIComponent(pair.slice(name.length + 1)); }
    catch { return null; }
  }

  function read() {
    const value = cookieValue();
    if (typeof value !== 'string' || value.length > 80) return null;
    const match = /^v1\.([1-9][0-9]{0,8})\.([1-9][0-9]{9,10})\.([0-7])$/.exec(value);
    if (!match || Number(match[1]) !== revision) return null;
    const now = Math.floor(Date.now() / 1000);
    const time = Number(match[2]);
    if (time > now + 60 || time + days * 86400 <= now) return null;
    return Number(match[3]);
  }

  function preferences(mask) {
    return Object.freeze({
      necessary: true,
      analytics: mask !== null && !!(mask & bits.analytics),
      marketing: mask !== null && !!(mask & bits.marketing),
      recordings: mask !== null && !!(mask & bits.recordings),
      saved: mask !== null,
      revision
    });
  }

  function announce(mask) {
    document.dispatchEvent(new CustomEvent('cajeer:consentchange', { detail: preferences(mask) }));
  }

  function showEditor() {
    previousFocus = document.activeElement;
    const mask = read() ?? 0;
    for (const [key, bit] of Object.entries(bits)) checks[key].checked = !!(mask & bit);
    error.hidden = true;
    root.hidden = false;
    summary.hidden = true;
    editor.hidden = false;
    checks.analytics.focus();
  }

  function closeEditor() {
    editor.hidden = true;
    summary.hidden = false;
    error.hidden = true;
    root.hidden = read() !== null;
    if (previousFocus && typeof previousFocus.focus === 'function') previousFocus.focus();
  }

  function save(mask) {
    if (!Number.isInteger(mask) || mask < 0 || mask > 7) return false;
    const value = `v1.${revision}.${Math.floor(Date.now() / 1000)}.${mask}`;
    const secure = location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${name}=${value}; Path=/; Max-Age=${days * 86400}; SameSite=Lax${secure}`;
    if (cookieValue() !== value || read() !== mask) {
      error.textContent = 'Не удалось сохранить выбор. Разрешите cookie для этого сайта и повторите попытку.';
      error.hidden = false;
      root.hidden = false;
      return false;
    }
    root.hidden = true;
    editor.hidden = true;
    summary.hidden = false;
    error.hidden = true;
    lastChoice = value;
    announce(mask);
    if (channel) channel.postMessage({ changed: true });
    if (previousFocus && typeof previousFocus.focus === 'function') previousFocus.focus();
    return true;
  }

  function sync() {
    const value = cookieValue();
    const mask = read();
    if (value !== lastChoice) {
      lastChoice = value;
      announce(mask);
      if (mask === null) root.hidden = false;
    }
  }

  for (const button of document.querySelectorAll('[data-consent-open]')) {
    button.addEventListener('click', showEditor);
  }
  root.addEventListener('click', event => {
    const button = event.target.closest('[data-consent-action]');
    if (!button || !root.contains(button)) return;
    switch (button.dataset.consentAction) {
      case 'accept': save(7); break;
      case 'reject': save(0); break;
      case 'settings': showEditor(); break;
      case 'cancel': closeEditor(); break;
      case 'save': {
        let mask = 0;
        for (const [key, bit] of Object.entries(bits)) if (checks[key].checked) mask |= bit;
        save(mask);
        break;
      }
    }
  });
  root.addEventListener('keydown', event => {
    if (event.key === 'Escape' && !editor.hidden) { event.preventDefault(); closeEditor(); }
  });

  Object.defineProperty(window, 'CajeerConsent', {
    value: Object.freeze({
      get: () => preferences(read()),
      has: key => key === 'necessary' || (Object.hasOwn(bits, key) && !!(read() & bits[key])),
      open: showEditor,
      revoke: () => save(0)
    }),
    configurable: false, writable: false
  });

  lastChoice = cookieValue();
  root.hidden = read() !== null;
  if (typeof BroadcastChannel === 'function') {
    channel = new BroadcastChannel('cajeer_lite_consent');
    channel.addEventListener('message', sync);
  }
  window.addEventListener('focus', sync);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) sync(); });
})();
