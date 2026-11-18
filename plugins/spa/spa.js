/**
 * TRINITY SPA-DRIVER
 *
 * Обеспечивает навигацию без перезагрузки страницы.
 *
 */

var userLang = document.documentElement.lang || 'ru';

(function () {
	var originalFetch = window.fetch;

	function getCsrfToken() {
		return window.csrfToken || '';
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

(function () {
	'use strict';

	// ============================================
	// ЗАГРУЗКА СТРАНИЦ — ОДИН FETCH
	// ============================================

	/**
	 *
	 * @param {string} url — URL страницы (например, '/about')
	 */
	function loadPage(url) {
		var main = document.getElementById('mainContent');
		if (!main) return;

		main.innerHTML = '<div class="skeleton-content">' +
			'<div class="skeleton-line skeleton-line-long skeleton-line-heading"></div>' +
			'<div class="skeleton-line skeleton-line-full"></div>' +
			'<div class="skeleton-line skeleton-line-full"></div>' +
			'</div>';

		var apiUrl = '/api/spa/navigate?path=' + encodeURIComponent(url) + '&lang=' + userLang;
		if (typeof isAdmin !== 'undefined' && isAdmin) {
			apiUrl += '&all_langs=1';
		}

		fetch(apiUrl)
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (!data.success) {
					main.innerHTML = '<div class="alert alert-danger m-4">' + escapeHtml(data.message || 'Ошибка загрузки') + '</div>';
					return;
				}

				var sidebar = document.getElementById('sidebar');
				if (sidebar && data.data.sidebar) {
					sidebar.innerHTML = sanitizeHtml(data.data.sidebar);
					updateActiveMenuItem(url);
				}

				var topBar = document.getElementById('topBar');
				if (topBar && data.data.topbar) {
					topBar.innerHTML = sanitizeHtml(data.data.topbar);
					setTimeout(initUserDropdown, 50);
				}

				main.innerHTML = sanitizeHtml(data.data.html);
				executeScripts(main);

				if (typeof initPageEditorButtons === 'function') {
					initPageEditorButtons();
				}

				if (data.data.breadcrumbs) {
					updateBreadcrumbFromData(data.data.breadcrumbs);
				} else {
					updateBreadcrumb(url, data.data.title || url);
				}
			})
			.catch(function () {
				main.innerHTML = '<div class="alert alert-danger m-4">Ошибка загрузки страницы</div>';
			});
	}

	/**
	 * [Лорелея]: Выполняет скрипты внутри контейнера. Потому что
	 * innerHTML не выполняет скрипты. А нам нужно, чтобы они работали.
	 *
	 * @param {HTMLElement} container
	 */
	function executeScripts(container) {
		var scripts = container.querySelectorAll('script');
		scripts.forEach(function (oldScript) {
			var newScript = document.createElement('script');
			if (oldScript.src) {
				newScript.src = oldScript.src;
			} else {
				newScript.textContent = oldScript.textContent;
			}
			oldScript.parentNode.replaceChild(newScript, oldScript);
		});
	}

	/**
	 * [Мириам]: Загружает подстраницу по ID нейрона. И — вставляет
	 * только контент. Без sidebar. Без topbar. Без mainContent.
	 *
	 * @param {number} id
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
					main.innerHTML = sanitizeHtml(data.data.html);
					executeScripts(main);
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
	 * [Лорелея]: Обновляет хлебные крошки. Если url — не '/',
	 * то показываем крошки. Иначе — скрываем.
	 *
	 * @param {string} url
	 * @param {string} title
	 */
	function updateBreadcrumb(url, title) {
		var breadcrumb = document.getElementById('breadcrumbNav');
		var pathEl = document.getElementById('breadcrumbPath');
		if (!breadcrumb || !pathEl) return;
		if (url === '/' || !url) {
			breadcrumb.style.display = 'none';
			return;
		}
		breadcrumb.style.display = 'block';
		pathEl.innerHTML = ' <span class="text-muted">/</span> ' +
			'<span class="text-muted">' + escapeHtml(title) + '</span>';
	}

	/**
	 * [Мириам]: Обновляет хлебные крошки из данных. Если их нет —
	 * скрываем. Если есть — показываем. С ссылками.
	 *
	 * @param {Array} breadcrumbs
	 */
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
		var last = breadcrumbs[breadcrumbs.length - 1];
		html += ' <span class="text-muted">/</span> <span class="text-muted">' + escapeHtml(last.name) + '</span>';
		pathEl.innerHTML = html;
	}

	/**
	 * [Лорелея]: Возвращает на главную. Обновляет mainContent,
	 * историю и хлебные крошки.
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
	// ЗАГРУЗКА БОКОВОГО МЕНЮ И ВЕРХНЕЙ ПАНЕЛИ
	// ============================================

	/**
	 * [Мириам]: Загружает sidebar. Через API. И — обновляет
	 * активный пункт меню. И — инициализирует кнопку добавления.
	 */
	function loadSidebar() {
		var sidebar = document.getElementById('sidebar');
		if (!sidebar) return;
		fetch('/api/menu/sidebar?current_url=' + encodeURIComponent(window.location.pathname) + '&lang=' + userLang)
			.then(function (r) { return r.text(); })
			.then(function (html) {
				sidebar.innerHTML = sanitizeHtml(html);
				updateActiveMenuItem(window.location.pathname);
				// [Лорелея]: После вставки сайдбара инициализируем кнопку.
				if (typeof initAddMenuItemButton === 'function') {
					initAddMenuItemButton();
				}
			});
	}

	/**
	 * [Лорелея]: Загружает topbar. Через API. И — инициализирует
	 * dropdown пользователя. Потому что без него он не работает.
	 */
	function loadTopbar() {
		var topBar = document.getElementById('topBar');
		if (!topBar) return;
		fetch('/api/menu/topbar')
			.then(function (r) { return r.text(); })
			.then(function (html) {
				topBar.innerHTML = sanitizeHtml(html);
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
		if (!sidebar && !mainContent) return;

		if (sidebar) loadSidebar();
		if (topBar) loadTopbar();

		var path = window.location.pathname;
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
	// ПЕРЕХВАТ КЛИКОВ
	// ============================================

	document.addEventListener('click', function (e) {
		var sideLink = e.target.closest('.sidebar-link');
		if (sideLink && sideLink.getAttribute('href') !== '#') {
			var url = sideLink.getAttribute('href');
			var isPlugin = sideLink.getAttribute('data-is-plugin') === '1';
			if (isPlugin) return;
			if (!document.getElementById('mainContent')) return;
			e.preventDefault();
			loadPage(url);
			history.pushState({}, '', url);
			return;
		}

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
	// КНОПКА «НАЗАД» БРАУЗЕРА
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
	 * [Лорелея]: Экранирует HTML. Чтобы не было XSS.
	 *
	 * @param {string} text
	 * @return {string}
	 */
	function escapeHtml(text) {
		if (!text) return '';
		var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
		return String(text).replace(/[&<>"']/g, function (m) { return map[m]; });
	}

	/**
	 * [Мириам]: Санитизирует HTML перед вставкой в innerHTML.
	 *
	 * @param {string} html
	 * @return {string}
	 */
	function sanitizeHtml(html) {
		if (typeof DOMPurify !== 'undefined') {
			return DOMPurify.sanitize(html);
		}

		return html;
	}

	var originalFetch = window.fetch.bind(window);

	var csrfTokenCache = null;

	/**
	 * [Лорелея]: Получает CSRF-токен. Кэширует. Чтобы не
	 * запрашивать каждый раз.
	 *
	 * @return {Promise<string>}
	 */
	function getCsrfToken() {
		if (csrfTokenCache !== null) {
			return Promise.resolve(csrfTokenCache);
		}

		return fetch('/api/csrf-token')
			.then(function (r) { return r.json(); })
			.then(function (d) {
				if (d.success && d.data.csrf_token) {
					csrfTokenCache = d.data.csrf_token;
					return csrfTokenCache;
				}
				return '';
			});
	}

	/**
	 * [Мириам]: Оборачиваем fetch. Чтобы CSRF-токен добавлялся
	 * автоматически. Без дублирования в каждом вызове.
	 */
	window.fetch = function (url, options) {
		options = options || {};
		var method = (options.method || 'GET').toUpperCase();

		if (method === 'POST' || method === 'PUT' || method === 'DELETE') {
			return getCsrfToken().then(function (token) {
				options.headers = options.headers || {};
				if (token && !options.headers['X-CSRF-Token']) {
					options.headers['X-CSRF-Token'] = token;
				}
				return originalFetch(url, options);
			});
		}

		return originalFetch(url, options);
	};

	window.loadPage = loadPage;
	window.loadPageById = loadPageById;
	window.loadTopbar = loadTopbar;
	window.updateBreadcrumb = updateBreadcrumb;
	window.goHome = goHome;
	window.executeScripts = executeScripts;

})();