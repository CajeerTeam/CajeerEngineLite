/* CajeerEngine Lite GA4 1.0.4. Basic Consent Mode: no Google resource before opt-in.
 * This file contains executable integration logic, not a provider embed placeholder. */
(() => {
  'use strict';
  const element = document.currentScript;
  if (!element) return;
  const id = element.dataset.ga4Id || '';
  if (!/^G-[A-Z0-9]{6,32}$/.test(id) || !window.CajeerConsent) return;
  const pageViews = element.dataset.ga4Pageviews === '1';
  const contactClicks = element.dataset.ga4Contacts === '1';
  const debugMode = element.dataset.ga4Debug === '1';
  const disableKey = `ga-disable-${id}`;
  const denied = Object.freeze({
    analytics_storage: 'denied', ad_storage: 'denied',
    ad_user_data: 'denied', ad_personalization: 'denied'
  });
  let initialized = false;
  let pageViewSent = false;
  let tagLoaded = false;
  let granted = false;
  let previousMarketing = false;
  let reloading = false;
  let remote = null;

  function consentState() {
    const consent = window.CajeerConsent.get();
    return consent && consent.saved && consent.analytics ? consent : null;
  }

  function consentFields(choice) {
    return {
      analytics_storage: choice ? 'granted' : 'denied',
      ad_storage: choice && choice.marketing ? 'granted' : 'denied',
      ad_user_data: choice && choice.marketing ? 'granted' : 'denied',
      ad_personalization: choice && choice.marketing ? 'granted' : 'denied'
    };
  }

  function gtag(...args) { window.dataLayer.push(args); }

  function clearOwnCookies() {
    // This cannot remove httpOnly, provider-domain, or already-sent analytics data.
    const names = document.cookie.split(';').map(part => part.trim().split('=')[0]).filter(name =>
      name === '_ga' || name.startsWith('_ga_') || name === '_gid' ||
      name === '_gat' || name.startsWith('_gat_') || name.startsWith('_gac_')
    );
    const host = location.hostname;
    const labels = host.split('.');
    for (const name of names) {
      document.cookie = `${name}=; Path=/; Max-Age=0; SameSite=Lax`;
      // GA commonly sets Domain=.example.org, not the www.example.org host.
      // Try each parent suffix; browsers reject public suffixes automatically.
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

  function start(choice) {
    if (reloading) return;
    const wasGranted = granted;
    granted = true;
    window[disableKey] = false;
    if (initialized) {
      if (!wasGranted || previousMarketing !== choice.marketing) {
        gtag('consent', 'update', consentFields(choice));
        previousMarketing = choice.marketing;
      }
      return;
    }
    // Consent defaults must precede the first command that can send measurements.
    window.dataLayer = window.dataLayer || [];
    if (typeof window.gtag !== 'function') window.gtag = gtag;
    gtag('consent', 'default', denied);
    gtag('consent', 'update', consentFields(choice));
    gtag('js', new Date());
    gtag('config', id, {
      send_page_view: false,
      allow_google_signals: false,
      allow_ad_personalization_signals: false,
      debug_mode: debugMode
    });
    previousMarketing = choice.marketing;
    initialized = true;

    remote = document.createElement('script');
    remote.async = true;
    remote.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(id)}`;
    remote.onload = () => { tagLoaded = true; };
    remote.onerror = () => { tagLoaded = false; };
    document.head.appendChild(remote);

    // Explicit, exactly-once navigation page_view. Avoid gtag's automatic config view.
    if (pageViews && !pageViewSent) {
      pageViewSent = true;
      gtag('event', 'page_view', {
        send_to: id,
        page_title: document.title.slice(0, 200),
        page_location: location.origin + location.pathname
      });
    }
  }

  function stop() {
    if (reloading) return;
    reloading = true;
    // A running Google tag may retain observers and queues. Setting its disable
    // flag alone is not a reliable withdrawal boundary; navigate after denial.
    window[disableKey] = true;
    granted = false;
    if (initialized) gtag('consent', 'update', denied);
    if (remote && !tagLoaded) remote.remove();
    clearOwnCookies();
    location.reload();
  }

  function reconcile() {
    if (reloading) return;
    const choice = consentState();
    if (choice) start(choice);
    else if (granted) stop();
  }

  // Never accept personally identifying keys, email/phone/URL strings, or
  // long numeric identifiers from arbitrary callers of the public event API.
  // Legitimate business events should use short symbolic labels or numbers.
  function safeParam(key, value) {
    if (!/^[a-z][a-z0-9_]{0,39}$/.test(key) ||
        /(?:email|phone|address|user|token|secret|password|ip_address|identifier|location|url|href|referrer|name|search|query|session|client_id)/.test(key) ||
        ['send_to', 'event', 'page_location', 'page_referrer', 'user_id'].includes(key)) return false;
    if (typeof value === 'boolean') return true;
    if (typeof value === 'number') return Number.isFinite(value) && Math.abs(value) <= 1e9;
    return typeof value === 'string' && value.length <= 80 &&
      /^[\p{L}\p{N} _.-]*$/u.test(value) && !/[0-9]{7,}/.test(value);
  }

  function track(name, params = {}) {
    if (!granted || reloading || !consentState() || !initialized ||
        typeof name !== 'string' || !/^[a-z][a-z0-9_]{0,39}$/.test(name) ||
        /^(ga_|google_|firebase_)/.test(name) ||
        ['page_view', 'session_start', 'first_visit', 'user_engagement'].includes(name) ||
        (/[0-9]{7,}/.test(name)) ||
        !params || typeof params !== 'object' || Array.isArray(params)) return false;
    const entries = Object.entries(params);
    if (entries.length > 25) return false;
    const properties = { send_to: id };
    for (const [key, value] of entries) {
      if (!safeParam(key, value)) return false;
      properties[key] = value;
    }
    gtag('event', name, properties);
    return true;
  }

  // Only event category is sent, never the address or phone number in href.
  if (contactClicks) document.addEventListener('click', event => {
    if (!granted || !(event.target instanceof Element)) return;
    const anchor = event.target.closest('a[href]');
    if (!anchor) return;
    const href = anchor.getAttribute('href') || '';
    if (/^mailto:/i.test(href)) track('contact_click', { contact_type: 'email' });
    else if (/^tel:/i.test(href)) track('contact_click', { contact_type: 'phone' });
  });

  window[disableKey] = true; // Deny even if another script invokes gtag first.
  Object.defineProperty(window, 'CajeerGA4', {
    value: Object.freeze({
      track,
      status: () => Object.freeze({ enabled: true, consent: granted, initialized, tagLoaded, pageViewSent, reloading })
    }),
    configurable: false,
    writable: false
  });
  document.addEventListener('cajeer:consentchange', reconcile);
  // Consent is evaluated after defer-ordered consent.js has registered its API.
  reconcile();
})();
