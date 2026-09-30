/**
 * SHIED WORLD Schema Markup - AI BYOK key field behaviour.
 *
 * Two things are handled here:
 *
 * 1. Show and hide. The key input starts masked (type="password"). Clicking
 *    the eye button switches it to plain text so the pasted key can be
 *    checked, and clicking again masks it. The button icon, aria-pressed,
 *    and aria-label follow the visible state.
 *
 * 2. Per provider preview. Each provider keeps its own saved key, so the
 *    field shows the masked value of the key stored for the provider that is
 *    currently selected. Changing the dropdown swaps the field over to the
 *    newly selected provider's masked value, or to an empty field with the
 *    plain placeholder when that provider has no key yet. A key the user has
 *    just typed is never thrown away by a dropdown change.
 */
(function () {
	'use strict';

	function setEndCaret(input) {
		// Move the caret to the end without changing the value.
		try {
			var len = input.value.length;
			input.setSelectionRange(len, len);
		} catch (err) {
			// Some input types do not support setSelectionRange. Ignoring is fine.
		}
	}

	function toggle(button) {
		var selector = button.getAttribute('data-smsw-eye-target');
		var input = selector ? document.querySelector(selector) : null;
		if (!input) {
			return;
		}

		var showing = input.type === 'password';
		input.type = showing ? 'text' : 'password';

		var showIcon = button.querySelector('.smsw-eye-show');
		var hideIcon = button.querySelector('.smsw-eye-hide');
		if (showIcon && hideIcon) {
			// While the key is visible, show the slashed eye that hides it.
			showIcon.hidden = showing;
			hideIcon.hidden = !showing;
		}

		button.setAttribute('aria-pressed', showing ? 'true' : 'false');
		button.setAttribute(
			'aria-label',
			showing
				? (button.getAttribute('data-label-hide') || 'Hide API key')
				: (button.getAttribute('data-label-show') || 'Show API key')
		);

		input.focus();
		setEndCaret(input);
	}

	function initEyeButtons() {
		var buttons = document.querySelectorAll('.smsw-key-eye');
		if (!buttons.length) {
			return;
		}

		Array.prototype.forEach.call(buttons, function (button) {
			if (button.getAttribute('data-smsw-eye-ready') === '1') {
				return;
			}
			button.setAttribute('data-smsw-eye-ready', '1');
			button.addEventListener('click', function (e) {
				e.preventDefault();
				toggle(button);
			});
		});
	}

	function initProviderPreview() {
		var wrap = document.getElementById('smsw-ai-key-wrap');
		if (!wrap) {
			return;
		}

		var selectSelector = wrap.getAttribute('data-smsw-provider-field');
		var select = selectSelector ? document.querySelector(selectSelector) : null;
		var input = document.getElementById('smsw_ai_api_key');

		if (!select || !input) {
			return;
		}

		var previews = {};
		try {
			previews = JSON.parse(wrap.getAttribute('data-smsw-previews') || '{}') || {};
		} catch (err) {
			previews = {};
		}

		var emptyPlaceholder = wrap.getAttribute('data-smsw-preview-placeholder') || '';

		function previewFor(slug) {
			return Object.prototype.hasOwnProperty.call(previews, slug) ? (previews[slug] || '') : '';
		}

		function applyPreview() {
			var value = previewFor(select.value);
			input.value = value;
			input.placeholder = value ? '' : emptyPlaceholder;
		}

		// Initialize and switch using only the selected provider's saved preview.
		applyPreview();
		select.addEventListener('change', applyPreview);
	}

	/**
	 * Per provider model dropdown.
	 *
	 * The server renders one <select> per provider and hides all but the
	 * active one, so switching the provider dropdown just reveals the matching
	 * select instead of rewriting a shared field. That keeps every provider's
	 * saved choice independent and stops a model id being submitted against
	 * the wrong provider's key.
	 *
	 * The rendered options are already the filtered, cached list. When the
	 * provider has a key saved, the list is refreshed from the provider's own
	 * models endpoint in the background so a model retired upstream cannot
	 * keep being offered, and so a newly released one appears.
	 */
	function initModelPicker() {
		var field = document.getElementById('smsw-ai-model-field');
		var providerSelect = document.getElementById('smsw_ai_provider');
		if (!field || !providerSelect) {
			return;
		}

		var ajaxUrl = field.getAttribute('data-smsw-model-ajax') || '';
		var nonce = field.getAttribute('data-smsw-model-nonce') || '';
		var lists = {};
		var selected = {};

		try {
			lists = JSON.parse(field.getAttribute('data-smsw-model-lists') || '{}') || {};
			selected = JSON.parse(field.getAttribute('data-smsw-model-selected') || '{}') || {};
		} catch (err) {
			lists = {};
			selected = {};
		}

		function selectFor(slug) {
			return document.querySelector('.smsw-ai-model-select[data-smsw-model-provider="' + slug + '"]');
		}

		function showProvider() {
			var slug = providerSelect.value;
			var selects = document.querySelectorAll('.smsw-ai-model-select');
			Array.prototype.forEach.call(selects, function (el) {
				// Only the active provider's select is visible AND focusable.
				el.hidden = el.getAttribute('data-smsw-model-provider') !== slug;
			});
			var notes = document.querySelectorAll('.smsw-ai-model-source');
			Array.prototype.forEach.call(notes, function (el) {
				// The live-or-fallback note belongs to one provider too.
				el.hidden = el.getAttribute('data-smsw-model-source') !== slug;
			});
			refresh(slug);
		}

		// Re-fetch one provider's list in the background. Failures are silent:
		// the rendered list is already a usable cached-or-fallback list, and
		// the settings screen must never be blocked by a network hiccup.
		function refresh(slug) {
			if (!ajaxUrl || !nonce || inFlight[slug]) {
				return;
			}

			inFlight[slug] = true;

			var xhr = new XMLHttpRequest();
			// POST, matching the admin-ajax convention, and keeping the nonce
			// out of the URL where logs and referrers can expose it. The
			// handler also accepts the parameters on the query string, so
			// either transport works.
			var body = 'action=smsw_ai_models' +
				'&nonce=' + encodeURIComponent(nonce) +
				'&provider=' + encodeURIComponent(slug);

			xhr.open('POST', ajaxUrl, true);
			xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
			xhr.setRequestHeader('accept', 'application/json');

			xhr.onreadystatechange = function () {
				if (xhr.readyState !== 4) {
					return;
				}

				inFlight[slug] = false;

				if (xhr.status < 200 || xhr.status >= 300) {
					return;
				}

				var payload = null;
				try {
					payload = JSON.parse(xhr.responseText);
				} catch (err) {
					return;
				}

				// An error response must leave the already-rendered list alone.
				// Only a success that actually carries models may rebuild the
				// options, otherwise a hiccup would replace real choices with
				// the "no models available" placeholder.
				if (!payload || !payload.success || !payload.data) {
					return;
				}

				// The note says whether what is on screen now came from the
				// provider or from the built-in fallback, so it is updated
				// before anything else, even when the list itself is unchanged.
				if (typeof payload.data.note === 'string' && payload.data.note) {
					var note = field.querySelector('.smsw-ai-model-source[data-smsw-model-source="' + slug + '"]');
					if (note) {
						note.textContent = payload.data.note;
					}
				}

				var incoming = Array.isArray(payload.data.models) ? payload.data.models : [];
				if (!incoming.length) {
					return;
				}

				lists[slug] = incoming;
				render(slug);
			};

			try {
				xhr.send(body);
			} catch (err) {
				inFlight[slug] = false;
			}
		}

		function render(slug) {
			var select = selectFor(slug);
			var models = lists[slug] || [];
			if (!select) {
				return;
			}

			// Never trade options that are already on screen for the empty
			// placeholder. A populated select (from the server render or the
			// cache) stays exactly as it is when nothing new arrives.
			if (!models.length && select.options.length) {
				return;
			}

			var previous = selected[slug] || select.value;

			select.innerHTML = '';

			if (!models.length) {
				var empty = document.createElement('option');
				empty.value = '';
				empty.textContent = 'No models available. Check your API key and quota.';
				select.appendChild(empty);
				return;
			}

			models.forEach(function (model) {
				var option = document.createElement('option');
				option.value = model;
				option.textContent = model;
				if (model === previous) {
					option.selected = true;
				}
				select.appendChild(option);
			});

			// A provider that retires the saved model must not leave the select
			// pointing at something the API will reject. Fall back to the first
			// (cheapest) option in that case.
			if (models.indexOf(previous) === -1) {
				select.selectedIndex = 0;
				selected[slug] = models[0];
			} else {
				selected[slug] = previous;
			}
		}

		providerSelect.addEventListener('change', showProvider);
		showProvider();
	}

	var inFlight = {};

	function init() {
		initEyeButtons();
		initProviderPreview();
		initModelPicker();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
