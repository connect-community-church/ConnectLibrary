/* ConnectLibrary public catalog — view toggle, no tracking or server writes */
(function () {
	'use strict';

	var STORAGE_KEY = 'connectlibrary_catalog_view';

	function applyView(catalog, view) {
		var items = catalog.querySelector('.connectlibrary-catalog__items');
		if (items) {
			items.classList.remove('is-grid', 'is-list');
			items.classList.add('is-' + view);
		}
		catalog.setAttribute('data-view', view);
		catalog.querySelectorAll('.connectlibrary-catalog__toggle-btn').forEach(function (btn) {
			btn.setAttribute('aria-pressed', btn.getAttribute('data-view') === view ? 'true' : 'false');
		});
	}

	function initCatalog(catalog) {
		try {
			var stored = localStorage.getItem(STORAGE_KEY);
			if (stored === 'grid' || stored === 'list') {
				applyView(catalog, stored);
			}
		} catch (e) {}

		catalog.querySelectorAll('.connectlibrary-catalog__toggle-btn').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var view = btn.getAttribute('data-view');
				if (view === 'grid' || view === 'list') {
					applyView(catalog, view);
					try { localStorage.setItem(STORAGE_KEY, view); } catch (e) {}
				}
			});
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.connectlibrary-catalog').forEach(initCatalog);
	});
}());
