/**
 * LeadTrackr ChannelFlow
 *
 * Records the visitor's marketing journey in a first-party cookie. One entry
 * per session, not per pageview.
 *
 * Must stay behaviourally identical to the GTM tag (leadtrackr/gtm-leadtrackr-tag)
 * and the LeadBot (leadtrackr/leadtrackr-leadbot): all three write the same two
 * cookies, so any difference in format or logic means they overwrite each
 * other's journeys. See docs/channelflow-logica.md in the tag repository.
 */
(function () {
  'use strict';

  var COOKIE_NAME = 'lt_channelflow';
  var SESSION_COOKIE = 'lt_session';
  var CONSENT_COOKIE = 'lt_consent';
  var CONSENT_TYPES = ['ad_storage', 'analytics_storage', 'ad_user_data', 'ad_personalization'];
  // Google's internal consent state codes.
  var CONSENT_GRANTED = 1;
  var CONSENT_DENIED = 2;
  var MAX_AGE_SECONDS = 395 * 86400;
  var DEFAULT_TIMEOUT_MINUTES = 30;

  // A entry marks the start of a session, so the array only grows for
  // returning visitors. Both limits guard the 4KB browser cookie limit: past
  // it the cookie is silently rejected and the whole journey is lost.
  var MAX_ENTRIES = 25;
  var MAX_COOKIE_LENGTH = 3500;

  var SEARCH_ENGINE_LABELS = [
    'google', 'bing', 'yahoo', 'duckduckgo', 'baidu',
    'ecosia', 'yandex', 'startpage', 'qwant', 'brave', 'naver'
  ];
  var SECOND_LEVEL_DOMAINS = ['co', 'com', 'org', 'net', 'gov', 'edu', 'ac', 'mil'];
  var GOOGLE_CLICK_IDS = ['gclid', 'gbraid', 'wbraid'];

  var config = window.leadtrackrChannelFlowConfig || {};
  var UTM_MAPPING = [
    ['s', config.sourceParam || 'utm_source'],
    ['m', config.mediumParam || 'utm_medium'],
    ['cm', config.campaignParam || 'utm_campaign'],
    ['ct', config.contentParam || 'utm_content'],
    ['tm', config.termParam || 'utm_term']
  ];

  function sessionTimeoutSeconds() {
    var minutes = parseInt(config.sessionTimeoutMinutes, 10);
    if (!minutes || minutes < 1) minutes = DEFAULT_TIMEOUT_MINUTES;
    return minutes * 60;
  }

  function getQueryParam(name) {
    return new URLSearchParams(window.location.search).get(name) || '';
  }

  function getCookie(name) {
    var cookies = document.cookie.split(';');
    for (var i = 0; i < cookies.length; i++) {
      var cookie = cookies[i].trim();
      if (cookie.indexOf(name + '=') === 0) {
        return cookie.substring(name.length + 1);
      }
    }
    return '';
  }

  var cachedDomain;

  /**
   * Highest domain the browser accepts a cookie on, found by probing rather
   * than by guessing from the hostname. Counting labels gets multi-part
   * suffixes wrong: on a .co.uk site it yields domain=.co.uk, which every
   * browser rejects, leaving no cookie at all.
   */
  function cookieDomain() {
    if (cachedDomain !== undefined) return cachedDomain;

    var parts = window.location.hostname.split('.');
    for (var i = parts.length - 2; i >= 0; i--) {
      var candidate = parts.slice(i).join('.');
      document.cookie = 'lt_probe=1;path=/;domain=' + candidate;
      if (getCookie('lt_probe')) {
        document.cookie = 'lt_probe=;path=/;domain=' + candidate + ';max-age=0';
        cachedDomain = candidate;
        return cachedDomain;
      }
    }
    cachedDomain = null;
    return cachedDomain;
  }

  function setCookie(name, value, maxAgeSeconds) {
    var domain = cookieDomain();
    document.cookie =
      name + '=' + encodeURIComponent(value) +
      ';path=/;max-age=' + maxAgeSeconds +
      (domain ? ';domain=' + domain : '') +
      ';SameSite=Lax' +
      (window.location.protocol === 'https:' ? ';Secure' : '');
  }

  /**
   * Registrable part of a host: www.google.nl -> google.nl,
   * www.google.co.uk -> google.co.uk. Matching on this instead of the full
   * host is what makes every country domain resolve to the same search engine.
   */
  function registrableDomain(host) {
    if (!host) return '';
    var parts = host.split('.');
    if (parts.length <= 2) return host;

    var take = SECOND_LEVEL_DOMAINS.indexOf(parts[parts.length - 2]) > -1 ? 3 : 2;
    if (parts.length <= take) return host;
    return parts.slice(parts.length - take).join('.');
  }

  function domainLabel(host) {
    var registrable = registrableDomain(host);
    return registrable ? registrable.split('.')[0] : '';
  }

  function referrerHost() {
    if (!document.referrer) return '';
    try {
      return new URL(document.referrer).hostname;
    } catch (e) {
      return '';
    }
  }

  // An empty referrer is not internal: a real ad click can arrive without one.
  function isInternalReferrer() {
    var referrer = referrerHost();
    if (!referrer) return false;
    return registrableDomain(referrer) === registrableDomain(window.location.hostname);
  }

  function utmChannel() {
    var channel = {};
    var hasAny = false;

    for (var i = 0; i < UTM_MAPPING.length; i++) {
      var value = getQueryParam(UTM_MAPPING[i][1]);
      if (value) {
        channel[UTM_MAPPING[i][0]] = value;
        hasAny = true;
      }
    }
    if (!hasAny) return null;

    if (!channel.s) channel.s = '(not set)';
    if (!channel.m) channel.m = '(not set)';
    return channel;
  }

  // Click IDs count only when present in this pageview's query string. Reading
  // them from _gcl_aw would mark every later visit as paid for 90 days.
  function clickIdChannel() {
    for (var i = 0; i < GOOGLE_CLICK_IDS.length; i++) {
      if (getQueryParam(GOOGLE_CLICK_IDS[i])) return { s: 'google', m: 'cpc' };
    }
    if (getQueryParam('msclkid')) return { s: 'bing', m: 'cpc' };
    return null;
  }

  function resolveChannel(utm, clickId) {
    if (utm) return utm;
    if (clickId) return clickId;

    var referrer = referrerHost();
    // Compared on domain level so a hop between subdomains stays internal.
    if (referrer && registrableDomain(referrer) !== registrableDomain(window.location.hostname)) {
      var label = domainLabel(referrer);
      if (SEARCH_ENGINE_LABELS.indexOf(label) > -1) return { s: label, m: 'organic' };
      return { s: referrer, m: 'referral' };
    }

    return { s: 'direct', m: 'none' };
  }

  function sameChannel(a, b) {
    if (!a || !b) return false;
    var keys = ['s', 'm', 'cm', 'ct', 'tm'];
    for (var i = 0; i < keys.length; i++) {
      if ((a[keys[i]] || '') !== (b[keys[i]] || '')) return false;
    }
    return true;
  }

  /**
   * Accepts both the compact format and the original one still living in
   * cookies out in the field, so existing journeys survive the upgrade.
   */
  function toCompactEntry(entry) {
    if (!entry || typeof entry !== 'object') return null;

    if (typeof entry.t === 'number' && entry.ch && typeof entry.ch === 'object') {
      return entry;
    }
    if (typeof entry.timestamp === 'number' && entry.channel && typeof entry.channel === 'object') {
      var legacy = entry.channel;
      var channel = {};
      if (legacy.source) channel.s = legacy.source;
      if (legacy.medium) channel.m = legacy.medium;
      if (legacy.campaign) channel.cm = legacy.campaign;
      if (legacy.content) channel.ct = legacy.content;
      if (legacy.term) channel.tm = legacy.term;
      return { t: entry.timestamp, ch: channel };
    }
    return null;
  }

  function readChannelFlow() {
    var raw = getCookie(COOKIE_NAME);
    if (!raw) return [];

    var text = raw;
    if (text.charAt(0) !== '[') {
      try {
        text = decodeURIComponent(text);
      } catch (e) {
        return [];
      }
    }
    if (text.charAt(0) !== '[') return [];

    var parsed;
    try {
      parsed = JSON.parse(text);
    } catch (e) {
      return [];
    }
    if (!Array.isArray(parsed)) return [];

    var flow = [];
    for (var i = 0; i < parsed.length; i++) {
      var compact = toCompactEntry(parsed[i]);
      if (compact) flow.push(compact);
    }
    return flow;
  }

  // Always drops the second entry, never the first: the first touch is what
  // makes first-touch attribution possible.
  function applyLimits(flow) {
    while (flow.length > MAX_ENTRIES && flow.length > 1) flow.splice(1, 1);
    while (flow.length > 1 &&
           encodeURIComponent(JSON.stringify(flow)).length > MAX_COOKIE_LENGTH) {
      flow.splice(1, 1);
    }
    return flow;
  }

  /**
   * Reads Consent Mode state from gtag's internal store and leaves it where PHP
   * can pick it up when a form is submitted — the lead is sent server-side, so
   * it cannot read this itself.
   *
   * There is no documented client-side API for this outside GTM's own template
   * sandbox, and plenty of sites have no gtag or GTM at all. Every path that
   * cannot produce a definite answer therefore removes the cookie rather than
   * writing a guess: an absent value reads as "we don't know", and if this call
   * ever stops working the field simply goes missing instead of going wrong.
   */
  var lastConsentWritten = null;

  function updateConsentState() {
    var getConsentState;
    try {
      getConsentState =
        window.google_tag_data &&
        window.google_tag_data.ics &&
        window.google_tag_data.ics.getConsentState;
    } catch (e) {
      getConsentState = null;
    }

    var state = {};
    var known = 0;
    if (typeof getConsentState === 'function') {
      for (var i = 0; i < CONSENT_TYPES.length; i++) {
        var type = CONSENT_TYPES[i];
        var value;
        try {
          value = getConsentState(type);
        } catch (e) {
          continue;
        }
        if (value === CONSENT_GRANTED) {
          state[type] = 'granted';
          known++;
        } else if (value === CONSENT_DENIED) {
          state[type] = 'denied';
          known++;
        }
      }
    }

    var serialised = known === 0 ? '' : JSON.stringify(state);
    if (serialised === lastConsentWritten) {
      return;
    }
    lastConsentWritten = serialised;

    if (serialised === '') {
      // Clear rather than leave a stale answer behind, for instance after gtag
      // has been removed from the site.
      setCookie(CONSENT_COOKIE, '', 0);
      return;
    }

    setCookie(CONSENT_COOKIE, serialised, sessionTimeoutSeconds());
  }

  function updateChannelFlow() {
    var flow = readChannelFlow();
    // Truthy, not just present: a cleared cookie can linger as an empty value.
    var sessionActive = !!getCookie(SESSION_COOKIE);

    // A click ID behind an internal referrer was carried over, not clicked:
    // consent mode's url_passthrough appends it to every internal link once
    // ad_storage is denied.
    var utm = utmChannel();
    var clickId = isInternalReferrer() ? null : clickIdChannel();

    var channel = resolveChannel(utm, clickId);
    var hasCampaignSignal = !!utm || !!clickId;
    var last = flow.length > 0 ? flow[flow.length - 1] : null;

    var isNewSession =
      !last ||
      !sessionActive ||
      (hasCampaignSignal && !sameChannel(last.ch, channel));

    if (isNewSession) {
      var entry = { t: Date.now(), ch: channel };
      var path = window.location.pathname || '';
      if (path) entry.lp = path.length > 100 ? path.substring(0, 100) : path;
      flow.push(entry);
      applyLimits(flow);
    }

    setCookie(COOKIE_NAME, JSON.stringify(flow), MAX_AGE_SECONDS);
    // Its existence is the session signal; expiry is left to the browser.
    setCookie(SESSION_COOKIE, '1', sessionTimeoutSeconds());
  }

  updateChannelFlow();
  updateConsentState();

  // Consent is nearly always given after this script has run: the banner
  // appears, the visitor accepts, and nothing navigates. Reading it once on
  // pageview would therefore record the state from before they agreed. These
  // two listeners re-read it at the moments that decide what ends up on the
  // lead, without loading anything extra at submit time.
  //
  // submit runs in the capture phase so it fires before a form builder turns
  // the submission into an AJAX call; the cookie is written synchronously and
  // so travels with that request either way.
  document.addEventListener('submit', updateConsentState, true);
  document.addEventListener('pointerdown', updateConsentState, true);
})();
