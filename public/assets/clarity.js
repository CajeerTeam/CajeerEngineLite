/* CajeerEngine Lite 1.0.7 — Microsoft Clarity (strict opt-in).
 * Clarity combines behavior analytics and session recording: load nothing until
 * BOTH analytics and recordings are consented to. The provider's Consent API v2
 * is queued BEFORE its tag is requested; no cookie-less collection by default.
 */
(() => {
  'use strict';
  const script = document.currentScript;
  if (!script || !window.CajeerConsent) return;
  const id = script.dataset.clarityId || '';
  if (!/^[a-z0-9]{1,40}$/.test(id)) return;
  const contactEvents = script.dataset.clarityContacts === '1';
  let initialized = false;
  let permitted = false;
  let reloading = false;
  let remoteLoaded = false;
  let remoteFailed = false;
  let remote = null;
  let marketing = false;

  function preference() {
    const value = window.CajeerConsent.get();
    return value && value.saved && value.analytics && value.recordings ? value : null;
  }

  function signals(value) {
    // Microsoft's Consent API v2 has case-sensitive *Storage keys.
    return {
      analytics_Storage: value ? 'granted' : 'denied',
      ad_Storage: value && value.marketing ? 'granted' : 'denied'
    };
  }

  function clearCookies() {
    // Only this origin's first-party Clarity cookies are controllable here.
    // Third-party / HttpOnly cookies and remotely stored data are not.
    const names = document.cookie.split(';').map(p => p.trim().split('=')[0])
      .filter(name => name === '_clck' || name === '_clsk');
    const host = location.hostname;
    const labels = host.split('.');
    for (const name of names) {
      document.cookie = `${name}=; Path=/; Max-Age=0; SameSite=Lax`;
      if (host && host !== 'localhost' && !/[:\[\]]/.test(host)) {
        for (let i = 0; i < labels.length - 1; i++) {
          const domain = labels.slice(i).join('.');
          if (domain.includes('.')) {
            document.cookie = `${name}=; Path=/; Domain=${domain}; Max-Age=0; SameSite=Lax`;
          }
        }
      }
    }
  }

  function stop() {
    if (reloading) return;
    reloading = true;
    permitted = false;
    // Clarity already injected may retain observers: navigation is the hard
    // stop boundary; send explicit denial before leaving the old page.
    if (typeof window.clarity === 'function') {
      window.clarity('consentv2', signals(null));
    }
    if (remote && !remoteLoaded) remote.remove();
    clearCookies();
    location.reload();
  }

  function start(choice) {
    initialized = true;
    permitted = true;
    marketing = !!choice.marketing;
    // Official asynchronous Clarity stub, created ONLY after permission.
    if (typeof window.clarity !== 'function') {
      window.clarity = function () {
        (window.clarity.q = window.clarity.q || []).push(arguments);
      };
    }
    window.clarity('consentv2', signals(choice));
    remote = document.createElement('script');
    remote.async = true;
    remote.src = `https://www.clarity.ms/tag/${encodeURIComponent(id)}`;
    remote.onload = () => { remoteLoaded = true; };
    remote.onerror = () => { remoteFailed = true; };
    document.head.appendChild(remote);
  }

  function reconcile() {
    if (reloading) return;
    const value = preference();
    if (!initialized) {
      if (value) start(value);
      return;
    }
    if (!value) {
      stop();
      return;
    }
    if (marketing !== !!value.marketing) {
      marketing = !!value.marketing;
      window.clarity('consentv2', signals(value));
    }
  }

  // Only bounded, non-identifying event names. Never pass user identifiers,
  // page URLs, email addresses, telephone numbers or free-form payloads.
  function event(name) {
    if (!permitted || reloading || !preference() ||
        typeof name !== 'string' || !/^cajeer_[a-z][a-z0-9_]{0,40}$/.test(name) ||
        /[0-9]{6,}/.test(name)) return false;
    window.clarity('event', name);
    return true;
  }

  if (contactEvents) document.addEventListener('click', e => {
    if (!permitted || !preference() || !(e.target instanceof Element)) return;
    const link = e.target.closest('a[href]');
    if (!link) return;
    const href = link.getAttribute('href') || '';
    if (/^mailto:/i.test(href)) event('cajeer_contact_email');
    else if (/^tel:/i.test(href)) event('cajeer_contact_phone');
  });

  Object.defineProperty(window, 'CajeerClarity', {
    value: Object.freeze({
      event,
      status: () => Object.freeze({ initialized, permitted, reloading,
        remoteLoaded, remoteFailed })
    }), configurable: false, writable: false
  });
  document.addEventListener('cajeer:consentchange', reconcile);
  reconcile();
})();
