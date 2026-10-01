/**
 * TRINITY SPA-DRIVER
 * ==================
 * Обеспечивает навигацию без перезагрузки страницы.
 * 
 * Возможности:
 * - Загрузка бокового меню и верхней панели
 * - Навигация по страницам через History API
 * - Хлебные крошки
 * - Обработка кнопки "Назад" браузера
 * - Исключения для плагинов (полный переход)
 * 
 * Зависимости:
 * - menu.js (функции updateActiveMenuItem, initUserDropdown)
 * - page-editor.js (inline-редактор, если админ)
 */

/**
 * ГЛОБАЛЬНАЯ ОБЁРТКА FETCH ДЛЯ CSRF
 * ===================================
 * Автоматически добавляет заголовок X-CSRF-Token
 * ко всем POST, PUT, DELETE запросам.
 * Токен читается из cookie 'csrf_token'.
 */
var userLang = document.documentElement.lang || 'ru';

(function () {
	var originalFetch = window.fetch;

	function getCsrfToken() {
		var cookies = document.cookie.split(';');
		for (var i = 0; i < cookies.length; i++) {
			var cookie = cookies[i].trim();
			if (cookie.startsWith('csrf_token=')) {
				return cookie.substring('csrf_token='.length);
			}
		}
		return '';
	}

	window.fetch = function (url, options) {
		options = options || {};

		var method = (options.method || 'GET').toUpperCase();
		if (method === 'POST' || method === 'PUT' || method === 'DELETE') {
			options.headers = options.headers || {};

			if (!options.headers['X-CSRF-Token']) {
				var token = getCsrfToken();
				if (token) {
					options.headers['X-CSRF-Token'] = token;
				}
			}
		}

		return originalFetch(url, options);
	};
})();

/**
 * TRINITY SPA-DRIVER
 * ==================
 * ...
 */
