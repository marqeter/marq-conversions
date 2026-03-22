(function () {
	'use strict';

	var cfg = window.marqConversions;
	if (!cfg || !cfg.restUrl || !Array.isArray(cfg.rules) || cfg.rules.length === 0) {
		return;
	}

	var T = window.marqConversionsTracking;
	if (!T || typeof T.getSessionId !== 'function') {
		return;
	}

	function hrefStartsWith(anchor, prefix) {
		var href = anchor.getAttribute('href');
		if (!href || typeof href !== 'string') {
			return false;
		}
		return href.trim().toLowerCase().indexOf(prefix) === 0;
	}

	function findAnchor(el) {
		while (el && el !== document) {
			if (el.tagName && el.tagName.toLowerCase() === 'a') {
				return el;
			}
			el = el.parentNode;
		}
		return null;
	}

	function resolveMatch(anchor) {
		if (hrefStartsWith(anchor, 'tel:')) {
			return 'tel';
		}
		if (hrefStartsWith(anchor, 'mailto:')) {
			return 'mailto';
		}
		return null;
	}

	function ruleForMatch(match) {
		var i;
		for (i = 0; i < cfg.rules.length; i++) {
			if (cfg.rules[i].match === match) {
				return cfg.rules[i];
			}
		}
		return null;
	}

	function send(rule) {
		var utms = T.getUtms();
		var body = JSON.stringify({
			event: rule.event,
			match: rule.match,
			page_url: window.location.href.split('#')[0],
			session_id: T.getSessionId(),
			landing_referrer: T.getLandingReferrer(),
			utm_source: utms.utm_source || '',
			utm_medium: utms.utm_medium || '',
			utm_campaign: utms.utm_campaign || '',
			document_referrer: typeof document.referrer === 'string' ? document.referrer : ''
		});
		try {
			if (window.fetch) {
				window.fetch(cfg.restUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json'
					},
					body: body,
					keepalive: true
				}).catch(function () {});
			}
		} catch (e) {}
	}

	document.addEventListener(
		'click',
		function (ev) {
			var anchor = findAnchor(ev.target);
			if (!anchor) {
				return;
			}
			var match = resolveMatch(anchor);
			if (!match) {
				return;
			}
			var rule = ruleForMatch(match);
			if (!rule) {
				return;
			}
			send(rule);
		},
		true
	);
})();
