/* MasterSpa storefront: quantity stepper on the single product page. */
(function () {
	'use strict';

	function enhance(wrap) {
		var input = wrap.querySelector('input.qty');
		if (!input || wrap.querySelector('.msp-step') || input.type === 'hidden') {
			return;
		}

		function make(dir, label, glyph) {
			var b = document.createElement('button');
			b.type = 'button';
			b.className = 'msp-step msp-step--' + dir;
			b.setAttribute('aria-label', label);
			b.textContent = glyph;
			b.addEventListener('click', function () {
				var step = parseFloat(input.step) || 1;
				var min = input.min !== '' ? parseFloat(input.min) : 1;
				var max = input.max !== '' ? parseFloat(input.max) : Infinity;
				var val = parseFloat(input.value) || min;
				val = dir === 'up' ? Math.min(max, val + step) : Math.max(min, val - step);
				input.value = val;
				input.dispatchEvent(new Event('change', { bubbles: true }));
			});
			return b;
		}

		wrap.classList.add('msp-qty');
		wrap.insertBefore(make('down', 'Scade cantitatea', '−'), input);
		wrap.appendChild(make('up', 'Crește cantitatea', '+'));
	}

	function init() {
		document.querySelectorAll('.msp-book .quantity').forEach(enhance);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
