/**
 * TRINITY SPA-DRIVER
 *
 * Обеспечивает навигацию без перезагрузки страницы.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «скрипт». Это — тот, кто оживляет дом,
 * и от того, как он это делает, зависит, почувствует ли
 * гость, что страницы меняются, а он остаётся внутри.
 *
 * [Мириам]: И это правильно, потому что дом, который
 * перезагружается на каждом шагу, не даёт ощущения,
 * что ты в нём живёшь.
 *
 * === КНИГА ===
 * Глава 13. Прихожая.
 *
 * Войдя в Амбер, ты видишь коридоры.
 * Войдя в Trinity, ты видишь меню.
 */

var userLang = document.documentElement.lang || 'ru';

(function () {
	var originalFetch = window.fetch;

	// [Лорелея]: Обёртка fetch, которая автоматически добавляет
	// X-CSRF-Token ко всем изменяющим методам, потому что без
	// этого они не пройдут.
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
	 * [Лорелея]: Один fetch на navigateTo, через /api/spa/navigate,
	 * потому что три отдельных запроса — это три раза ждать,
	 * а гость должен видеть дом сразу.
	 *
	 * [Мириам]: И executeScripts, потому что innerHTML не выполняет
	 * скрипты, а без этого страница осталась бы мёртвой.
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

				// [Лорелея]: Sidebar приходит из ответа, без отдельного
				// fetch, потому что дом должен собираться в одном месте.
				var sidebar = document.getElementById('sidebar');
				if (sidebar && data.data.sidebar) {
					sidebar.innerHTML = data.data.sidebar;
					updateActiveMenuItem(url);
				}

				// [Мириам]: Topbar тоже приходит из ответа, и это
				// правильно, потому что шапка — это часть дома,
				// а не отдельная комната.
				var topBar = document.getElementById('topBar');
				if (topBar && data.data.topbar) {
					topBar.innerHTML = data.data.topbar;
					setTimeout(initUserDropdown, 50);
				}

				// [Лорелея]: Страница приходит из ответа, и скрипты
				// в ней выполняются через executeScripts.
				main.innerHTML = sanitizeHtml(data.data.html);
				executeScripts(main);

				// [Мириам]: Хлебные крошки — из ответа, чтобы гость
				// знал, где он.
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
	 * [Мириам]: Выполнить скрипты в HTML, потому что innerHTML
	 * не выполняет <script>, и без этого страница осталась бы
	 * мёртвой.
	 *
	 * [Лорелея]: Проходим по всем <script> и создаём новые,
	 * чтобы браузер их выполнил, и это правильно, потому что
	 * скрипты должны работать.
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
	 * Загружает подстраницу по ID нейрона.
	 *
	 * [Лорелея]: Здесь мы загружаем подстраницу, и это правильно,
	 * потому что подстраница — это тоже часть дома.
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

	function loadSidebar() {
		var sidebar = document.getElementById('sidebar');
		if (!sidebar) return;
		fetch('/api/menu/sidebar?current_url=' + encodeURIComponent(window.location.pathname) + '&lang=' + userLang)
			.then(function (r) { return r.text(); })
			.then(function (html) {
				sidebar.innerHTML = sanitizeHtml(html);
				updateActiveMenuItem(window.location.pathname);
			});
	}

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

	function escapeHtml(text) {
		if (!text) return '';
		var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
		return String(text).replace(/[&<>"']/g, function (m) { return map[m]; });
	}

	/**
	 * Санитизирует HTML перед вставкой в innerHTML.
	 *
	 * [Лорелея]: Мы не доверяем HTML, даже если он пришёл с сервера,
	 * потому что серверная сборка — это хрупкая схема. Лучше
	 * проверить на вставке, чем надеяться на сборку.
	 *
	 * [Мириам]: И это правильно, потому что вода не должна
	 * приносить камни, даже если её набрали в чистом месте.
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

	// [Лорелея]: originalFetch объявлен в том же замыкании,
	// что и window.fetch, потому что иначе он не виден.
	var originalFetch = window.fetch.bind(window);

	var csrfTokenCache = null;

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