(function () {
	'use strict';

	// ============================================
	// ЗАГРУЗКА СТРАНИЦ
	// ============================================

	/**
	 * Загружает страницу через API и вставляет HTML в #mainContent.
	 * @param {string} url — путь страницы (/about, /books)
	 */
	function loadPage(url) {
		var main = document.getElementById('mainContent');
		if (!main) return;

		// Профиль — особый случай (загружается через /api/profile)
		if (url === '/profile' || url.startsWith('/profile')) {
			var slug = url.replace(/^\//, '');
			main.innerHTML = '<div class="skeleton-content">' +
				'<div class="skeleton-line skeleton-line-long skeleton-line-heading"></div>' +
				'<div class="skeleton-line skeleton-line-full"></div>' +
				'</div>';

			fetch('/api/' + slug + '?lang=' + userLang)
				.then(function (r) { return r.json(); })
				.then(function (data) {
					if (data.success) {
						main.innerHTML = data.data.html;
						updateBreadcrumb(url, data.data.title || slug);
					}
				});
			return;
		}

		// Обычная страница — через /api/page/{slug}
		var slug = url.replace(/^\//, '');
		main.innerHTML = '<div class="skeleton-content">' +
			'<div class="skeleton-line skeleton-line-long skeleton-line-heading"></div>' +
			'<div class="skeleton-line skeleton-line-full"></div>' +
			'<div class="skeleton-line skeleton-line-full"></div>' +
			'</div>';

		var apiUrl = '/api/page/' + slug + '?lang=' + userLang;
		if (typeof isAdmin !== 'undefined' && isAdmin) {
			apiUrl += '&all_langs=1';
		}

		fetch(apiUrl)
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (data.success) {
					main.innerHTML = data.data.html;
					updateActiveMenuItem(url);
					if (data.data.breadcrumbs) {
						updateBreadcrumbFromData(data.data.breadcrumbs);
					}
				}
			})
			.catch(function () {
				main.innerHTML = '<div class="alert alert-danger m-4">Ошибка загрузки страницы</div>';
			});
	}

	/**
	 * Загружает подстраницу по ID нейрона.
	 * Используется для подстраниц без slug.
	 * @param {number} id — ID нейрона подстраницы
	 */
	function loadPageById(id) {
		var main = document.getElementById('mainContent');
		if (!main) return;

		main.innerHTML = '<div class="skeleton-content">' +
			'<div class="skeleton-line skeleton-line-long skeleton-line-heading"></div>' +
			'<div class="skeleton-line skeleton-line-full"></div>' +
			'<div class="skeleton-line skeleton-line-full"></div>' +
			'</div>';

		fetch('/api/page/sub/' + id)
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (data.success) {
					main.innerHTML = data.data.html;
					if (data.data.breadcrumbs) {
						updateBreadcrumbFromData(data.data.breadcrumbs);
					}
				}
			})
			.catch(function () {
				main.innerHTML = '<div class="alert alert-danger m-4">Ошибка загрузки подстраницы</div>';
			});
	}

	// ============================================
	// ХЛЕБНЫЕ КРОШКИ
	// ============================================

	/**
	 * Обновляет хлебные крошки.
	 * @param {string} url — текущий путь
	 * @param {string} title — название страницы
	 */
	function updateBreadcrumb(url, title) {
		var breadcrumb = document.getElementById('breadcrumbNav');
		var pathEl = document.getElementById('breadcrumbPath');

		if (!breadcrumb || !pathEl) return;

		// На главной — скрываем
		if (url === '/' || !url) {
			breadcrumb.style.display = 'none';
			return;
		}

		breadcrumb.style.display = 'block';
		pathEl.innerHTML = ' <span class="text-muted">/</span> ' +
			'<span class="text-muted">' + escapeHtml(title) + '</span>';
	}

	function updateBreadcrumbFromData(breadcrumbs) {
		var breadcrumb = document.getElementById('breadcrumbNav');
		var pathEl = document.getElementById('breadcrumbPath');
		if (!breadcrumb || !pathEl) return;
		
		if (!breadcrumbs || breadcrumbs.length === 0) {
			breadcrumb.style.display = 'none';
			return;
		}
		
		breadcrumb.style.display = 'block';
		
		var html = '';
		for (var i = 0; i < breadcrumbs.length - 1; i++) {
			var bc = breadcrumbs[i];
			if (bc.slug) {
				html += ' <span class="text-muted">/</span> <a href="/' + bc.slug + '" class="gold-text">' + escapeHtml(bc.name) + '</a>';
			} else {
				html += ' <span class="text-muted">/</span> <span class="text-muted">' + escapeHtml(bc.name) + '</span>';
			}
		}
		// Последний — не ссылка
		var last = breadcrumbs[breadcrumbs.length - 1];
		html += ' <span class="text-muted">/</span> <span class="text-muted">' + escapeHtml(last.name) + '</span>';
		
		pathEl.innerHTML = html;
	}

	/**
	 * Возвращает на главную страницу.
	 */
	function goHome() {
		var main = document.getElementById('mainContent');
		if (main) {
			main.innerHTML = '<div class="p-4">' +
				'<h1>Добро пожаловать в Trinity</h1>' +
				'<p class="lead">SPA-движок на трёх таблицах. Всё работает без перезагрузки.</p>' +
				'</div>';
		}
		history.pushState({}, '', '/');
		updateActiveMenuItem('/');
		updateBreadcrumb('/', '');
	}

	// ============================================
	// ЗАГРУЗКА БОКОВОГО МЕНЮ
	// ============================================

	/**
	 * Загружает боковое меню через API.
	 */
	function loadSidebar() {
		var sidebar = document.getElementById('sidebar');
		if (!sidebar) return;

		fetch('/api/menu/sidebar?current_url=' + encodeURIComponent(window.location.pathname) + '&lang=' + userLang)
			.then(function (r) { return r.text(); })
			.then(function (html) {
				sidebar.innerHTML = html;
				updateActiveMenuItem(window.location.pathname);
			});
	}

	/**
	 * Загружает верхнюю панель через API.
	 */
	function loadTopbar() {
		var topBar = document.getElementById('topBar');
		if (!topBar) return;

		fetch('/api/menu/topbar')
			.then(function (r) { return r.text(); })
			.then(function (html) {
				topBar.innerHTML = html;
				// Инициализируем dropdown после вставки в DOM
				setTimeout(initUserDropdown, 50);
			});
	}

	// ============================================
	// ИНИЦИАЛИЗАЦИЯ
	// ============================================

	document.addEventListener('DOMContentLoaded', function () {
		var sidebar = document.getElementById('sidebar');
		var topBar = document.getElementById('topBar');
		var mainContent = document.getElementById('mainContent');

		// Если нет SPA-зон — это страница логина/регистрации/карты, выходим
		if (!sidebar && !mainContent) return;

		// Загружаем боковое меню
		if (sidebar) loadSidebar();

		// Загружаем верхнюю панель
		if (topBar) loadTopbar();

		// Загружаем контент текущей страницы
		var path = window.location.pathname;

		// Плагины (полный переход) не обрабатываем
		if (path === '/admin' || path === '/map' || path === '/tools' || path === '/gallery') return;

		if (mainContent) {
			if (path === '/') {
				goHome();
			} else {
				loadPage(path);
			}
		}
	});

	// ============================================
	// ПЕРЕХВАТ КЛИКОВ ДЛЯ SPA-НАВИГАЦИИ
	// ============================================

	document.addEventListener('click', function (e) {
		// Боковое меню
		var sideLink = e.target.closest('.sidebar-link');
		if (sideLink && sideLink.getAttribute('href') !== '#') {
			var url = sideLink.getAttribute('href');
			var isPlugin = sideLink.getAttribute('data-is-plugin') === '1';

			// Плагины — полный переход, не обрабатываем
			if (isPlugin) return;
			// Если нет контентной зоны — выходим
			if (!document.getElementById('mainContent')) return;

			e.preventDefault();
			loadPage(url);
			history.pushState({}, '', url);
			return;
		}

		// Верхнее меню (профиль)
		var topLink = e.target.closest('.topbar-dropdown-item');
		if (topLink) {
			var topUrl = topLink.getAttribute('href');
			if (topUrl === '/profile' || topUrl === '/profile/edit') {
				if (!document.getElementById('mainContent')) return;
				e.preventDefault();
				loadPage(topUrl);
				history.pushState({}, '', topUrl);
			}
			return;
		}
	});

	// ============================================
	// КНОПКА "НАЗАД" БРАУЗЕРА
	// ============================================

	window.addEventListener('popstate', function () {
		var mainContent = document.getElementById('mainContent');
		if (!mainContent) return;

		var path = window.location.pathname;
		if (path === '/') {
			goHome();
		} else {
			loadPage(path);
		}
	});

	// ============================================
	// ВСПОМОГАТЕЛЬНЫЕ
	// ============================================

	/**
	 * Экранирует HTML-сущности.
	 * @param {string} text
	 * @return {string}
	 */
	function escapeHtml(text) {
		if (!text) return '';
		var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
		return String(text).replace(/[&<>"']/g, function (m) { return map[m]; });
	}

	// Экспорт в глобальную область
	window.loadPage = loadPage;
	window.loadPageById = loadPageById;
	window.loadTopbar = loadTopbar;
	window.updateBreadcrumb = updateBreadcrumb;
	window.goHome = goHome;

})();