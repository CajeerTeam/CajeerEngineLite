/* CajeerEngine Lite 1.0.6: real GTM container + basic Consent Mode v2.
 * No third-party request is made without explicitly saved optional consent.
 * Tags INSIDE a user-managed GTM container must also have consent checks. */
(() => {
  'use strict';
  const element = document.currentScript;
  if (!element || !window.CajeerConsent) return;
  const id = element.dataset.gtmId || '';
  if (!/^GTM-[A-Z0-9]{4,32}$/.test(id)) return;
  const contactEvents = element.dataset.gtmContacts === '1';
  const denied = Object.freeze({
    analytics_storage: 'denied', ad_storage: 'denied',
    ad_user_data: 'denied', ad_personalization: 'denied'
  });
  let initialized = false;
  let allowed = false;
  let remoteLoaded = false;
  let remoteFailed = false;
  let reloading = false;
  let activeMask = 0;
  let remote = null;

  // Preserve the standard Google dataLayer / gtag contract. Consent commands
  // precede gtm.js initialization; GTM consumes them in FIFO order.
  if (!Array.isArray(window.dataLayer)) window.dataLayer = [];
  function gtag() { window.dataLayer.push(arguments); }
  if (typeof window.gtag !== 'function') window.gtag = gtag;
  gtag('consent', 'default', denied);

  function choice() {
    const value = window.CajeerConsent.get();
    return value && value.saved ? value : null;
  }
  function mask(value) {
    return value ? ((value.analytics ? 1 : 0) | (value.marketing ? 2 : 0) |
      (value.recordings ? 4 : 0)) : 0;
  }
  function fields(value) {
    return {
      analytics_storage: value && value.analytics ? 'granted' : 'denied',
      ad_storage: value && value.marketing ? 'granted' : 'denied',
      ad_user_data: value && value.marketing ? 'granted' : 'denied',
      ad_personalization: value && value.marketing ? 'granted' : 'denied'
    };
  }
  function emitConsent(value) {
    // Custom category variables permit Additional Consent Checks in GTM for
    // non-Google tags, including third-party session recording tools.
    window.dataLayer.push({
      event: 'cajeer_consent_update',
      cajeer_consent: {
        analytics: !!(value && value.analytics),
        marketing: !!(value && value.marketing),
        recordings: !!(value && value.recordings)
      }
    });
  }
  function clearGoogleCookies() {
    const names = document.cookie.split(';').map(part => part.trim().split('=')[0])
      .filter(name => name === '_ga' || name.startsWith('_ga_') ||
        name === '_gid' || name === '_gat' || name.startsWith('_gat_') ||
        name.startsWith('_gac_') || name.startsWith('_gcl_'));
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
  function start(value) {
    initialized = true;
    allowed = true;
    activeMask = mask(value);
    gtag('consent', 'update', fields(value));
    emitConsent(value);
    window.dataLayer.push({ 'gtm.start': Date.now(), event: 'gtm.js' });
    remote = document.createElement('script');
    remote.async = true;
    remote.src = `https://www.googletagmanager.com/gtm.js?id=${encodeURIComponent(id)}`;
    remote.onload = () => { remoteLoaded = true; };
    remote.onerror = () => { remoteFailed = true; };
    document.head.appendChild(remote);
  }
  function reloadToEnforce(value) {
    if (reloading) return;
    reloading = true;
    allowed = false;
    // Deny every category until the next document is initialized. Applying
    // newly granted categories to the old container would briefly expose
    // already-running tags with the new permissions before navigation.
    gtag('consent', 'update', denied);
    emitConsent(null);
    if (!value || !value.analytics || !value.marketing) clearGoogleCookies();
    // Already executed Custom HTML tags cannot be reliably unloaded. A page
    // navigation is the only enforceable stop boundary for the live container.
    if (remote && !remoteLoaded) remote.remove();
    location.reload();
  }
  function reconcile() {
    const current = choice();
    const nextMask = mask(current);
    if (!initialized) {
      if (nextMask !== 0) start(current);
      return;
    }
    if (nextMask !== activeMask) reloadToEnforce(current);
  }

  // Explicitly categorized custom dataLayer events; do not accept arbitrary
  // URLs, tokens, phone numbers or email addresses as payload fields.
  function track(name, params = {}, category = 'analytics') {
    if (!initialized || !allowed || reloading ||
        !['analytics', 'marketing', 'recordings'].includes(category) ||
        !choice()?.[category] ||
        typeof name !== 'string' || !/^cajeer_[a-z][a-z0-9_]{0,48}$/.test(name) ||
        ['cajeer_consent_update', 'cajeer_contact_click'].includes(name) ||
        !params || typeof params !== 'object' || Array.isArray(params)) return false;
    const entries = Object.entries(params);
    if (entries.length > 20) return false;
    const event = { event: name, cajeer_event_category: category };
    for (const [key, value] of entries) {
      if (!/^[a-z][a-z0-9_]{0,39}$/.test(key) ||
          /(?:email|phone|address|user|token|secret|password|ip_address|identifier|location|url|href)/.test(key) ||
          ['event', 'cajeer_event_category'].includes(key) ||
          !(typeof value === 'boolean' || (typeof value === 'number' && Number.isFinite(value)) ||
            (typeof value === 'string' && value.length <= 80 &&
             /^[\p{L}\p{N} _.-]*$/u.test(value) && !/[0-9]{7,}/.test(value)))) return false;
      event[key] = value;
    }
    window.dataLayer.push(event);
    return true;
  }

  if (contactEvents) document.addEventListener('click', event => {
    if (!allowed || !choice()?.analytics || !(event.target instanceof Element)) return;
    const link = event.target.closest('a[href]');
    if (!link) return;
    const href = link.getAttribute('href') || '';
    if (/^mailto:/i.test(href) || /^tel:/i.test(href)) {
      window.dataLayer.push({
        event: 'cajeer_contact_click', cajeer_event_category: 'analytics',
        contact_type: /^mailto:/i.test(href) ? 'email' : 'phone'
      });
    }
  });

  Object.defineProperty(window, 'CajeerGTM', {
    value: Object.freeze({
      track,
      status: () => Object.freeze({ enabled: true, containerId: id, initialized,
        allowed, remoteLoaded, remoteFailed, reloading, consentMask: activeMask })
    }),
    configurable: false, writable: false
  });
  document.addEventListener('cajeer:consentchange', reconcile);
  reconcile();
})();
