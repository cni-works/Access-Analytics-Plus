(function () {
  'use strict';

  if (!window.aapTracker || !window.fetch || !window.crypto) {
    return;
  }

  var VISITOR_COOKIE = 'aap_vid';
  var VISITOR_STORAGE = 'aap_visitor_id';
  var SESSION_COOKIE = 'aap_sid';
  var SESSION_STORAGE = 'aap_session_state';

  function uuid() {
    if (typeof window.crypto.randomUUID === 'function') {
      return window.crypto.randomUUID();
    }
    var bytes = new Uint8Array(16);
    window.crypto.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    return Array.from(bytes, function (byte, index) {
      var value = byte.toString(16).padStart(2, '0');
      return [4, 6, 8, 10].indexOf(index) !== -1 ? '-' + value : value;
    }).join('');
  }

  function readCookie(name) {
    var prefix = name + '=';
    var item = document.cookie.split('; ').find(function (part) {
      return part.indexOf(prefix) === 0;
    });
    return item ? decodeURIComponent(item.substring(prefix.length)) : '';
  }

  function writeCookie(name, value, maxAge) {
    var secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = name + '=' + encodeURIComponent(value) + '; Path=/; Max-Age=' + maxAge + '; SameSite=Lax' + secure;
  }

  function getVisitorId() {
    var value = readCookie(VISITOR_COOKIE);
    if (!value) {
      try {
        value = window.localStorage.getItem(VISITOR_STORAGE) || '';
      } catch (error) {
        // A per-page identifier remains available when both storage methods are blocked.
      }
    }
    if (!value) {
      value = uuid();
    }
    writeCookie(VISITOR_COOKIE, value, 31536000);
    try {
      window.localStorage.setItem(VISITOR_STORAGE, value);
    } catch (error) {
      // Cookie-only mode remains available when storage access is blocked.
    }
    return value;
  }

  function getSessionId(forceNew) {
    var timeout = Number(window.aapTracker.sessionTimeout || 1800);
    var now = Date.now();
    var value = forceNew ? '' : readCookie(SESSION_COOKIE);

    if (!value && !forceNew) {
      try {
        var stored = JSON.parse(window.localStorage.getItem(SESSION_STORAGE) || '{}');
        if (stored.id && Number(stored.expires || 0) > now) {
          value = stored.id;
        }
      } catch (error) {
        // Cookie-only mode remains available when storage access is blocked.
      }
    }

    if (!value) {
      value = uuid();
    }
    writeCookie(SESSION_COOKIE, value, timeout);
    try {
      window.localStorage.setItem(SESSION_STORAGE, JSON.stringify({ id: value, expires: now + timeout * 1000 }));
    } catch (error) {
      // Cookie-only mode remains available when storage access is blocked.
    }
    return value;
  }

  function deviceType() {
    var userAgent = navigator.userAgent || '';
    var isIPadDesktopMode = /Macintosh/i.test(userAgent) && Number(navigator.maxTouchPoints || 0) > 1;
    if (isIPadDesktopMode || /iPad|Tablet|Kindle|Silk/i.test(userAgent)) {
      return 'tablet';
    }
    if (/Android/i.test(userAgent) && !/Mobile/i.test(userAgent)) {
      return 'tablet';
    }
    if (/Mobile|iPhone|iPod|Android/i.test(userAgent)) {
      return 'mobile';
    }
    return userAgent ? 'desktop' : 'other';
  }

  function beginEngagement(token) {
    var endpoint = window.aapTracker.engagementEndpoint;
    if (!token || !endpoint) {
      return;
    }

    var idleMilliseconds = Number(window.aapTracker.engagementIdleSeconds || 300) * 1000;
    var maxMilliseconds = Number(window.aapTracker.engagementMaxSeconds || 1800) * 1000;
    var totalMilliseconds = 0;
    var lastTick = Date.now();
    var lastActivity = lastTick;
    var lastSentSeconds = 0;
    var isVisible = document.visibilityState !== 'hidden';
    var stopped = false;
    var mouseMoveAt = 0;

    function advance() {
      var now = Date.now();
      if (!stopped && isVisible && totalMilliseconds < maxMilliseconds) {
        var activeUntil = Math.min(now, lastActivity + idleMilliseconds);
        if (activeUntil > lastTick) {
          totalMilliseconds = Math.min(maxMilliseconds, totalMilliseconds + activeUntil - lastTick);
        }
      }
      lastTick = now;

      if (totalMilliseconds >= maxMilliseconds) {
        stopped = true;
      }
    }

    function transmit(useBeacon) {
      var seconds = Math.min(
        Number(window.aapTracker.engagementMaxSeconds || 1800),
        Math.floor(totalMilliseconds / 1000)
      );
      if (seconds <= lastSentSeconds) {
        return;
      }

      var payload = JSON.stringify({ token: token, seconds: seconds });
      lastSentSeconds = seconds;
      getSessionId(false);

      if (useBeacon && navigator.sendBeacon) {
        var blob = new Blob([payload], { type: 'application/json' });
        if (navigator.sendBeacon(endpoint, blob)) {
          return;
        }
      }

      window.fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        keepalive: true,
        headers: { 'Content-Type': 'application/json' },
        body: payload
      }).catch(function () {
        // Engagement tracking must never interrupt the visitor experience.
      });
    }

    function maybeTransmit() {
      var seconds = Math.floor(totalMilliseconds / 1000);
      var reachedIdleLimit = isVisible && Date.now() - lastActivity >= idleMilliseconds;
      var reachedTimeLimit = totalMilliseconds >= maxMilliseconds;
      if (
        (lastSentSeconds === 0 && seconds >= 15) ||
        seconds - lastSentSeconds >= 60 ||
        ((reachedIdleLimit || reachedTimeLimit) && seconds > lastSentSeconds)
      ) {
        transmit(false);
      }
    }

    function markActivity() {
      advance();
      lastActivity = Date.now();
      lastTick = lastActivity;
      if (totalMilliseconds < maxMilliseconds) {
        stopped = false;
      }
    }

    function markMouseActivity() {
      var now = Date.now();
      if (now - mouseMoveAt < 1000) {
        return;
      }
      mouseMoveAt = now;
      markActivity();
    }

    document.addEventListener('visibilitychange', function () {
      advance();
      isVisible = document.visibilityState !== 'hidden';
      lastTick = Date.now();
      if (!isVisible) {
        transmit(true);
      }
    });
    window.addEventListener('scroll', markActivity, { passive: true });
    window.addEventListener('pointerdown', markActivity, { passive: true });
    window.addEventListener('touchstart', markActivity, { passive: true });
    window.addEventListener('keydown', markActivity);
    window.addEventListener('mousemove', markMouseActivity, { passive: true });
    window.addEventListener('pagehide', function () {
      advance();
      transmit(true);
    });
    window.addEventListener('pageshow', function () {
      isVisible = document.visibilityState !== 'hidden';
      lastTick = Date.now();
      if (totalMilliseconds < maxMilliseconds) {
        stopped = false;
      }
    });

    window.setInterval(function () {
      advance();
      maybeTransmit();
    }, 1000);
  }

  function beginShadowDiagnostics(token) {
    var endpoint = window.aapTracker.shadowEndpoint;
    if (!token || !endpoint) {
      return;
    }

    var visibleTarget = Number(window.aapTracker.shadowVisibleSeconds || 3) * 1000;
    var visibleMilliseconds = 0;
    var lastTick = Date.now();
    var isVisible = document.visibilityState !== 'hidden';
    var visibleAcknowledged = false;
    var interactionAcknowledged = 0;
    var interactionMask = 0;
    var requestInFlight = false;
    var retryAttempts = 0;
    var retryScheduled = false;
    var retryExhausted = false;
    var MAX_RETRIES = 3;
    var RETRY_DELAYS = [1000, 3000, 8000];

    function advance() {
      var now = Date.now();
      var wasVisibleConfirmed = visibleMilliseconds >= visibleTarget;
      if (isVisible && now > lastTick) {
        visibleMilliseconds += now - lastTick;
      }
      if (!wasVisibleConfirmed && visibleMilliseconds >= visibleTarget) {
        retryAttempts = 0;
        retryExhausted = false;
      }
      lastTick = now;
    }

    function transmit(useBeacon) {
      var visibleConfirmed = visibleMilliseconds >= visibleTarget;
      var hasVisibleUpdate = visibleConfirmed && !visibleAcknowledged;
      var hasInteractionUpdate = (interactionMask & ~interactionAcknowledged) !== 0;
      if ((!hasVisibleUpdate && !hasInteractionUpdate) || requestInFlight || (retryExhausted && !useBeacon)) {
        return;
      }

      var payload = JSON.stringify({
        token: token,
        visible_confirmed: visibleConfirmed,
        interaction_mask: interactionMask
      });
      if (useBeacon && navigator.sendBeacon) {
        var blob = new Blob([payload], { type: 'application/json' });
        if (navigator.sendBeacon(endpoint, blob)) {
          // sendBeacon has no response. Keep the state unacknowledged so a bfcache
          // restore can retry it; duplicate signals are idempotent on the server.
          return;
        }
      }

      if (retryExhausted) {
        return;
      }

      requestInFlight = true;
      var sentVisible = visibleConfirmed;
      var sentInteractions = interactionMask;
      window.fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        keepalive: true,
        headers: { 'Content-Type': 'application/json' },
        body: payload
      }).then(function (response) {
        if (!response.ok) {
          throw new Error('shadow_signal_http_' + response.status);
        }
        return response.json().catch(function () { return { accepted: true }; });
      }).then(function (data) {
        if (data && data.accepted === false) {
          throw new Error('shadow_signal_rejected');
        }
        if (sentVisible) {
          visibleAcknowledged = true;
        }
        interactionAcknowledged |= sentInteractions;
        requestInFlight = false;
        retryAttempts = 0;
        retryScheduled = false;
        retryExhausted = false;
        transmit(false);
      }).catch(function () {
        requestInFlight = false;
        if (retryAttempts >= MAX_RETRIES || retryScheduled) {
          retryExhausted = retryAttempts >= MAX_RETRIES;
          return;
        }
        retryScheduled = true;
        var delay = RETRY_DELAYS[Math.min(retryAttempts, RETRY_DELAYS.length - 1)];
        retryAttempts += 1;
        window.setTimeout(function () {
          retryScheduled = false;
          transmit(false);
        }, delay);
      });
    }

    function markInteraction(flag) {
      advance();
      if ((interactionMask & flag) === 0) {
        retryAttempts = 0;
        retryExhausted = false;
      }
      interactionMask |= flag;
      transmit(false);
    }

    document.addEventListener('visibilitychange', function () {
      advance();
      isVisible = document.visibilityState !== 'hidden';
      lastTick = Date.now();
      transmit(true);
    });
    window.addEventListener('scroll', function () { markInteraction(1); }, { passive: true });
    window.addEventListener('pointerdown', function () { markInteraction(2); }, { passive: true });
    window.addEventListener('touchstart', function () { markInteraction(4); }, { passive: true });
    window.addEventListener('keydown', function () { markInteraction(8); });
    window.addEventListener('pagehide', function () {
      advance();
      transmit(true);
    });
    window.addEventListener('pageshow', function () {
      isVisible = document.visibilityState !== 'hidden';
      lastTick = Date.now();
      retryAttempts = 0;
      retryExhausted = false;
      transmit(false);
    });

    window.setInterval(function () {
      advance();
      transmit(false);
    }, 1000);
  }

  function send(forceNewSession) {
    var payload = {
      collect_id: uuid(),
      visitor_id: getVisitorId(),
      session_id: getSessionId(Boolean(forceNewSession)),
      path: window.location.pathname,
      title: document.title || '',
      referrer: document.referrer || '',
      device_type: deviceType(),
      webdriver: typeof navigator.webdriver === 'boolean' ? (navigator.webdriver ? 1 : 0) : -1,
      tracker_build: String(window.aapTracker.build || 'unknown')
    };

    transmitCollect(payload, Boolean(forceNewSession), 0);
  }

  function transmitCollect(payload, forceNewSession, retryAttempt) {
    var MAX_COLLECT_RETRIES = 3;
    var COLLECT_RETRY_DELAYS = [1000, 3000, 8000];

    window.fetch(window.aapTracker.endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      keepalive: true,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    }).then(function (response) {
      if (response.status === 409 && !forceNewSession) {
        send(true);
        return null;
      }

      if (!response.ok) {
        var error = new Error('collect_http_' + response.status);
        error.retryable = response.status >= 500 || response.status === 408 || response.status === 429;
        throw error;
      }

      return response.json();
    }).then(function (data) {
      if (data && data.accepted && data.engagement_token) {
        beginEngagement(data.engagement_token);
        beginShadowDiagnostics(data.engagement_token);
      }
    }).catch(function (error) {
      var retryable = !error || error.retryable !== false;
      if (!retryable || retryAttempt >= MAX_COLLECT_RETRIES) {
        return;
      }
      window.setTimeout(function () {
        // Keep the same IDs across retries so a temporary failure cannot create
        // several synthetic visitors.
        transmitCollect(payload, forceNewSession, retryAttempt + 1);
      }, COLLECT_RETRY_DELAYS[Math.min(retryAttempt, COLLECT_RETRY_DELAYS.length - 1)]);
    });
  }

  send(false);
})();
