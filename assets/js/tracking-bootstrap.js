/**
 * First-party session + first-touch attribution cookies (readable on server for forms/mail).
 */
(function () {
	'use strict';

	var cfg = window.marqTracking;
	if (!cfg || !cfg.cookies) {
		return;
	}

	var c = cfg.cookies;
	var MAX_AGE = 2592000; // 30 days
	var LANDING_MAX = 2000;

	function getCookie(name) {
		var n = name + '=';
		var ca = document.cookie.split(';');
		var i;
		for (i = 0; i < ca.length; i++) {
			var x = ca[i].trim();
			if (x.indexOf(n) === 0) {
				return decodeURIComponent(x.substring(n.length));
			}
		}
		return null;
	}

	function setCookie(name, value, maxAge) {
		var secure = window.location.protocol === 'https:' ? ';Secure' : '';
		document.cookie =
			name +
			'=' +
			encodeURIComponent(value === undefined || value === null ? '' : String(value)) +
			';path=/;max-age=' +
			maxAge +
			';SameSite=Lax' +
			secure;
	}

	/** External traffic sources only — skip same-origin and wp-admin (e.g. “Visit site” from dashboard). */
	function isExternalAttributionReferrer(ref) {
		if (!ref || typeof ref !== 'string') {
			return false;
		}
		try {
			var u = new URL(ref);
			if (u.origin === window.location.origin) {
				return false;
			}
			var p = (u.pathname || '').toLowerCase();
			if (p.indexOf('wp-admin') !== -1 || p.indexOf('wp-login.php') !== -1) {
				return false;
			}
			return true;
		} catch (e) {
			return false;
		}
	}

	// Session id (refresh max-age when seen).
	var sid = getCookie(c.session);
	if (!sid) {
		sid =
			window.crypto && typeof window.crypto.randomUUID === 'function'
				? window.crypto.randomUUID()
				: 'mcv_' + Math.random().toString(36).slice(2) + Date.now().toString(36);
	}
	setCookie(c.session, sid, MAX_AGE);
	try {
		sessionStorage.setItem('marq_cv_session_id', sid);
	} catch (e) {}

	// First-touch landing + UTMs once per attribution window (cookie lifetime).
	if (!getCookie(c.firstAttr)) {
		var rawRef = document.referrer || '';
		if (rawRef.length > LANDING_MAX) {
			rawRef = rawRef.slice(0, LANDING_MAX);
		}
		var ref = isExternalAttributionReferrer(rawRef) ? rawRef : '';
		setCookie(c.landing, ref, MAX_AGE);

		var p = new URLSearchParams(window.location.search);
		var us = p.get('utm_source') || '';
		var um = p.get('utm_medium') || '';
		var uc = p.get('utm_campaign') || '';
		if (us) {
			setCookie(c.utmSource, us.slice(0, 255), MAX_AGE);
		}
		if (um) {
			setCookie(c.utmMedium, um.slice(0, 255), MAX_AGE);
		}
		if (uc) {
			setCookie(c.utmCampaign, uc.slice(0, 255), MAX_AGE);
		}

		setCookie(c.firstAttr, '1', MAX_AGE);
	}

	window.marqConversionsTracking = {
		getSessionId: function () {
			try {
				return sessionStorage.getItem('marq_cv_session_id') || getCookie(c.session) || '';
			} catch (err) {
				return getCookie(c.session) || '';
			}
		},
		getLandingReferrer: function () {
			var v = getCookie(c.landing);
			if (v === null || v === undefined || v === '') {
				return '';
			}
			return isExternalAttributionReferrer(v) ? v : '';
		},
		getUtms: function () {
			function g(name) {
				var v = getCookie(name);
				return v === null || v === undefined ? '' : v;
			}
			return {
				utm_source: g(c.utmSource),
				utm_medium: g(c.utmMedium),
				utm_campaign: g(c.utmCampaign)
			};
		}
	};

	var pf = cfg.postFields;
	if (pf && window.marqConversionsTracking) {
		var T = window.marqConversionsTracking;

		function ensureHidden(form, name, value) {
			if (!form || !name) {
				return;
			}
			var v = value === undefined || value === null ? '' : String(value);
			var sel = 'input[type="hidden"][name="' + name.replace(/"/g, '\\"') + '"]';
			var el = form.querySelector(sel);
			if (!el) {
				el = document.createElement('input');
				el.type = 'hidden';
				el.name = name;
				form.appendChild(el);
			}
			el.value = v;
		}

		function syncFormAttribution(form) {
			if (!T) {
				return;
			}
			var utms = T.getUtms();
			ensureHidden(form, pf.sessionId, T.getSessionId());
			ensureHidden(form, pf.landing, T.getLandingReferrer());
			ensureHidden(form, pf.utmSource, utms.utm_source || '');
			ensureHidden(form, pf.utmMedium, utms.utm_medium || '');
			ensureHidden(form, pf.utmCampaign, utms.utm_campaign || '');
			ensureHidden(form, pf.docRef, document.referrer || '');
		}

		document.addEventListener(
			'submit',
			function (e) {
				var form = e.target;
				if (!form || form.nodeName !== 'FORM') {
					return;
				}
				syncFormAttribution(form);
			},
			true
		);
	}
})();
