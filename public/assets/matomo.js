/* CajeerEngine Lite 1.0.8 — Matomo Cloud / self-hosted, explicit analytics opt-in.
 * A single first-party consent cookie is authoritative; Matomo does NOT persist
 * a second independent consent grant across pages. No beacon or tag before opt-in.
 */
(() => {
  'use strict';
  const element = document.currentScript;
  if (!element || !window.CajeerConsent) return;
  const mode = element.dataset.matomoMode;
  const siteId = element.dataset.matomoSite;
  const base = element.dataset.matomoUrl;
  if (!/^[1-9][0-9]{0,9}$/.test(siteId || '') ||
      !['cloud', 'onpremise'].includes(mode)) return;

  let baseUrl;
  try {
    baseUrl = new URL(base);
    if (baseUrl.protocol !== 'https:' || baseUrl.username || baseUrl.password ||
        baseUrl.search || baseUrl.hash || !/^(?:\/[A-Za-z0-9_-]+)*\/$/.test(baseUrl.pathname)) return;
    if (mode === 'cloud' &&
        (!/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.matomo\.cloud$/.test(baseUrl.hostname) ||
         baseUrl.port || baseUrl.pathname !== '/')) return;
    // Require exact canonical formatting from the PHP configuration.
    if (baseUrl.href !== base) return;
  } catch { return; }

  const endpoint = new URL('matomo.php', baseUrl).href;
  const scriptUrl = mode === 'cloud'
    ? `https://cdn.matomo.cloud/${baseUrl.hostname}/matomo.js`
    : new URL('matomo.js', baseUrl).href;
  const pageViews = element.dataset.matomoPageviews === '1';
  const contactEvents = element.dataset.matomoContacts === '1';
  let initialized = false;
  let permitted = false;
  let reloading = false;
  let remoteLoaded = false;
  let remoteFailed = false;
  let remote = null;
  let externalUrl = '';

  function allowed() {
    const c = window.CajeerConsent.get();
    return !!(c && c.saved && c.analytics);
  }

  function clearCookies() {
    // Matomo sets first-party _pk_* cookies. Third-party / HttpOnly cookies are
    // outside the origin's control; we also ask the tracker to delete its own.
    const names = document.cookie.split(';').map(pair => pair.trim().split('=')[0])
      .filter(name => /^_pk_(?:id|ses|ref|cvar|hsh|testcookie)[._]/.test(name));
    const host = location.hostname;
    const labels = host.split('.');
    for (const name of names) {
      const expired = `${name}=; Max-Age=0; SameSite=Lax`;
      document.cookie = `${expired}; Path=/`;
      if (host && host !== 'localhost' && !/[:\[\]]/.test(host)) {
        for (let i = 0; i < labels.length - 1; i++) {
          const domain = labels.slice(i).join('.');
          if (domain.includes('.')) document.cookie = `${expired}; Path=/; Domain=${domain}`;
        }
      }
    }
  }

  function stop() {
    if (reloading) return;
    reloading = true;
    permitted = false;
    // Undo consent in Matomo before navigating. This discards queued beacons
    // and Matomo's first-party cookies; hard navigation stops async observers.
    if (window._paq && initialized) {
      window._paq.push(['forgetConsentGiven']);
      window._paq.push(['forgetCookieConsentGiven']);
      window._paq.push(['deleteCookies']);
    }
    if (remote && !remoteLoaded) remote.remove();
    clearCookies();
    location.reload();
  }

  function start() {
    initialized = true;
    permitted = true;
    externalUrl = scriptUrl;
    window._paq = window._paq || [];
    // Both requirements are queued first, before any tracking commands.
    window._paq.push(['requireConsent']);
    window._paq.push(['requireCookieConsent']);
    window._paq.push(['setTrackerUrl', endpoint]);
    window._paq.push(['setSiteId', siteId]);
    // setConsentGiven is intentionally non-persistent; every new page rereads
    // the current, revisioned CajeerConsent selection before loading Matomo.
    window._paq.push(['setConsentGiven']);
    window._paq.push(['setCookieConsentGiven']);
    if (pageViews) window._paq.push(['trackPageView']);
    remote = document.createElement('script');
    remote.async = true;
    remote.src = scriptUrl;
    remote.onload = () => { remoteLoaded = true; };
    remote.onerror = () => { remoteFailed = true; };
    document.head.appendChild(remote);
  }

  function reconcile() {
    if (reloading) return;
    if (!initialized) {
      if (allowed()) start();
    } else if (!allowed()) stop();
  }

  function validToken(value) {
    return typeof value === 'string' && /^[a-z][a-z0-9_-]{0,39}$/.test(value) &&
      !/[0-9]{6,}/.test(value);
  }

  // Deliberately bounded symbolic labels, not free-form values or URLs.
  function event(category, action, name) {
    if (!permitted || reloading || !allowed() || !validToken(category) ||
        !validToken(action) || (name !== undefined && !validToken(name))) return false;
    const args = ['trackEvent', category, action];
    if (name !== undefined) args.push(name);
    window._paq.push(args);
    return true;
  }

  function goal(id, revenue) {
    if (!permitted || reloading || !allowed() ||
        !(typeof id === 'number' && Number.isSafeInteger(id) && id > 0 && id <= 9999999999) ||
        (revenue !== undefined && !(typeof revenue === 'number' && Number.isFinite(revenue) && revenue >= 0 && revenue <= 9999999))) return false;
    window._paq.push(revenue === undefined ? ['trackGoal', id] : ['trackGoal', id, revenue]);
    return true;
  }

  if (contactEvents) document.addEventListener('click', e => {
    if (!permitted || !allowed() || !(e.target instanceof Element)) return;
    const link = e.target.closest('a[href]');
    if (!link) return;
    const href = link.getAttribute('href') || '';
    if (/^mailto:/i.test(href)) event('contact', 'email');
    else if (/^tel:/i.test(href)) event('contact', 'phone');
  });

  Object.defineProperty(window, 'CajeerMatomo', {
    value: Object.freeze({ event, goal,
      status: () => Object.freeze({ initialized, permitted, reloading,
        remoteLoaded, remoteFailed, scriptUrl: externalUrl })
    }), configurable: false, writable: false
  });
  document.addEventListener('cajeer:consentchange', reconcile);
  reconcile();
})();
