/* CajeerEngine Lite 1.0.5: Yandex Metrica, goals and consent-gated Webvisor.
 * Basic consent: no Yandex resource, image beacon or queue before opt-in.
 * A running third-party session recorder cannot reliably be stopped by removing
 * its script, so losing recording permission reloads the page immediately. */
(() => {
  'use strict';
  const script = document.currentScript;
  if (!script || !window.CajeerConsent) return;
  const rawId = script.dataset.metricaId || '';
  if (!/^[1-9][0-9]{0,14}$/.test(rawId)) return;
  const id = Number(rawId);
  if (!Number.isSafeInteger(id) || id < 1) return;
  const pageViews = script.dataset.metricaPageviews === '1';
  const bounce = script.dataset.metricaBounce === '1';
  const webvisor = script.dataset.metricaWebvisor === '1';
  const clickmap = script.dataset.metricaClickmap === '1';
  const contacts = script.dataset.metricaContacts === '1';
  const emailGoal = script.dataset.metricaEmailGoal || '';
  const phoneGoal = script.dataset.metricaPhoneGoal || '';
  const validGoal = value => typeof value === 'string' && /^[A-Za-z][A-Za-z0-9_-]{0,79}$/.test(value);
  if ((emailGoal && !validGoal(emailGoal)) || (phoneGoal && !validGoal(phoneGoal))) return;

  let initialized = false;
  let allowed = false;
  let recording = false;
  let remoteLoaded = false;
  let remoteFailed = false;
  let reloading = false;
  let remote = null;
  let viewSent = false;

  function preference() {
    const choice = window.CajeerConsent.get();
    return choice && choice.saved && choice.analytics ? choice : null;
  }

  function cleanCookies() {
    // Only cookies visible to this origin can be deleted; data on Yandex's own
    // domains, server-held data and any HTTP-only cookies cannot be revoked here.
    const names = document.cookie.split(';').map(piece => piece.trim().split('=')[0])
      .filter(name => /^_ym(?:_|$)/.test(name) || /^ym_/.test(name));
    const host = location.hostname;
    const labels = host.split('.');
    for (const name of names) {
      document.cookie = `${name}=; Path=/; Max-Age=0; SameSite=Lax`;
      if (host && host !== 'localhost' && !/[:\[\]]/.test(host)) {
        for (let i = 0; i < labels.length - 1; ++i) {
          const domain = labels.slice(i).join('.');
          if (domain.includes('.')) {
            document.cookie = `${name}=; Path=/; Domain=${domain}; Max-Age=0; SameSite=Lax`;
          }
        }
      }
    }
    // Yandex can also use localStorage. Do not purge unrelated site keys.
    try {
      const keys = [];
      for (let i = 0; i < localStorage.length; i++) {
        const key = localStorage.key(i);
        if (key && (/^_ym(?:_|$)/.test(key) || /^ym_/.test(key))) keys.push(key);
      }
      for (const key of keys) localStorage.removeItem(key);
    } catch { /* storage may be disabled by privacy controls */ }
  }

  function reloadAfterConsentChange() {
    if (reloading) return;
    reloading = true;
    allowed = false;
    cleanCookies();
    // Immediately leave the document to stop third-party observers. Reinitializing
    // a running Yandex tag with different Webvisor flags is not a supported API.
    location.reload();
  }

  function init(choice) {
    recording = !!choice.recordings;
    allowed = true;
    initialized = true;
    // Official asynchronous tag stub. Never define or call it before permission.
    if (typeof window.ym !== 'function') {
      window.ym = function () { (window.ym.a = window.ym.a || []).push(arguments); };
      window.ym.l = Date.now();
    }
    window.ym(id, 'init', {
      defer: true, // Avoid the default URL being sent with sensitive query parameters.
      clickmap: clickmap && recording,
      webvisor: webvisor && recording,
      accurateTrackBounce: bounce,
      trackLinks: false
    });
    remote = document.createElement('script');
    remote.async = true;
    remote.src = 'https://mc.yandex.ru/metrika/tag.js';
    remote.onload = () => { remoteLoaded = true; };
    remote.onerror = () => { remoteFailed = true; };
    document.head.appendChild(remote);
    if (pageViews) {
      // Explicit single page view. Never include URL query, fragment or referrer.
      viewSent = true;
      window.ym(id, 'hit', location.origin + location.pathname, {
        title: document.title.slice(0, 200)
      });
    }
  }

  function reconcile() {
    const choice = preference();
    if (!initialized) {
      if (choice) init(choice);
      return;
    }
    if (!choice || recording !== !!choice.recordings) {
      // On withdrawal, a loaded Webvisor can keep collecting even if the public
      // JS API stops issuing goals. Navigation is the enforceable stop boundary.
      if (remote && !remoteLoaded) remote.remove();
      reloadAfterConsentChange();
    } else {
      allowed = true;
    }
  }

  function goal(name, params = {}) {
    if (!allowed || reloading || !preference() || !initialized || !validGoal(name) ||
        !params || typeof params !== 'object' || Array.isArray(params)) return false;
    const entries = Object.entries(params);
    if (entries.length > 10) return false;
    const safe = Object.create(null);
    for (const [key, value] of entries) {
      if (!/^[A-Za-z][A-Za-z0-9_]{0,39}$/.test(key) ||
          !((typeof value === 'string' && value.length <= 100 && !/[<>]/.test(value)) ||
            (typeof value === 'number' && Number.isFinite(value)) || typeof value === 'boolean')) return false;
      safe[key] = value;
    }
    if (entries.length) window.ym(id, 'reachGoal', name, safe);
    else window.ym(id, 'reachGoal', name);
    return true;
  }

  function hit(path) {
    if (!allowed || reloading || !preference() || !initialized ||
        typeof path !== 'string' || path.length > 1024 ||
        !/^\/(?:[A-Za-z0-9_./~-]|%[0-9A-Fa-f]{2})*$/.test(path) ||
        path.includes('..')) return false;
    window.ym(id, 'hit', location.origin + path, { title: document.title.slice(0, 200) });
    return true;
  }

  if (contacts) document.addEventListener('click', event => {
    if (!allowed || !(event.target instanceof Element)) return;
    const link = event.target.closest('a[href]');
    if (!link) return;
    const href = link.getAttribute('href') || '';
    // Never transmit addresses, phone numbers, or link query parameters.
    if (/^mailto:/i.test(href) && emailGoal) goal(emailGoal);
    else if (/^tel:/i.test(href) && phoneGoal) goal(phoneGoal);
  });

  Object.defineProperty(window, 'CajeerMetrica', {
    value: Object.freeze({
      goal, hit,
      status: () => Object.freeze({ enabled: true, allowed, initialized,
        remoteLoaded, remoteFailed, webvisor: recording && webvisor,
        clickmap: recording && clickmap, viewSent, reloading })
    }), configurable: false, writable: false
  });
  document.addEventListener('cajeer:consentchange', reconcile);
  reconcile(); // consent.js executes before this deferred script
})();
