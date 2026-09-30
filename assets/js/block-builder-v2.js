/**
 * SHIED WORLD Schema Markup - block builder (v2).
 *
 * Drives every .smsw-metabox[data-add-button] container: the post editor
 * meta box, the Site Schema settings builder, and the Validation tab
 * builder. Renders schema blocks, wires the Add Schema Block button,
 * keeps the hidden JSON input in sync, builds live JSON-LD previews,
 * and handles copy/validate, AI suggestions, and rule-based warnings.
 */
(function () {
	'use strict';

	// jQuery is declared as an enqueue dependency (see enqueue_assets in
	// class-smsw-admin-menu.php), but deferred, combined or minified delivery
	// (cache/optimization plugins) can still execute this file before jQuery is
	// defined. `$` is always read from window.jQuery and never from a bare
	// global `$`, so WordPress noConflict mode -- and plugins such as Rank Math
	// or Elementor that may define their own `$` -- cannot break this file.
	// Every statement below the entry point is a function declaration, so no
	// jQuery call runs until start() invokes the entry point. If jQuery is
	// genuinely absent at that moment, the builder waits for it instead of
	// aborting permanently.
	var $ = null;
	var META = window.smswMetaBox || {};

	function start(fn) {
		var run = function (jq) {
			// Resolve the module-wide alias before the entry point runs, so the
			// builder functions this entry point calls all see a valid jQuery.
			$ = jq;
			fn(jq);
		};

		if (window.jQuery) {
			run(window.jQuery);
			return;
		}

		if (window.console && window.console.warn) {
			window.console.warn('[Schema Markup] jQuery was not available when block-builder-v2.js executed; waiting for it to load. Disable JS minify/combine/deferred loading in your cache plugin if the schema block builder still does not start.');
		}

		document.addEventListener('DOMContentLoaded', function () {
			if (window.jQuery) {
				run(window.jQuery);
			}
		});
	}

	function t(key) {
		return (META.i18n && META.i18n[key]) ? META.i18n[key] : '';
	}

	function isEmpty(v) {
		return v === null || v === undefined || String(v).trim() === '';
	}

	function escHtml(s) {
		return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	var uidCounter = 0;
	var instanceCounter = 0;
	function uid() {
		uidCounter += 1;
		return 'smsw_' + Date.now().toString(36) + '_' + uidCounter + '_' + Math.floor(Math.random() * 1000000).toString(36);
	}

	function debounce(fn, wait) {
		var timer = null;
		return function () {
			var args = arguments;
			var self = this;
			if (timer) { clearTimeout(timer); }
			timer = setTimeout(function () { fn.apply(self, args); }, wait);
		};
	}

	function fallbackCopy(text) {
		var ta = document.createElement('textarea');
		var ok = false;
		ta.value = text;
		ta.setAttribute('readonly', '');
		ta.style.position = 'fixed';
		ta.style.left = '-9999px';
		document.body.appendChild(ta);
		ta.select();
		try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
		document.body.removeChild(ta);
		return ok;
	}

	function copyText(text, cb) {
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(
				function () { cb(true); },
				function () { cb(fallbackCopy(text)); }
			);
		} else {
			cb(fallbackCopy(text));
		}
	}

	/**
	 * Push the live schema-block count into the side meta box badge.
	 *
	 * The "Go to Schema Blocks" side panel shows how many schema blocks
	 * the builder currently holds. sync() calls this after every block
	 * mutation (add, duplicate, delete, type change), so the badge can
	 * never drift from the hidden JSON input. On screens without the
	 * side panel (Site Schema, Validation) the badge element does not
	 * exist and this call is a no-op.
	 *
	 * @param {number} count Current number of schema blocks.
	 * @return {void}
	 */
	function updateSidePanelCount(count) {
		var $num = $('.smsw-side-panel-count-num').first();
		if (!$num.length) { return; }
		$num.text(String(count));
	}

	var NESTED_FIELDS = {
		PostalAddress: ['streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'addressCountry'],
		Person: ['name', 'url', 'jobTitle'],
		Organization: ['name', 'url', 'logo'],
		Brand: ['name'],
		Offer: ['price', 'priceCurrency', 'availability', 'url', 'itemCondition'],
		AggregateRating: ['ratingValue', 'bestRating', 'worstRating', 'ratingCount'],
		ReviewRating: ['ratingValue', 'bestRating', 'worstRating'],
		GeoCoordinates: ['latitude', 'longitude'],
		OpeningHoursSpecification: ['opens', 'closes', 'dayOfWeek'],
		ContactPoint: ['telephone', 'contactType', 'email'],
		ImageObject: ['url', 'caption'],
		QuantitativeValue: ['value', 'unitText', 'minValue', 'maxValue']
	};

	function nestedFieldsFor(nestedType) {
		return NESTED_FIELDS[nestedType] || ['name'];
	}

	var AUTOFILL_TOKENS = {
		name: '{{post_title}}',
		headline: '{{post_title}}',
		description: '{{meta_description}}',
		url: '{{post_url}}',
		image: '{{featured_image}}',
		datePublished: '{{post_date}}',
		logo: '{{site_logo}}'
	};

	function autofillTokenFor(key) {
		var k = String(key);
		return Object.prototype.hasOwnProperty.call(AUTOFILL_TOKENS, k) ? AUTOFILL_TOKENS[k] : '';
	}

	function hasNestedData(obj) {
		for (var k in obj) {
			if (Object.prototype.hasOwnProperty.call(obj, k) && k !== '@type' && !isEmpty(obj[k])) {
				return true;
			}
		}
		return false;
	}

	function cleanJsonLdValue(v) {
		if (v === null || v === undefined) { return null; }
		if (Object.prototype.toString.call(v) === '[object Array]') {
			var arr = [];
			for (var i = 0; i < v.length; i++) {
				var cv = cleanJsonLdValue(v[i]);
				if (cv !== null) { arr.push(cv); }
			}
			return arr.length ? arr : null;
		}
		if (typeof v === 'object') {
			var out = {};
			var keys = Object.keys(v);
			var dataKeys = 0;
			for (var k = 0; k < keys.length; k++) {
				var ck = keys[k];
				var cleaned = cleanJsonLdValue(v[ck]);
				if (cleaned === null) { continue; }
				out[ck] = cleaned;
				if (ck !== '@type') { dataKeys++; }
			}
			if (!dataKeys) { return null; }
			return out;
		}
		if (typeof v === 'string') {
			return isEmpty(v) ? null : v;
		}
		return v;
	}

	function labelForType(type) {
		if (META.types && META.types[type]) { return META.types[type]; }
		return type || '';
	}

	function isCustomType(type) {
		return String(type) === String(META.customType);
	}

	function typeExists(type) {
		if (!type) { return false; }
		if (META.types && META.types[type]) { return true; }
		return !!(META.vocabularyTypes && META.vocabularyTypes[type]);
	}

	var TYPE_ENTRIES = null;
	function typeEntriesCached() {
		if (TYPE_ENTRIES) { return TYPE_ENTRIES; }
		var groups = [];
		var seen = {};
		var groupLabels = [];
		var gk;

		if (META.typeGroups) {
			for (gk in META.typeGroups) {
				if (Object.prototype.hasOwnProperty.call(META.typeGroups, gk)) {
					groupLabels.push(gk);
				}
			}
		}

		function pushGroup(label, items) {
			var clean = [];
			var i;
			for (i = 0; i < items.length; i++) {
				var val = String(items[i] || '');
				if (!val || seen[val]) { continue; }
				seen[val] = true;
				clean.push({ value: val, label: labelForType(val) || val });
			}
			if (clean.length) {
				groups.push({ label: label, items: clean });
			}
		}

		var gi;
		for (gi = 0; gi < groupLabels.length; gi++) {
			pushGroup(groupLabels[gi], META.typeGroups[groupLabels[gi]] || []);
		}

		var leftover = [];
		if (META.vocabularyTypes) {
			for (var vk in META.vocabularyTypes) {
				if (Object.prototype.hasOwnProperty.call(META.vocabularyTypes, vk) && !seen[vk]) {
					leftover.push(vk);
				}
			}
		}
		if (META.types) {
			for (var tk in META.types) {
				if (Object.prototype.hasOwnProperty.call(META.types, tk) && !seen[tk] && tk !== META.customType) {
					leftover.push(tk);
				}
			}
		}
		leftover.sort(function (a, b) {
			var la = a.toLowerCase();
			var lb = b.toLowerCase();
			return la < lb ? -1 : (la > lb ? 1 : 0);
		});
		var letterBuckets = {};
		var li;
		for (li = 0; li < leftover.length; li++) {
			var name = leftover[li];
			var first = name.charAt(0).toUpperCase();
			if (!/^[A-Z]$/.test(first)) { first = '#'; }
			if (!letterBuckets[first]) { letterBuckets[first] = []; }
			letterBuckets[first].push(name);
		}
		var letters = Object.keys(letterBuckets).sort();
		for (li = 0; li < letters.length; li++) {
			pushGroup(letters[li], letterBuckets[letters[li]]);
		}

		if (META.customType) {
			pushGroup('Custom', [META.customType]);
		}

		TYPE_ENTRIES = groups;
		return TYPE_ENTRIES;
	}	function propsFor(type) {
		if (isCustomType(type)) { return {}; }
		return (META.properties && META.properties[type]) ? META.properties[type] : null;
	}

	/**
	 * Splits a type's property keys into the always-visible group and the
	 * collapsible group.
	 *
	 * This is the single place the essential/advanced split is decided, so all
	 * three builder contexts (settings site schema, post editor meta box and
	 * validation) get identical behaviour. The split itself is data, computed
	 * once in PHP and shipped as META.essentialMap; nothing is re-derived here.
	 *
	 * Order is preserved from propsFor() so the fields appear in the same
	 * sequence whether they are in the visible group or the collapsed one. Any
	 * essential key the type no longer defines is skipped rather than rendered
	 * as a dangling field.
	 */
	function splitPropertyKeys(type, keys) {
		var all = keys || [];
		var essential = [];
		var advanced = [];
		if (!all.length) { return { essential: essential, advanced: advanced }; }

		var map = (META.essentialMap && META.essentialMap[type]) ? META.essentialMap[type] : null;
		if (!map || !map.length) {
			// No classification for this type: show everything rather than hide
			// a field the user may need. The toggle is only rendered when there
			// is something to put behind it.
			return { essential: all.slice(), advanced: [] };
		}

		var i, j, key, isEssential;
		for (i = 0; i < all.length; i++) {
			key = all[i];
			isEssential = false;
			for (j = 0; j < map.length; j++) {
				if (map[j] === key) { isEssential = true; break; }
			}
			if (isEssential) { essential.push(key); } else { advanced.push(key); }
		}
		return { essential: essential, advanced: advanced };
	}

	/**
	 * True when the block already holds a value for any of these keys.
	 *
	 * A saved block that filled in an advanced field must reopen with the
	 * advanced group expanded, otherwise the stored value is still in the JSON
	 * but invisible and the user reasonably concludes their data was lost.
	 */
	function hasAnyValue(b, keys) {
		var i;
		for (i = 0; i < keys.length; i++) {
			if (hasVal(b.properties, keys[i])) { return true; }
		}
		return false;
	}

	/**
	 * True when a properties map holds a usable value for one key.
	 *
	 * Shared by the tiering check above and the warning/JSON readers below, so
	 * "is this field filled in?" is answered identically everywhere. An object
	 * counts as filled when it has at least one own key, which is what a nested
	 * property group looks like once any child has been entered.
	 */
	function hasVal(props, key) {
		if (!props || !Object.prototype.hasOwnProperty.call(props, key)) { return false; }
		var v = props[key];
		if (v && typeof v === 'object') {
			return Object.keys(v).length > 0;
		}
		return !isEmpty(v);
	}

	function normalizeBlock(raw) {
		var b = (raw && typeof raw === 'object') ? raw : {};
		var props = {};
		var k;
		if (b.properties && typeof b.properties === 'object') {
			for (k in b.properties) {
				if (Object.prototype.hasOwnProperty.call(b.properties, k)) {
					props[k] = b.properties[k];
				}
			}
		}
		return {
			id: b.id ? String(b.id) : uid(),
			type: b.type && typeExists(b.type) ? String(b.type) : '',
			name: b.name ? String(b.name) : '',
			enabled: b.enabled === undefined ? true : !!b.enabled,
			properties: props,
			custom_type: b.custom_type ? String(b.custom_type) : '',
			custom_json: b.custom_json ? String(b.custom_json) : ''
		};
	}

	function controlHtml(def, key, value, isNested, options) {
		var type = String(def.type || 'text').toLowerCase();
		var cat = def.category === 'A' ? 'A' : 'B';
		var token = autofillTokenFor(key);
		// Default an empty field to the real resolved value, such as this page's
		// actual URL or title, instead of the raw "{{post_url}}" token. The token
		// is not a URL and a validator rejects it, so showing it as the default
		// left the builder looking broken even though the saved value resolved.
		var resolved = (token && META.placeholderValues && META.placeholderValues[token] !== undefined) ? META.placeholderValues[token] : '';
		if (!isNested && token && isEmpty(value)) {
			value = (resolved !== null && String(resolved).trim() !== '') ? String(resolved) : token;
		}
		var hint = '';
		// Only reachable when no token applied, so the value stayed empty.
		if (cat === 'A' && isEmpty(value) && token && resolved !== null && String(resolved).trim() !== '') {
			hint = String(resolved);
		}
		var ph = hint ? hint : (def.placeholder || '');
		var phAttr = ph ? ' placeholder="' + escHtml(ph) + '"' : '';
		var fieldClass = 'smsw-field smsw-field-' + cat + (hint ? ' smsw-hint' : '');
		var label = '<label class="smsw-field-label">' + escHtml(def.label || key) + '</label>';
		// Short description from the bundled schema.org vocabulary, shown under
		// the control. It is a hint only, never a value, and it is omitted when
		// the vocabulary has no description for this property.
		var tip = (def.hint && !hint) ? '<p class="smsw-field-hint">' + escHtml(def.hint) + '</p>' : '';

		if (type === 'textarea') {
			return '<div class="' + fieldClass + '">' + label +
				'<textarea class="smsw-input" rows="3" data-key="' + escHtml(key) + '"' + phAttr + '>' + escHtml(value) + '</textarea>' + tip + '</div>';
		}

		if (type === 'select') {
			var opts = (options && options.length) ? options : ((def.options && def.options.length) ? def.options : ['', 'True', 'False']);
			var buf = '<div class="' + fieldClass + '">' + label +
				'<select class="smsw-input" data-key="' + escHtml(key) + '">';
			var i;
			for (i = 0; i < opts.length; i++) {
				var ov = String(opts[i]);
				var sel = (ov === String(value === null || value === undefined ? '' : value)) ? ' selected="selected"' : '';
				buf += '<option value="' + escHtml(ov) + '"' + sel + '>' + escHtml(ov === '' ? '-' : ov) + '</option>';
			}
			buf += '</select>' + tip + '</div>';
			return buf;
		}

		var inputType = (['url', 'number', 'date', 'datetime-local'].indexOf(type) !== -1) ? type : 'text';
		return '<div class="' + fieldClass + '">' + label +
			'<input type="' + inputType + '" class="smsw-input" data-key="' + escHtml(key) + '"' + phAttr +
			' value="' + escHtml(value) + '" />' + tip + '</div>';
	}

	var FALLBACK_PLACEHOLDERS = {
		name: 'Sample Name',
		url: 'https://example.com',
		logo: 'https://example.com/logo.png',
		sameas: 'https://example.com/profile',
		price: '19.99',
		pricecurrency: 'USD',
		availability: 'https://schema.org/InStock',
		itemcondition: 'https://schema.org/NewCondition',
		streetaddress: '123 Main Street',
		addresslocality: 'New York',
		addressregion: 'NY',
		postalcode: '10001',
		addresscountry: 'US',
		telephone: '+1-555-0100',
		email: 'info@example.com',
		jobtitle: 'Sample Job Title',
		ratingvalue: '4.5',
		bestrating: '5',
		worstrating: '1',
		ratingcount: '127',
		reviewcount: '89',
		latitude: '40.7128',
		longitude: '-74.0060',
		opens: '09:00',
		closes: '17:00',
		dayofweek: 'Monday',
		caption: 'Sample caption',
		contacttype: 'Customer Service',
		paymentaccepted: 'Cash, Credit Card',
		quantity: '3',
		unittext: 'grams'
	};

	function fallbackPlaceholderFor(key) {
		var k = String(key).toLowerCase();
		if (Object.prototype.hasOwnProperty.call(FALLBACK_PLACEHOLDERS, k)) {
			return FALLBACK_PLACEHOLDERS[k];
		}
		if (k.indexOf('url') !== -1) { return 'https://example.com'; }
		if (k.indexOf('date') !== -1) { return '2026-09-13'; }
		if (k.indexOf('price') !== -1 || k.indexOf('amount') !== -1) { return '19.99'; }
		if (k.indexOf('count') !== -1 || k.indexOf('number') !== -1 || k.indexOf('quantity') !== -1) { return '10'; }
		return 'Sample ' + String(key).replace(/([a-z0-9])([A-Z])/g, '$1 $2');
	}

	function nestedChildDefs(def) {
		var list = [];
		var nestedType = def.nestedType || 'Thing';

		var typeDefs = (META.properties && META.properties[nestedType]) ? META.properties[nestedType] : null;
		if (typeDefs && typeof typeDefs === 'object') {
			var keys = Object.keys(typeDefs);
			for (var i = 0; i < keys.length; i++) {
				var k = keys[i];
				var c = typeDefs[k];
				if (!c || typeof c !== 'object') { continue; }
				var cType = String(c.type || 'text').toLowerCase();
				if (cType === 'nested') { continue; }
				var cDepth = (typeof c.depth !== 'undefined' && c.depth !== null) ? parseInt(c.depth, 10) : 1;
				if (cDepth > 1) { continue; }
				list.push({
					key: k,
					label: k,
					type: cType,
					category: 'B',
					placeholder: c.placeholder || fallbackPlaceholderFor(k),
					options: c.options
				});
			}
			if (list.length) {
				list.sort(function (a, b) {
					var la = String(a.key).toLowerCase();
					var lb = String(b.key).toLowerCase();
					return la < lb ? -1 : (la > lb ? 1 : 0);
				});
				return list;
			}
		}

		var nf = def.nestedFields;
		if (nf && typeof nf === 'object' && Object.prototype.toString.call(nf) !== '[object Array]') {
			var nfKeys = Object.keys(nf);
			for (var ni = 0; ni < nfKeys.length; ni++) {
				var s = nf[nfKeys[ni]] || {};
				var sk = s.key || nfKeys[ni];
				list.push({
					key: sk,
					label: s.label || sk,
					type: s.type || 'text',
					category: 'B',
					placeholder: s.placeholder || '',
					options: s.options
				});
			}
			if (list.length) { return list; }
		}

		var fallback = nestedFieldsFor(nestedType);
		for (var j = 0; j < fallback.length; j++) {
			var fk = fallback[j];
			list.push({
				key: fk,
				label: fk,
				type: (fk === 'url' || fk === 'logo' || fk === 'sameAs') ? 'url' : 'text',
				category: 'B',
				placeholder: fallbackPlaceholderFor(fk)
			});
		}
		return list;
	}

	function nestedEntryHtml(def, value) {
		var obj = (value && typeof value === 'object' && !Array.isArray(value)) ? value : {};
		var nestedType = obj['@type'] || def.nestedType || 'Thing';
		var children = nestedChildDefs(def);
		var rows = '';
		for (var i = 0; i < children.length; i++) {
			var c = children[i];
			var cv = obj[c.key] === undefined || obj[c.key] === null ? '' : obj[c.key];
			rows += controlHtml(c, c.key, typeof cv === 'object' ? '' : cv, true, c.options);
		}
		var typeRow = '<div class="smsw-field smsw-field-B"><label class="smsw-field-label">@type</label>' +
			'<input type="text" class="smsw-input smsw-nested-type" data-key="@type" value="' +
			escHtml(obj['@type'] || nestedType) + '" /></div>';
		return '<div class="smsw-nested-entry">' +
			'<div class="smsw-properties">' + typeRow + rows + '</div>' +
			'<p class="smsw-nested-entry-actions"><button type="button" class="button-link smsw-nested-entry-remove" data-smsw-action="remove-nested-entry">' + escHtml(t('delete')) + '</button></p>' +
			'</div>';
	}

	function nestedHtml(def, key, value) {
		var nestedType = def.nestedType || 'Thing';
		var entries;
		if (Object.prototype.toString.call(value) === '[object Array]') {
			entries = value;
		} else if (value && typeof value === 'object') {
			entries = [value];
		} else {
			entries = [undefined];
		}
		var buf = '';
		for (var i = 0; i < entries.length; i++) {
			buf += nestedEntryHtml(def, entries[i]);
		}
		return '<details class="smsw-nested-form" data-key="' + escHtml(key) + '" data-nested-type="' + escHtml(nestedType) + '">' +
			'<summary>' + escHtml(def.label || key) + ' <span class="smsw-nested-type">' + escHtml(nestedType) + '</span></summary>' +
			'<div class="smsw-nested-entries">' + buf + '</div>' +
			'<p class="smsw-nested-actions"><button type="button" class="button smsw-nested-add" data-smsw-action="add-nested-entry">Add Another</button></p>' +
				'</details>';
	}

	function createBuilder(rootEl) {
		instanceCounter += 1;
		var myId = String(instanceCounter);

		var opts = {
			blocksSel: rootEl.attr('data-blocks-container') || '',
			hiddenSel: rootEl.attr('data-hidden-input') || '',
			addSel: rootEl.attr('data-add-button') || '',
			previewSel: rootEl.attr('data-preview-target') || '',
			noticeSel: rootEl.attr('data-notice-target') || '',
			postId: parseInt(rootEl.attr('data-post-id'), 10) || 0
		};

		// The metabox node is stamped with this builder's instance id. Every
		// document-delegated handler re-resolves the node from the clicked
		// element and ignores clicks that belong to another instance, so a
		// Gutenberg remount can never double-fire stale handlers.
		rootEl.attr('data-smsw-instance', myId);

		var root = rootEl;
		var $blocks = opts.blocksSel ? root.find(opts.blocksSel).first() : $();
		var $hidden = opts.hiddenSel ? root.find(opts.hiddenSel).first() : $();
		var $add = opts.addSel ? root.find(opts.addSel).first() : $();
		var blocks = [];
		var defsCache = {};
		var pendingDefs = {};

		// Re-resolve the node and its controls after any remount.
		function refreshRefs(freshRoot) {
			if (!freshRoot || !freshRoot.length) { return; }
			root = freshRoot;
			$blocks = opts.blocksSel ? root.find(opts.blocksSel).first() : $();
			$hidden = opts.hiddenSel ? root.find(opts.hiddenSel).first() : $();
			$add = opts.addSel ? root.find(opts.addSel).first() : $();
		}

		// Every document-delegated handler re-resolves the container from the
		// clicked element, then asks claimRoot() whether the click is its own.
		// claimRoot() is the single gate for that: it rejects a node belonging to
		// a different builder and re-resolves the refs so a handler never writes
		// into a detached node.
		//
		// A React remount (Gutenberg re-creates the meta box markup) rebuilds the
		// container from the original server HTML, which drops data-smsw-instance
		// because it is stamped by JS and never printed by PHP. Treating a missing
		// stamp as "not mine" would silently swallow every click after a remount,
		// so an unstamped node is re-claimed by this builder. A node stamped with a
		// different instance id still belongs to another builder and is rejected.
		function claimRoot(freshRoot) {
			if (!freshRoot || !freshRoot.length) { return false; }
			var stamped = freshRoot.attr('data-smsw-instance');
			if (stamped == null) {
				freshRoot.attr('data-smsw-instance', myId);
			} else if (String(stamped) !== myId) {
				return false;
			}
			refreshRefs(freshRoot);
			return true;
		}

		try {
			var rawBlocks = root.attr('data-blocks');
			var parsed = rawBlocks ? JSON.parse(rawBlocks) : [];
			if (Object.prototype.toString.call(parsed) === '[object Array]') {
				for (var pi = 0; pi < parsed.length; pi++) {
					blocks.push(normalizeBlock(parsed[pi]));
				}
			}
		} catch (e) {
			blocks = [];
		}

		function ruleKeyHas(props, key) {
			if (hasVal(props, key)) { return true; }
			var idx = String(key).indexOf('_');
			if (idx > 0) {
				var nested = props[String(key).slice(0, idx)];
				if (nested && typeof nested === 'object') {
					if (Object.prototype.toString.call(nested) === '[object Array]') {
						for (var x = 0; x < nested.length; x++) {
							if (nested[x] && typeof nested[x] === 'object' && hasVal(nested[x], String(key).slice(idx + 1))) { return true; }
						}
						return false;
					}
					if (hasVal(nested, String(key).slice(idx + 1))) { return true; }
				}
			}
			return false;
		}

		function computeWarnings(b) {
			var list = [];
			if (!b.enabled || isCustomType(b.type)) { return list; }
			var rule = (META.warningRules || {})[b.type];
			if (!rule) { return list; }
			var i;
			var allKeys = rule.all || [];
			var anyKeys = rule.any || [];
			var allOk = true;
			var anyOk = anyKeys.length === 0;
			for (i = 0; i < allKeys.length; i++) {
				if (!ruleKeyHas(b.properties, allKeys[i])) { allOk = false; break; }
			}
			for (i = 0; i < anyKeys.length; i++) {
				if (ruleKeyHas(b.properties, anyKeys[i])) { anyOk = true; break; }
			}
			if ((allKeys.length > 0 && !allOk) || (anyKeys.length > 0 && !anyOk)) {
				if (rule.message) { list.push(rule.message); }
			}
			return list;
		}

		/**
		 * Renders one group of property fields, preserving propsFor() order.
		 *
		 * Shared by the essential and advanced groups so a nested property or a
		 * category-A placeholder is rendered identically wherever it lands.
		 */
		function renderFieldGroup(keys, defs, b, extraClass) {
			if (!keys.length) { return ''; }
			var rows = '';
			var i, key, def, val;
			for (i = 0; i < keys.length; i++) {
				key = keys[i];
				def = defs[key] || {};
				val = b.properties[key];
				if (def.type === 'nested') {
					rows += nestedHtml(def, key, val);
				} else {
					rows += controlHtml(def, key, (val === null || val === undefined || typeof val === 'object') ? '' : val);
				}
			}
			return '<div class="smsw-property-fields ' + extraClass + '">' + rows + '</div>';
		}

		/**
		 * Renders the collapsible advanced group plus its toggle.
		 *
		 * The toggle is a real button with aria-expanded and aria-controls so it
		 * is reachable by keyboard and announced by a screen reader, and the
		 * panel it controls is hidden with the HTML hidden attribute rather than
		 * a CSS class, so collapsed fields are genuinely absent from the layout.
		 *
		 * The field markup is always printed, hidden or not, so a value typed
		 * into an advanced field is never dropped by readBlockFromDom() when the
		 * block is re-read. Visibility is a presentation concern only.
		 */
		/**
		 * Builds the advanced-toggle label, e.g. "Show more fields (37)".
		 *
		 * Shared by the renderer and the click handler so the two can never
		 * disagree about the wording or about how many fields are hidden. The
		 * count is always the number of advanced fields for that block, and the
		 * collapse label deliberately carries no count: once open, the number
		 * of visible fields is self-evident from the panel itself.
		 *
		 * @param {boolean} wantOpen True for the label shown while collapsed
		 *                             (the action of clicking is to open).
		 * @param {number}  count    Number of advanced fields for the block.
		 * @return {string} Localised label.
		 */
		function advancedToggleLabel(wantOpen, count) {
			if (!wantOpen) {
				return t('showFewerFields') || t('showMoreFields');
			}

			var label = String(t('moreFieldsCount') || t('showMoreFields') || '');

			// A translation may legitimately drop the %d placeholder, so fall
			// back to appending the count rather than rendering a literal "%d".
			if (label.indexOf('%d') !== -1) {
				return label.replace('%d', String(count));
			}

			return label ? (label + ' (' + String(count) + ')') : String(count);
		}

		function renderAdvancedGroup(keys, defs, b, expanded) {
			var panelId = 'smsw-advanced-' + b.id;
			var label = advancedToggleLabel(!expanded, keys.length);

			return '<div class="smsw-advanced">' +
				'<button type="button" class="smsw-advanced-toggle" data-smsw-action="toggle-advanced"' +
				// The top-level advanced field count, captured here from the same
				// keys array the panel is built from. The click handler reuses it
				// instead of re-counting the DOM, because a descendant selector
				// also matches the fields a nested property renders inside its
				// own <details>, which made the number jump on first toggle.
				' data-smsw-advanced-count="' + String(keys.length) + '"' +
				' aria-expanded="' + (expanded ? 'true' : 'false') + '"' +
				' aria-controls="' + escHtml(panelId) + '">' +
				'<span class="smsw-advanced-caret"' + (expanded ? ' is-open' : '') + ' aria-hidden="true">&#9662;</span>' +
				'<span class="smsw-advanced-toggle-text">' + escHtml(label) + '</span>' +
				'</button>' +
				'<div class="smsw-advanced-panel" id="' + escHtml(panelId) + '"' + (expanded ? '' : ' hidden') + '>' +
				renderFieldGroup(keys, defs, b, 'smsw-property-advanced') +
				'</div></div>';
		}

		function blockBodyHtml(b) {
			var out = '';
			out += '<div class="smsw-toggle"><label><input type="checkbox" class="smsw-enabled"' +
				(b.enabled ? ' checked="checked"' : '') + ' /> ' + escHtml(t('enabled')) + '</label></div>';

			// An empty type means the user has not chosen anything yet. Show the
			// search placeholder and no properties until a real type is selected.
			var hasType = !!(b.type && typeExists(b.type));

			out += '<div class="smsw-type-autocomplete">' +
				'<label class="smsw-field-label">' + escHtml(t('schemaType')) + '</label>' +
				'<div class="smsw-type-select-wrap">' +
				'<input type="text" class="smsw-type-input" value="' + escHtml(labelForType(b.type) || '') + '" placeholder="' + escHtml(t('selectType') || t('schemaType')) + '" autocomplete="off" />' +
				'<input type="hidden" data-block-role="type" value="' + escHtml(b.type) + '" />' +
				'<div class="smsw-type-dropdown" hidden></div>' +
				'</div></div>';

			if (isCustomType(b.type)) {
				out += '<div class="smsw-field smsw-field-A"><label class="smsw-field-label">' + escHtml(t('customType')) + '</label>' +
					'<input type="text" class="smsw-input" data-block-role="custom-type" value="' + escHtml(b.custom_type) + '" placeholder="MyCustomType" /></div>';
				out += '<div class="smsw-field smsw-field-A"><label class="smsw-field-label">' + escHtml(t('customJson')) + '</label>' +
					'<textarea class="smsw-input" rows="10" data-block-role="custom-json" placeholder=\'{ "@type": "Thing", "name": "" }\'>' +
					escHtml(b.custom_json) + '</textarea></div>';
			} else if (!hasType) {
				out += '<p class="smsw-empty-props">' + escHtml(t('selectType') || t('schemaType')) + '</p>';
			} else {
				var defs = propsFor(b.type);
				out += '<h4 class="smsw-properties-title">' + escHtml(t('properties')) + '</h4>';
				if (!defs) {
					out += '<p class="smsw-empty-props">' + escHtml(t('loadingProperties')) + '</p>';
				} else {
					var keys = Object.keys(defs);
					var groups = splitPropertyKeys(b.type, keys);
					var expandAdvanced = groups.advanced.length > 0 && hasAnyValue(b, groups.advanced);

					out += renderFieldGroup(groups.essential, defs, b, 'smsw-property-essential');
					if (groups.advanced.length) {
						out += renderAdvancedGroup(groups.advanced, defs, b, expandAdvanced);
					}
				}
			}

			var warns = computeWarnings(b);
			if (warns.length) {
				out += '<div class="smsw-block-warnings">';
				for (var wi = 0; wi < warns.length; wi++) {
					out += '<p class="smsw-block-warning">' + escHtml(t('warningPrefix')) + ' ' + escHtml(warns[wi]) + '</p>';
				}
				out += '</div>';
			}
			return out;
		}		function blockHtml(b, loadingProps) {
			var warns = computeWarnings(b);
			return '<div class="smsw-block' + (loadingProps ? ' smsw-block-loading' : '') + '" data-block-id="' + escHtml(b.id) + '">' +
				'<div class="smsw-block-header">' +
					'<button type="button" class="smsw-block-chevron" data-smsw-action="collapse" aria-label="Toggle">\u25BE</button>' +
					'<span class="smsw-block-title-wrap">' +
						'<span class="smsw-block-title" data-name="' + escHtml(b.name) + '">' + escHtml(b.name || t('newBlock')) + '</span>' +
						'<input type="text" class="smsw-name-input" value="' + escHtml(b.name) + '" placeholder="' + escHtml(t('newBlock')) + '" hidden />' +
						'<button type="button" class="button-link smsw-name-edit" aria-label="Rename">\u270E</button>' +
						'<span class="smsw-block-type-badge">' + escHtml(labelForType(b.type) || b.type) + '</span>' +
					'</span>' +
					'<span class="smsw-block-warning-dot"' + (warns.length ? '' : ' hidden') + '></span>' +
					'<span class="smsw-block-toolbar">' +
						'<button type="button" class="button smsw-header-preview" data-smsw-action="header-preview">' + escHtml(t('preview')) + '</button>' +
						'<button type="button" class="button smsw-header-validate" data-smsw-action="header-validate">' + escHtml(t('validateThis')) + '</button>' +
						'<button type="button" class="button-link smsw-duplicate" data-smsw-action="duplicate">' + escHtml(t('duplicate')) + '</button>' +
						'<button type="button" class="button-link smsw-delete" data-smsw-action="delete">' + escHtml(t('delete')) + '</button>' +
					'</span>' +
				'</div>' +
				'<div class="smsw-block-body">' + blockBodyHtml(b) + '</div>' +
				'</div>';
		}

		function renderAll() {
			var html = '';
			var i;
			for (i = 0; i < blocks.length; i++) {
				var b = blocks[i];
				var loading = (!isCustomType(b.type) && !propsFor(b.type));
				html += blockHtml(b, loading);
			}
			$blocks.html(html);
		}

		function readBlockFromDom($el, fallback) {
			var fb = fallback || normalizeBlock(null);
			var type = $el.find('[data-block-role="type"]').first().val() || fb.type || '';
			var b = {
				id: $el.attr('data-block-id') || fb.id || uid(),
				type: type,
				name: '',
				enabled: $el.find('.smsw-enabled').first().prop('checked'),
				properties: {},
				custom_type: '',
				custom_json: ''
			};

			var $nameInput = $el.find('.smsw-name-input').first();
			if ($nameInput.length && !$nameInput.prop('hidden')) {
				b.name = $nameInput.val() || '';
			} else {
				b.name = $el.find('.smsw-block-title').first().attr('data-name') || '';
			}

			if (isCustomType(type)) {
				b.custom_type = $el.find('[data-block-role="custom-type"]').first().val() || '';
				b.custom_json = $el.find('[data-block-role="custom-json"]').first().val() || '';
			} else if ($el.hasClass('smsw-block-loading')) {
				// Only carry values forward while the block still waits for the
				// definitions of the SAME type. When the type changed, the old
				// property bag must never be resurrected into the preview.
				b.properties = (fb.type && String(fb.type) === String(type)) ? (fb.properties || {}) : {};
			} else {
				$el.find('.smsw-input[data-key]').each(function () {
					var $input = $(this);
					var key = $input.attr('data-key');
					if (!key) { return; }
					var $det = $input.closest('details.smsw-nested-form');
					var val = $input.val();
					if (Object.prototype.toString.call(val) === '[object Array]') {
						val = val.join(', ');
					}
					if ($det.length) {
						var nk = $det.attr('data-key');
						if (!nk) { return; }
						var $entry = $input.closest('.smsw-nested-entry');
						var ei = $det.find('.smsw-nested-entry').index($entry);
						if (ei < 0) { return; }
						if (Object.prototype.toString.call(b.properties[nk]) !== '[object Array]') {
							b.properties[nk] = (b.properties[nk] && typeof b.properties[nk] === 'object') ? [b.properties[nk]] : [];
						}
						while (b.properties[nk].length <= ei) {
							b.properties[nk].push({});
						}
						if (!isEmpty(val)) { b.properties[nk][ei][key] = val; }
						return;
					}
					if (!isEmpty(val)) { b.properties[key] = val; }
				});
			}
			// A property is only valid while the current type actually renders
			// it as a field. Any key the current type does not define is stale
			// data from a previously selected type, so it is dropped here for
			// every branch above (DOM read, carried bag, or fallback). This is
			// the guarantee that the preview, the hidden JSON input, and the
			// saved output can never show a property of an older type.
			var liveDefs = isCustomType(type) ? null : (propsFor(type) || defsCache[type] || null);
			if (liveDefs) {
				var allowed = {};
				var allowedCount = 0;
				for (var alk in liveDefs) {
					if (Object.prototype.hasOwnProperty.call(liveDefs, alk)) {
						allowed[alk] = true;
						allowedCount++;
					}
				}
				// Never filter against an empty definition set: a failed
				// definitions load must not wipe existing values.
				if (allowedCount > 0) {
					for (var dk in b.properties) {
						if (Object.prototype.hasOwnProperty.call(b.properties, dk) && !allowed[dk]) {
							delete b.properties[dk];
						}
					}
				}
			}
			for (var nk2 in b.properties) {
				if (!Object.prototype.hasOwnProperty.call(b.properties, nk2)) { continue; }
				var nv = b.properties[nk2];
				if (!nv || typeof nv !== 'object') { continue; }
				if (Object.prototype.toString.call(nv) === '[object Array]') {
					var filled = [];
					for (var ei2 = 0; ei2 < nv.length; ei2++) {
						var ev = nv[ei2];
						if (ev && typeof ev === 'object' && Object.prototype.toString.call(ev) !== '[object Array]' && hasNestedData(ev)) {
							filled.push(ev);
						}
					}
					if (!filled.length) {
						delete b.properties[nk2];
					} else if (filled.length === 1) {
						b.properties[nk2] = filled[0];
					} else {
						b.properties[nk2] = filled;
					}
				} else if (!hasNestedData(nv)) {
					delete b.properties[nk2];
				}
			}
			return normalizeBlock(b);
		}

		function syncFromDom() {
			var collected = [];
			$blocks.children('.smsw-block').each(function (i) {
				collected.push(readBlockFromDom($(this), blocks[i]));
			});
			blocks = collected;
			return blocks;
		}

		function blockToJsonLd(b) {
			if (isCustomType(b.type)) {
				var obj = null;
				try { obj = JSON.parse(b.custom_json || 'null'); } catch (e) { obj = null; }
				if (!obj || typeof obj !== 'object' || Object.prototype.toString.call(obj) === '[object Array]') {
					return null;
				}
				if (!obj['@context']) { obj['@context'] = 'https://schema.org'; }
				if (!obj['@type'] && b.custom_type) { obj['@type'] = b.custom_type; }
				return obj;
			}
			var out = { '@context': 'https://schema.org', '@type': b.type };
			for (var key in b.properties) {
				if (!Object.prototype.hasOwnProperty.call(b.properties, key)) { continue; }
				var v = b.properties[key];
				if (/_json$/.test(key) && typeof v === 'string') {
					try {
						var dec = JSON.parse(v);
						if (dec && typeof dec === 'object') {
							var cleanedDec = cleanJsonLdValue(dec);
							if (cleanedDec !== null) {
								out[key.replace(/_json$/, '')] = cleanedDec;
							}
						}
					} catch (e2) {}
					continue;
				}
				var cleaned = cleanJsonLdValue(v);
				if (cleaned === null) { continue; }
				out[key] = cleaned;
			}
			return out;
		}

		function buildMergedPreview() {
			// Callers (sync → refreshPreview) have already synced the DOM, so
			// no second syncFromDom() walk is needed here.
			var items = [];
			for (var i = 0; i < blocks.length; i++) {
				if (!blocks[i].enabled) { continue; }
				var obj = blockToJsonLd(blocks[i]);
				if (obj) { items.push(obj); }
			}
			if (!items.length) { return null; }
			if (items.length === 1) { return items[0]; }
			return { '@context': 'https://schema.org', '@graph': items };
		}

		function previewTarget() {
			if (opts.previewSel) {
				var $t = root.find(opts.previewSel).first();
				if ($t.length) { return $t; }
			}
			return root.find('.smsw-json-preview').first();
		}

		function previewPanel() {
			var $target = previewTarget();
			if (!$target.length) { return $(); }
			return $target.closest('.smsw-preview-wrap, .smsw-preview-panel').first();
		}

		// The lower preview box carries its own copy button, exactly like the
		// floating preview box, so the code can be copied right on the box.
		function ensurePanelCopyButton() {
			var $panel = previewPanel();
			if (!$panel.length) { return; }
			if ($panel.find('[data-smsw-action="copy-panel-preview"]').length) { return; }
			var label = t('previewCopy') || 'Copy';
			$panel.append(
				'<button type="button" class="smsw-preview-copy" data-smsw-action="copy-panel-preview" aria-label="' + escHtml(label) + '">' +
					escHtml(label) +
				'</button>'
			);
		}

		function refreshFloatPreviews() {
			$blocks.children('.smsw-block').each(function () {
				var $blockEl = $(this);
				var $float = $blockEl.children('.smsw-float-preview-area');
				if (!$float.length) { return; }
				var fi = $blocks.children('.smsw-block').index($blockEl);
				var fobj = (typeof blocks[fi] !== 'undefined') ? blockToJsonLd(blocks[fi]) : null;
				$float.find('.smsw-float-preview-code').first().text(fobj ? JSON.stringify(fobj, null, 2) : t('previewEmpty'));
			});
		}

		function refreshPreview() {
			// Refresh any open floating preview first: it must stay live even on
			// containers that have no lower preview panel (the Site Schema tab
			// has none, so the old early return left the float preview stale).
			refreshFloatPreviews();
			var $target = previewTarget();
			if (!$target.length) { return; }
			var merged = buildMergedPreview();
			var text = merged ? JSON.stringify(merged, null, 2) : '';
			if (!text) { text = t('previewEmpty'); }
			$target.val(text);
			root.find('.smsw-copy-test-btn').prop('disabled', !merged);
		}

		function sync() {
			syncFromDom();
			if ($hidden.length) {
				$hidden.val(JSON.stringify(blocks));
			}
			refreshPreview();
			// Re-open the floating preview after a re-render removed it.
			restoreFloatPreview();
			// The "Go to Schema Blocks" side panel badge mirrors this
			// builder's block count, so add/duplicate/delete all keep it
			// live. Screens without the badge no-op inside the helper.
			updateSidePanelCount(blocks.length);
		}

		var syncDebounced = debounce(sync, 250);

		function showNotice(sel, msg) {
			var $n = sel ? root.find(sel).first() : root.find('.smsw-copy-notice').first();
			if (!$n.length) { return; }
			$n.html(escHtml(msg) + ' <button type="button" class="button-link smsw-copy-dismiss">' + escHtml(t('copyDismiss')) + '</button>');
			$n.prop('hidden', false);
		}

		function addBlock(type) {
			syncFromDom();
			var chosen = typeExists(type) ? type : '';
			var b = normalizeBlock({ id: uid(), type: chosen, name: '', enabled: true, properties: {} });
			blocks.push(b);
			renderAll();
			var $el = $blocks.children('.smsw-block[data-block-id="' + b.id + '"]');
			$el.removeClass('is-collapsed');
			sync();
			if (!isCustomType(chosen) && typeExists(chosen) && !propsFor(chosen)) {
				ensureProps(chosen, function () {
					renderAll();
					sync();
				});
			}
		}

		function ensureProps(type, done) {
			if (isCustomType(type) || propsFor(type)) {
				done();
				return;
			}
			if (defsCache[type]) {
				META.properties[type] = defsCache[type];
				done();
				return;
			}
			if (pendingDefs[type]) {
				pendingDefs[type].push(done);
				return;
			}
			pendingDefs[type] = [done];
			$.post(META.ajaxUrl, {
				action: 'smsw_type_properties',
				nonce: META.nonce,
				type: type
			}).done(function (resp) {
				var defs = (resp && resp.success && resp.data && resp.data.properties) ? resp.data.properties : {};
				defsCache[type] = defs;
				if (META.properties) { META.properties[type] = defs; }
				// The essential/advanced split arrives with the type it belongs
				// to, so the page never has to ship a classification for all
				// 928 schema.org types. Absent or empty means "no split known
				// yet", which splitPropertyKeys() treats as "show every field".
				if (resp && resp.success && resp.data && resp.data.essential && resp.data.essential.length) {
					if (!META.essentialMap) { META.essentialMap = {}; }
					META.essentialMap[type] = resp.data.essential;
				}
			}).fail(function () {
				defsCache[type] = {};
			}).always(function () {
				var queue = pendingDefs[type] || [];
				delete pendingDefs[type];
				for (var i = 0; i < queue.length; i++) { queue[i](); }
			});
		}

		function currentTypeOf($block) {
			return $block.find('[data-block-role="type"]').first().val() || '';
		}

		/**
		 * Reset a block so it only carries data for the new type.
		 *
		 * Changing a block's schema type must never keep values from the
		 * previously selected type: the property bag, the custom type name,
		 * and the custom JSON are all cleared so the block is rebuilt from
		 * the new type only.
		 *
		 * @param {Object} b    Block object.
		 * @param {string} type New schema @type.
		 * @return {Object} The reset block.
		 */
		function resetBlockForType(b, type) {
			b.type = String(type);
			b.properties = {};
			b.custom_type = '';
			b.custom_json = '';
			return b;
		}

		function chooseType($wrap, type) {
			var $block = $wrap.closest('.smsw-block');
			var $input = $wrap.find('.smsw-type-input').first();
			$wrap.find('.smsw-type-dropdown').first().prop('hidden', true);
			$input.removeData('smsw-idx');
			if (!typeExists(type)) {
				$input.val(labelForType(currentTypeOf($block)) || currentTypeOf($block));
				return;
			}
			syncFromDom();
			var idx = $blocks.children('.smsw-block').index($block);
			if (idx < 0 || !blocks[idx]) { return; }
			// A type change starts this block from a clean slate. Every value
			// collected from the previous type is discarded before the new
			// type renders, so no key from the old type can reach the preview,
			// the hidden JSON input, or the saved data.
			if (String(blocks[idx].type || '') !== String(type)) {
				resetBlockForType(blocks[idx], type);
			}
			blocks[idx].type = type;
			var loading = (!isCustomType(type) && !propsFor(type));
			$block.replaceWith(blockHtml(blocks[idx], loading));
			sync();
			if (loading) {
				ensureProps(type, function () {
					renderAll();
					sync();
				});
			}
		}

		function highlight($input, $items, idx) {
			$items.removeClass('smsw-type-highlight');
			$items.eq(idx).addClass('smsw-type-highlight');
			$input.data('smsw-idx', idx);
			var el = $items.eq(idx)[0];
			if (!el) { return; }
			var dd = $(el).closest('.smsw-type-dropdown')[0];
			if (!dd) { return; }
			var r = el.getBoundingClientRect();
			var dRect = dd.getBoundingClientRect();
			if (r.top < dRect.top) {
				dd.scrollTop += r.top - dRect.top;
			} else if (r.bottom > dRect.bottom) {
				dd.scrollTop += r.bottom - dRect.bottom;
			}
		}

		function openDropdown($input) {
			var $dd = $input.closest('.smsw-type-select-wrap').find('.smsw-type-dropdown').first();
			var q = String($input.val() || '').toLowerCase();
			var entries = typeEntriesCached();
			var html = '';
			var total = 0;
			for (var i = 0; i < entries.length; i++) {
				var items = [];
				for (var j = 0; j < entries[i].items.length; j++) {
					var it = entries[i].items[j];
					if (!q || it.label.toLowerCase().indexOf(q) !== -1 || it.value.toLowerCase().indexOf(q) !== -1) {
						items.push(it);
					}
				}
				if (items.length) {
					html += '<div class="smsw-type-group">' + escHtml(entries[i].label) + '</div>';
					for (var k = 0; k < items.length; k++) {
						html += '<button type="button" class="smsw-type-item" data-value="' + escHtml(items[k].value) + '">' + escHtml(items[k].label) + '</button>';
						total++;
					}
				}
			}
			if (!total) {
				html += '<div class="smsw-type-no-results">' + escHtml(t('noTypes')) + '</div>';
			}
			$dd.html(html).prop('hidden', false);
			$input.data('smsw-idx', -1);
		}		$(document).on('click.smsw', '.smsw-metabox [data-smsw-action="collapse"]', function (e) {
			var root = $(this).closest('.smsw-metabox');
			if ( ! claimRoot( root ) ) { return; }
			refreshRefs( root );
			e.preventDefault();
			$(this).closest('.smsw-block').toggleClass('is-collapsed');
		});

		// Expand/collapse the advanced property group.
		//
		// This only flips presentation: the fields are always in the DOM, so
		// no value can be lost by collapsing. aria-expanded and the label both
		// stay in sync with the panel's real hidden state, which is what a
		// screen reader and the caret rotation both read.
		$(document).on('click.smsw', '.smsw-metabox [data-smsw-action="toggle-advanced"]', function (e) {
			var root = $(this).closest('.smsw-metabox');
			if ( ! claimRoot( root ) ) { return; }
			e.preventDefault();

			var $toggle = $(this);
			var panelId = $toggle.attr('aria-controls');
			var $panel = panelId ? root.find('#' + panelId).first() : $toggle.nextAll('.smsw-advanced-panel').first();
			if (!$panel.length) { return; }

			var willOpen = $panel.prop('hidden') === true || $panel.attr('hidden') === '';

			$panel.prop('hidden', !willOpen);
			if (willOpen) {
				$panel.removeAttr('hidden');
			} else {
				$panel.attr('hidden', 'hidden');
			}

			$toggle.attr('aria-expanded', willOpen ? 'true' : 'false');
			$toggle.find('.smsw-advanced-caret').toggleClass('is-open', willOpen);

			// Rebuild the label from the same helper the renderer uses, so the
			// wording and the field count can never drift between the initial
			// render and a later toggle. The count is the one the renderer
			// recorded, not a fresh DOM tally: nested properties render their
			// own .smsw-field descendants, so counting them here reported a
			// different number than the panel actually has.
			var $text = $toggle.find('.smsw-advanced-toggle-text');
			if ($text.length) {
				var advCount = parseInt($toggle.attr('data-smsw-advanced-count'), 10);
				if (isNaN(advCount)) {
					advCount = $panel.find('.smsw-property-advanced').children().length;
				}
				$text.text(advancedToggleLabel(willOpen ? false : true, advCount));
			}

			// Keep the side panel badge and the hidden JSON input in step.
			sync();
		});

		$(document).on('click.smsw', '.smsw-metabox [data-smsw-action="duplicate"]', function (e) {
			var root = $(this).closest('.smsw-metabox');
			if ( ! claimRoot( root ) ) { return; }
			refreshRefs( root );
			e.preventDefault();
			syncFromDom();
			var $el = $(this).closest('.smsw-block');
			var idx = $blocks.children('.smsw-block').index($el);
			if (idx < 0 || !blocks[idx]) { return; }
			var copy = JSON.parse(JSON.stringify(blocks[idx]));
			copy.id = uid();
			copy.name = copy.name ? copy.name + ' (copy)' : '';
			blocks.splice(idx + 1, 0, copy);
			renderAll();
			sync();
		});

		$(document).on('click.smsw', '.smsw-metabox [data-smsw-action="delete"]', function (e) {
			var root = $(this).closest('.smsw-metabox');
			if ( ! claimRoot( root ) ) { return; }
			refreshRefs( root );
			e.preventDefault();
			if (!window.confirm(t('confirmDelete'))) { return; }
			syncFromDom();
			var idx = $blocks.children('.smsw-block').index($(this).closest('.smsw-block'));
			if (idx < 0) { return; }
			blocks.splice(idx, 1);
			renderAll();
			sync();
		});

		$(document).on('click.smsw', '.smsw-metabox [data-smsw-action="add-nested-entry"]', function (e) {
			var root = $(this).closest('.smsw-metabox');
			if ( ! claimRoot( root ) ) { return; }
			refreshRefs( root );
			e.preventDefault();
			var $det = $(this).closest('details.smsw-nested-form');
			if (!$det.length) { return; }
			var nk = $det.attr('data-key');
			var $block = $det.closest('.smsw-block');
			var defs = propsFor(currentTypeOf($block));
			var def = (defs && defs[nk]) ? defs[nk] : { nestedType: $det.attr('data-nested-type') || 'Thing' };
			$det.find('.smsw-nested-entries').first().append(nestedEntryHtml(def, undefined));
			syncDebounced();
		});

		$(document).on('click.smsw', '.smsw-metabox [data-smsw-action="remove-nested-entry"]', function (e) {
			var root = $(this).closest('.smsw-metabox');
			if ( ! claimRoot( root ) ) { return; }
			refreshRefs( root );
			e.preventDefault();
			var $entry = $(this).closest('.smsw-nested-entry');
			var $det = $entry.closest('details.smsw-nested-form');
			$entry.remove();
			if ($det.length && !$det.find('.smsw-nested-entry').length) {
				var nk = $det.attr('data-key');
				var $block = $det.closest('.smsw-block');
				var defs = propsFor(currentTypeOf($block));
				var def = (defs && defs[nk]) ? defs[nk] : null;
				if (def) {
					$det.find('.smsw-nested-entries').first().append(nestedEntryHtml(def, undefined));
				}
			}
			syncDebounced();
		});

				/* Name-edit / type-select handlers remain jQuery-delegated: they target
		   inputs (change) and keydown, not click dispatch, and React does not
		   intercept change/keydown the same way it intercepts click. */
		$(document).on('click.smsw', '.smsw-metabox .smsw-name-edit', function (e) {
			var root = $(this).closest('.smsw-metabox');
			if ( ! claimRoot( root ) ) { return; }
			e.preventDefault();
			$(this).closest('.smsw-block').find('.smsw-name-input').first().prop('hidden', false).trigger('focus');
		});

		$(document).on('change.smsw', '.smsw-metabox .smsw-name-input', function () {
			var root = $(this).closest('.smsw-metabox');
			if ( ! claimRoot( root ) ) { return; }
			var $input = $(this);
			var $title = $input.closest('.smsw-block').find('.smsw-block-title').first();
			$title.attr('data-name', $input.val() || '');
			$title.text($input.val() || t('newBlock'));
			$input.prop('hidden', true);
			syncDebounced();
		});

		$(document).on('focus.smsw click.smsw input.smsw', '.smsw-metabox .smsw-type-input', function () {
			var root = $(this).closest('.smsw-metabox');
			if ( ! claimRoot( root ) ) { return; }
			refreshRefs( root );
			openDropdown($(this));
		});

		$(document).on('keydown.smsw', '.smsw-metabox .smsw-type-input', function (e) {
			var root = $(this).closest('.smsw-metabox');
			if ( ! claimRoot( root ) ) { return; }
			refreshRefs( root );
			var $input = $(this);
			var $wrap = $input.closest('.smsw-type-select-wrap');
			var $dd = $wrap.find('.smsw-type-dropdown').first();
			if ($dd.prop('hidden') && e.key !== 'Escape') {
				openDropdown($input);
				$dd = $wrap.find('.smsw-type-dropdown').first();
			}
			var $items = $dd.find('.smsw-type-item');
			var idx = $input.data('smsw-idx');
			idx = (typeof idx === 'number') ? idx : -1;
			if (e.key === 'ArrowDown' && $items.length) {
				e.preventDefault();
				highlight($input, $items, Math.min(idx + 1, $items.length - 1));
			} else if (e.key === 'ArrowUp' && $items.length) {
				e.preventDefault();
				highlight($input, $items, Math.max(idx - 1, 0));
			} else if (e.key === 'Enter') {
				e.preventDefault();
				if (idx >= 0 && $items.eq(idx).length) {
					chooseType($wrap, $items.eq(idx).attr('data-value'));
				}
			} else if (e.key === 'Escape') {
				$dd.prop('hidden', true);
				$input.removeData('smsw-idx');
				$input.val(labelForType(currentTypeOf($input.closest('.smsw-block'))) || currentTypeOf($input.closest('.smsw-block')));
			}
		});

		$(document).on('mousedown.smsw', '.smsw-metabox .smsw-type-item', function (e) {
			var root = $(this).closest('.smsw-metabox');
			if ( ! claimRoot( root ) ) { return; }
			refreshRefs( root );
			e.preventDefault();
			chooseType($(this).closest('.smsw-type-select-wrap'), $(this).attr('data-value'));
		});

		$(document).on('input.smsw change.smsw', '.smsw-metabox .smsw-input, .smsw-metabox .smsw-enabled', function () {
			var root = $(this).closest('.smsw-metabox');
			if ( ! claimRoot( root ) ) { return; }
			syncDebounced();
		});

		var $form = root.closest('form');
		if ($form.length) {
			$form.on('submit.smsw', function () { sync(); });
		}

		$(document).on('click.smsw', '.smsw-metabox .smsw-copy-test-btn', function (e) {
			var root = $(this).closest('.smsw-metabox');
			if ( ! claimRoot( root ) ) { return; }
			refreshRefs( root );
			e.preventDefault();
			var $btn = $(this);
			var mode = $btn.attr('data-smsw-copy') || 'code';
			var $prev = $btn.attr('data-smsw-preview') ? root.find($btn.attr('data-smsw-preview')).first() : previewTarget();
			var text = $prev.length ? String($prev.val() || '') : '';
			var noticeSel = $btn.attr('data-smsw-notice') || opts.noticeSel || '';
			if (!text || text === t('previewEmpty')) {
				showNotice(noticeSel, t('copyEmpty'));
				return;
			}
			if (mode === 'google-url') {
				var pv = META.placeholderValues || {};
				var url = META.permalink || pv.post_url || pv.site_url || '';
				window.open('https://search.google.com/test/rich-results' + (url ? '?url=' + encodeURIComponent(url) : ''), '_blank');
				return;
			}
			if (mode === 'google') {
				copyText(text, function (ok) {
					showNotice(noticeSel, ok ? t('validateBlockOk') : t('copyManual'));
					window.open('https://search.google.com/test/rich-results', '_blank');
				});
				return;
			}
			if (mode === 'schemaorg') {
				copyText(text, function (ok) {
					showNotice(noticeSel, ok ? t('copySchemaOk') : t('copyManual'));
					window.open('https://validator.schema.org/', '_blank');
				});
				return;
			}
			copyText(text, function (ok) {
				showNotice(noticeSel, ok ? t('copyOk') : t('copyManual'));
			});
		});

		function closeFloatPreviews() {
			$blocks.children('.smsw-block').children('.smsw-float-preview-area').remove();
		}

		var floatOpenId = '';

		function restoreFloatPreview() {
			if (!floatOpenId) { return; }
			var $el = $blocks.children('.smsw-block[data-block-id="' + floatOpenId + '"]').first();
			if (!$el.length) { floatOpenId = ''; return; }
			if ($el.children('.smsw-float-preview-area').length) { return; }
			openFloatPreview($el);
		}

		// Auto-close support. One document-level listener (bound once, in the
		// entry point below) fires this event when the user interacts anywhere
		// outside the floating preview. Every builder listens for it so each
		// one can clear its OWN floatOpenId: dropping the nodes alone is not
		// enough, because the next sync() calls restoreFloatPreview(), which
		// would otherwise re-open the popup the user just dismissed.
		$(document).on('smsw:closefloatpreview', function () {
			floatOpenId = '';
			$blocks.children('.smsw-block').children('.smsw-float-preview-area').remove();
		});

		function openFloatPreview($el) {
			$el.children('.smsw-float-preview-area').remove();
			if ($el.hasClass('is-collapsed')) {
				$el.removeClass('is-collapsed');
			}
			syncFromDom();
			var idx = $blocks.children('.smsw-block').index($el);
			if (idx < 0 || !blocks[idx]) { return; }
			var obj = blockToJsonLd(blocks[idx]);
			var text = obj ? JSON.stringify(obj, null, 2) : t('previewEmpty');

			var blockEl = $el[0];
			var bRect = blockEl.getBoundingClientRect();
			var blockWidth = bRect.width;
			var contentRight = 0;
			$el.find('.smsw-field .smsw-input').each(function () {
				var r = this.getBoundingClientRect();
				var edge = r.right - bRect.left;
				if (edge > contentRight) { contentRight = edge; }
			});
			if (contentRight <= 0) { contentRight = Math.round(blockWidth * 0.35); }

			// The preview area gets the full width left over beside the fields
			// instead of half of it, and a generous minimum, so a typical block's
			// JSON-LD is readable without scrolling. The internal scroll stays
			// (CSS overflow-y) for blocks whose output is still taller.
			var gap = 16;
			var minArea = 340;
			var areaLeft = contentRight + gap;
			var avail = blockWidth - areaLeft;
			if (avail < minArea) {
				areaLeft = Math.max(0, blockWidth - minArea);
			}

			var topPx = 48;
			var $type = $el.find('.smsw-type-autocomplete').first();
			if ($type.length) {
				var tRect = $type[0].getBoundingClientRect();
				topPx = Math.max(48, Math.round(tRect.bottom - bRect.top) + 12);
			}

			// Keep the sticky panel clear of the WordPress admin toolbar.
			var $bar = $('#wpadminbar');
			var barH = ($bar.length && $bar[0].offsetHeight) ? $bar[0].offsetHeight : 32;
			var stickyTop = Math.max(46, Math.round(barH + 14));
			var copyLabel = t('previewCopy') || 'Copy';

			// Fill the available column rather than splitting it in half.
			var areaW = Math.max(340, Math.round(blockWidth - areaLeft - 2));
			// Minimum 280px tall, but grow with the block when it has the room.
			var areaH = Math.max(280, Math.round(bRect.height - topPx - 1));
			var $area = $('<div class="smsw-float-preview-area">' +
				'<div class="smsw-float-preview-anchor">' +
				'<div class="smsw-float-preview"><pre class="smsw-float-preview-code"></pre></div>' +
				'<button type="button" class="smsw-float-preview-copy" data-smsw-action="copy-float-preview" aria-label="' + escHtml(copyLabel) + '">' + escHtml(copyLabel) + '</button>' +
				'<button type="button" class="smsw-float-preview-close" data-smsw-action="close-float-preview" aria-label="Close">\u00D7</button>' +
				'</div>' +
				'</div>');
			$area.css({
				top: topPx + 'px',
				right: 0,
				width: areaW + 'px',
				height: areaH + 'px'
			});
			if ($area[0] && $area[0].style && $area[0].style.setProperty) {
				$area[0].style.setProperty('--smsw-float-max-h', areaH + 'px');
				$area[0].style.setProperty('--smsw-float-sticky-top', stickyTop + 'px');
			}
			$el.append($area);
			$area.find('.smsw-float-preview-code').first().text(text);
		}

		$(document).on('click.smsw', '.smsw-metabox [data-smsw-action="header-preview"]', function (e) {
			var root = $(this).closest('.smsw-metabox');
			if ( ! claimRoot( root ) ) { return; }
			refreshRefs( root );
			e.preventDefault();
			var $el = $(this).closest('.smsw-block');
			if ($el.children('.smsw-float-preview-area').length) {
				floatOpenId = '';
				$el.children('.smsw-float-preview-area').remove();
				return;
			}
			closeFloatPreviews();
			floatOpenId = $el.attr('data-block-id') || '';
			openFloatPreview($el);
		});

		$(document).on('click.smsw', '.smsw-metabox [data-smsw-action="close-float-preview"]', function (e) {
			var root = $(this).closest('.smsw-metabox');
			if ( ! claimRoot( root ) ) { return; }
			e.preventDefault();
			// The X lives next to the preview box inside the anchor, not inside
			// the box itself, so remove the whole area.
			var $blockEl = $(this).closest('.smsw-block');
			if (($blockEl.attr('data-block-id') || '') === floatOpenId) { floatOpenId = ''; }
			$(this).closest('.smsw-float-preview-area').remove();
		});

		// One merged handler serves the floating preview copy button and the
		// copy button on the lower preview panel.
		$(document).on('click.smsw', '.smsw-metabox [data-smsw-action="copy-float-preview"], .smsw-metabox [data-smsw-action="copy-panel-preview"]', function (e) {
			var root = $(this).closest('.smsw-metabox');
			if ( ! claimRoot( root ) ) { return; }
			refreshRefs( root );
			e.preventDefault();
			var $btn = $(this);
			var $area = $btn.closest('.smsw-float-preview-area');
			var $panel = $btn.closest('.smsw-preview-wrap, .smsw-preview-panel');
			var $ta = $area.length ? $area.find('.smsw-float-preview-code').first() : ($panel.length ? $panel.find('.smsw-json-preview').first() : previewTarget());
			var text = $ta.length ? String($ta.text() || $ta.val() || '') : '';
			var noticeSel = opts.noticeSel || '';
			var label = $btn.text();
			if (!text || text === t('previewEmpty')) {
				showNotice(noticeSel, t('copyEmpty'));
				return;
			}
			copyText(text, function (ok) {
				showNotice(noticeSel, ok ? t('copyOk') : t('copyManual'));
				if (ok) {
					$btn.text(t('previewCopied') || label).addClass('is-copied');
					window.setTimeout(function () {
						$btn.text(label).removeClass('is-copied');
					}, 1500);
				}
			});
		});

		$(document).on('click.smsw', '.smsw-metabox [data-smsw-action="header-validate"]', function (e) {
			var root = $(this).closest('.smsw-metabox');
			if ( ! claimRoot( root ) ) { return; }
			refreshRefs( root );
			e.preventDefault();
			var $el = $(this).closest('.smsw-block');
			syncFromDom();
			var idx = $blocks.children('.smsw-block').index($el);
			if (idx < 0 || !blocks[idx]) { return; }
			var obj = blockToJsonLd(blocks[idx]);
			var noticeSel = opts.noticeSel || '';
			if (!obj) {
				showNotice(noticeSel, t('copyEmpty'));
				return;
			}
			var text = JSON.stringify(obj, null, 2);
			// Same pattern as the lower Copy and Validate with Schema.org button:
			// copy this block's JSON-LD, then open the official schema.org validator.
			copyText(text, function (ok) {
				showNotice(noticeSel, ok ? t('copySchemaOk') : t('copyManual'));
				window.open('https://validator.schema.org/', '_blank');
			});
		});

		$(document).on('click.smsw', '.smsw-metabox .smsw-copy-dismiss', function (e) {
			var root = $(this).closest('.smsw-metabox');
			if ( ! claimRoot( root ) ) { return; }
			e.preventDefault();
			$(this).closest('.smsw-copy-notice').prop('hidden', true);
		});

		$(document).on('click.smsw', '.smsw-metabox #smsw-validation-refresh', function (e) {
			var root = $(this).closest('.smsw-metabox');
			if ( ! claimRoot( root ) ) { return; }
			refreshRefs( root );
			e.preventDefault();
			refreshPreview();
		});

		$(document).on('click.smsw', '.smsw-metabox #smsw-ai-suggest', function (e) {
			var root = $(this).closest('.smsw-metabox');
			if ( ! claimRoot( root ) ) { return; }
			refreshRefs( root );
			e.preventDefault();
			var $btn = $(this);
			var $res = root.find('#smsw-ai-result').first();
			if ($btn.prop('disabled') || $btn.data('smsw-busy')) { return; }
			$btn.data('smsw-busy', true);
			$res.prop('hidden', false).text(t('aiLoading'));

			// Track which types were already suggested or used on this post,
			// so the server can ask the AI for a different, still relevant type.
			var excludeList = [];
			var aiSeen = root.data('smsw-ai-seen') || [];
			for (var ei = 0; ei < aiSeen.length; ei++) {
				if (aiSeen[ei]) { excludeList.push(String(aiSeen[ei])); }
			}
			syncFromDom();
			for (var bi = 0; bi < blocks.length; bi++) {
				if (blocks[bi] && blocks[bi].type) { excludeList.push(String(blocks[bi].type)); }
			}

			$.post(META.ajaxUrl, {
				action: 'smsw_ai_suggest',
				nonce: META.aiNonce,
				post_id: opts.postId || META.postId || 0,
				exclude: excludeList.join(',')
			}).done(function (resp) {
				var data = (resp && resp.data) ? resp.data : {};
				if (resp && resp.success && Array.isArray(data.suggestions)) {
					// Each returned type is remembered so the next request is told
					// to avoid it. This is also what lets a dismissed card reappear
					// naturally on a later call: nothing is permanently suppressed.
					var seen = root.data('smsw-ai-seen') || [];
					for (var si = 0; si < data.suggestions.length; si++) {
						if (data.suggestions[si] && data.suggestions[si].type) {
							seen.push(String(data.suggestions[si].type));
						}
					}
					root.data('smsw-ai-seen', seen);
					$res.html(renderAiSuggestions(data.suggestions));
				} else if (resp && resp.success && data.type) {
					var seen = root.data('smsw-ai-seen') || [];
					seen.push(String(data.type));
					root.data('smsw-ai-seen', seen);
					$res.html(
						'<p><strong>' + escHtml(data.type) + '</strong>' + (data.reason ? ' - ' + escHtml(data.reason) : '') + '</p>' +
						'<p><button type="button" class="button smsw-btn-primary" data-smsw-ai-type="' + escHtml(data.type) + '">' + escHtml(t('aiAccept')) + '</button> ' +
						'<button type="button" class="button" data-smsw-ai-dismiss="1">' + escHtml(t('aiDismiss')) + '</button></p>'
					);
				} else {
					$res.text((data && data.message) ? data.message : t('aiError'));
				}
			}).fail(function () {
				$res.text(t('aiError'));
			}).always(function () {
				$btn.removeData('smsw-busy');
			});
		});

		$(document).on('click.smsw', '.smsw-metabox [data-smsw-ai-type]', function (e) {
			var root = $(this).closest('.smsw-metabox');
			if ( ! claimRoot( root ) ) { return; }
			refreshRefs( root );
			e.preventDefault();
			var stype = $(this).attr('data-smsw-ai-type') || '';
			if (!typeExists(stype)) { return; }
			addBlock(stype);
			// Only the card that was used is removed. Any sibling suggestions
			// stay on screen so the user can add more than one, one at a time.
			// When the last card is gone the whole results area is hidden.
			var $card = $(this).closest('.smsw-ai-card');
			var $res = root.find('#smsw-ai-result').first();
			if ($card.length) {
				$card.remove();
				if (!$res.find('.smsw-ai-card').length) {
					$res.prop('hidden', true).empty();
				}
			} else {
				// Legacy single-suggestion markup with no card wrapper.
				$res.prop('hidden', true).empty();
			}
		});

		$(document).on('click.smsw', '.smsw-metabox [data-smsw-ai-dismiss="1"]', function (e) {
			var root = $(this).closest('.smsw-metabox');
			if ( ! claimRoot( root ) ) { return; }
			refreshRefs( root );
			e.preventDefault();
			// Dismiss is single-purpose and one-way: it removes just this card
			// and never toggles back to "add". Sibling cards are untouched, and
			// the area is only hidden once the last card is gone. A dismissed
			// card returns naturally on the next "Get AI Suggestion" click,
			// because that is a fresh call and nothing is remembered as
			// permanently dismissed.
			var $res = root.find('#smsw-ai-result').first();
			var $card = $(this).closest('.smsw-ai-card');
			if ($card.length) {
				$card.remove();
				if (!$res.find('.smsw-ai-card').length) {
					$res.prop('hidden', true).empty();
				}
				return;
			}
			$res.prop('hidden', true).empty();
		});

		/**
		 * Render the suggestion cards.
		 *
		 * One card per suggestion, each with its own "use" button and its own
		 * dismiss control, so the two actions never interfere with each other
		 * or with the other cards.
		 *
		 * @param {Array<{type:string, reason:string}>} items Suggestions.
		 * @return {string} HTML for the results area.
		 */
		function renderAiSuggestions(items) {
			if (!items || !items.length) {
				// The model was asked to return an empty array when nothing
				// meaningfully new fits. That is a good outcome, not a failure.
				return '<p class="smsw-ai-covered">' + escHtml(t('aiCovered')) + '</p>';
			}

			var html = '';
			for (var i = 0; i < items.length; i++) {
				var item = items[i];
				if (!item || !item.type) { continue; }
				html += '<div class="smsw-ai-card" data-smsw-ai-card-type="' + escHtml(item.type) + '">' +
					'<p class="smsw-ai-card-text"><strong>' + escHtml(t('aiSuggestedType')) + ': ' + escHtml(item.type) + '</strong>' +
					(item.reason ? ' - ' + escHtml(item.reason) : '') + '</p>' +
					'<p class="smsw-ai-card-actions">' +
					'<button type="button" class="button smsw-btn-primary" data-smsw-ai-type="' + escHtml(item.type) + '">' + escHtml(t('aiAccept')) + '</button> ' +
					'<button type="button" class="button smsw-ai-card-dismiss" data-smsw-ai-dismiss="1" aria-label="' + escHtml(t('aiDismiss')) + '">' + escHtml(t('aiDismiss')) + '</button>' +
					'</p></div>';
			}
			return html;
		}

		$(document).on('click.smsw', '.smsw-metabox #smsw-use-suggestion', function (e) {
			var root = $(this).closest('.smsw-metabox');
			if ( ! claimRoot( root ) ) { return; }
			refreshRefs( root );
			e.preventDefault();
			var stype = root.find('#smsw-rule-suggestion').first().attr('data-type') || '';
			if (typeExists(stype)) {
				addBlock(stype);
			}
		});

		$(document).on('click.smsw', '.smsw-metabox #smsw-dismiss-suggestion', function (e) {
			var root = $(this).closest('.smsw-metabox');
			if ( ! claimRoot( root ) ) { return; }
			refreshRefs( root );
			e.preventDefault();
			root.find('#smsw-rule-suggestion').first().prop('hidden', true);
		});

		// The Add button is delegated at document level too, so it keeps
		// working after a Gutenberg remount gives the metabox a fresh node.
		// The selector is built from this container's own data-add-button
		// attribute, because every builder renders a differently named
		// button (meta box, Site Schema and Validation all use unique ids).
		// The instance guard below keeps a click from another builder inert.
		if (opts.addSel) {
			$(document).on('click.smsw-add', '.smsw-metabox ' + opts.addSel, function (e) {
				var root = $(this).closest('.smsw-metabox');
				if ( ! claimRoot( root ) ) { return; }
				refreshRefs( root );
				e.preventDefault();
				addBlock();
			});
		}
		if ($add.length) {
			$add.prop('hidden', false);
		}
		$blocks.prop('hidden', false);

		renderAll();

		var needTypes = {};
		var bi;
		for (bi = 0; bi < blocks.length; bi++) {
			var bt = blocks[bi].type;
			if (!isCustomType(bt) && typeExists(bt) && !propsFor(bt)) {
				needTypes[bt] = true;
			}
		}
		var needList = Object.keys(needTypes);
		if (needList.length) {
			var remaining = needList.length;
			var onLoaded = function () {
				remaining -= 1;
				if (remaining <= 0) {
					renderAll();
					sync();
				}
			};
			for (bi = 0; bi < needList.length; bi++) {
				ensureProps(needList[bi], onLoaded);
			}
		}

		ensurePanelCopyButton();
		sync();
	}

	start(function ($) {
		$(function () {
			// Outside-click closes open type dropdowns. Guarded by a flag on the
			// document so a second builder cannot register the handler twice.
			if (!$(document).data('smsw-outside-bound')) {
				$(document).data('smsw-outside-bound', true);
				$(document).on('mousedown.smsw-out', function (e) {
					if (!$(e.target).closest('.smsw-type-autocomplete').length) {
						$('.smsw-type-dropdown').prop('hidden', true);
						$('.smsw-type-input').removeData('smsw-idx');
					}
				});

				// Auto-close the floating JSON-LD preview on any interaction
				// outside it. The preview is a floating overlay, so while it is
				// open it can cover other fields and buttons on the page.
				//
				// This listener is bound exactly once for the whole page, no
				// matter how many builders exist (Settings page, post editor
				// meta box and Validation tab can all be present at once), so
				// there is no duplicate firing. Each builder owns a separate
				// 'smsw:closefloatpreview' listener scoped to its own block
				// container, so one broadcast closes them all and never twice
				// over the same popup.
				//
				// Registered in the capture phase so a React (Gutenberg) handler
				// that calls stopPropagation() upstream cannot keep the popup up.
				// Clicks inside the popup itself, and on the header button that
				// toggles it, are excluded: the former must stay usable (the
				// Copy button and text selection live there) and the latter
				// still needs to toggle closed on a second click.
				var closeFloatOnOutside = function (e) {
					var $t = $(e.target);
					if ($t.closest('.smsw-float-preview-area, [data-smsw-action="header-preview"]').length) {
						return;
					}
					$(document).trigger('smsw:closefloatpreview');
				};
				document.addEventListener('mousedown', closeFloatOnOutside, true);
				// focusin covers Tab navigation and programmatic focus into any
				// other input, textarea or select, which never fires mousedown.
				document.addEventListener('focusin', closeFloatOnOutside, true);

				// The jump button (#smsw-side-jump) and modal close controls are
				// handled below in the capture-phase document click listener,
				// ensuring they toggle the modal regardless of React re-renders.
			}

			if (!META.types || !META.i18n) {
				if (window.console && window.console.warn) {
					window.console.warn('[Schema Markup] The smswMetaBox data was missing, so the schema block builder did not start. Check whether another plugin is stripping localized script data.');
				}
				return;
			}
			$('.smsw-metabox[data-add-button]').each(function () {
				createBuilder($(this));
			});

		// Capture-phase modal controls. Every builder button is handled by the
		// document-delegated jQuery handlers inside createBuilder(); only the
		// modal open/close shortcuts live here, in the capture phase, so
		// Gutenberg's React root can never swallow them.
		document.addEventListener('click', function (e) {
			var el = e.target;
			if (!el || !el.closest) { return; }
			// "Go to Schema Blocks" toggles the full-screen modal.
			if (el.closest('#smsw-side-jump')) {
				e.preventDefault();
				e.stopPropagation();
				$('#smsw_schema_blocks').toggleClass('smsw-fullscreen-modal smsw-modal-open');
				return;
			}
			// Static close button in the postbox header closes it.
			if (el.closest('.smsw-modal-close')) {
				e.preventDefault();
				e.stopPropagation();
				$('#smsw_schema_blocks').removeClass('smsw-fullscreen-modal smsw-modal-open');
				return;
			}
		}, true);

		// Escape closes the modal, unless a schema type dropdown is open:
		// then Escape means "close the dropdown" and the modal stays up.
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				var openDd = document.querySelector('.smsw-type-dropdown:not([hidden])');
				if (openDd) {
					var wrapEl = openDd.closest('.smsw-type-select-wrap');
					var typeIn = wrapEl ? wrapEl.querySelector('.smsw-type-input') : null;
					openDd.hidden = true;
					if (typeIn) { typeIn.removeAttribute('data-smsw-idx'); }
					return;
				}
				$('#smsw_schema_blocks').removeClass('smsw-fullscreen-modal smsw-modal-open');
			}
		}, true);
		});
	});
})();
