/**
 * TRINITY CORE 1.0 — SPA-ДВИЖОК
 * 
 * Обеспечивает навигацию без перезагрузки страницы.
 * При первом запросе сервер отдаёт пустой каркас с тремя зонами:
 * - #sidebar   — боковое меню (загружается через API)
 * - #topBar    — верхняя панель (загружается через API)
 * - #mainContent — контент страницы
 * 
 * Все последующие переходы — через AJAX, без перезагрузки.
 * Использует History API для работы кнопки "Назад".
 */

/** @var {string} Текущая страница */
var currentPage = 'home';

/** @var {boolean} Флаг загрузки скрипта админки */
window.adminScriptLoaded = false;

// ============================================
// 1. ИНИЦИАЛИЗАЦИЯ
// ============================================

document.addEventListener('DOMContentLoaded', function() {
	initApp();
	
	// Перехватываем все клики по ссылкам в боковом меню
	// чтобы предотвратить перезагрузку страницы
	document.addEventListener('click', function(e) {
		var link = e.target.closest('.sidebar-link');
		if (link && link.getAttribute('href') !== '#') {
			var url = link.getAttribute('href');
			if (url === '/admin') return; // пропускаем админку
			e.preventDefault();
			navigateTo(url);
		}
	});
});

/**
 * Первичная инициализация приложения.
 * Загружает боковое меню, верхнюю панель.
 * Если URL указывает на админку — загружает её сразу.
 */
function initApp() {
	loadSidebar();
	loadTopbar();

	// Если пользователь сразу перешёл на /admin — загружаем админку
	if (window.location.pathname === '/') {
		loadPage('/');
	}
}

// ============================================
// 2. ЗАГРУЗКА ЗОН
// ============================================

/**
 * Загружает HTML бокового меню через API и вставляет в #sidebar.
 */
function loadSidebar() {
	fetch('/api/menu/sidebar')
		.then(function(resp) { return resp.text(); })
		.then(function(html) {
			document.getElementById('sidebar').innerHTML = html;
		})
		.catch(function(err) {
			console.error('Trinity: ошибка загрузки меню:', err);
		});
}

/**
 * Загружает HTML верхней панели через API и вставляет в #topBar.
 */
function loadTopbar() {
    fetch('/api/menu/topbar')
        .then(function(resp) { return resp.text(); })
        .then(function(html) {
            var topbar = document.getElementById('topBar');
            topbar.innerHTML = html;
            
            // Инициализируем Alpine для topbar
            if (typeof Alpine !== 'undefined') {
                Alpine.initTree(topbar);
            } else {
				console.log('Alpine not available');
			}
        })
        .catch(function(err) {
            console.error('Trinity: ошибка загрузки верхней панели:', err);
        });
}

// ============================================
// 3. НАВИГАЦИЯ
// ============================================

/**
 * Переход на указанную страницу без перезагрузки.
 * Обновляет URL через History API.
 * 
 * @param {string} url — URL страницы (например, '/admin')
 */
function navigateTo(url) {
	currentPage = url;
	history.pushState({ page: url }, '', url);
	loadPage(url);
}

/**
 * Загружает контент страницы в #mainContent.
 * Для админки используется специальный API-эндпоинт /api/admin.
 * Для остальных страниц — прямой fetch URL.
 * 
 * @param {string} url — URL страницы
 */
function loadPage(url) {
	var main = document.getElementById('mainContent');
	
	// Показываем скелетон на время загрузки
	main.innerHTML = '<div class="skeleton-content"><div class="skeleton-line skeleton-line-long skeleton-line-heading"></div><div class="skeleton-line skeleton-line-full"></div><div class="skeleton-line skeleton-line-full"></div></div>';
	
	// --- ГЛАВНАЯ ---
	if (url === '/') {
		main.innerHTML = '<div class="p-4"><h1>Добро пожаловать в Trinity</h1><p class="lead">SPA-движок на трёх таблицах. Всё работает без перезагрузки.</p></div>';
		return;
	}

	// --- ОБЫЧНЫЕ СТРАНИЦЫ ---
	fetch(url)
		.then(function(resp) { return resp.text(); })
		.then(function(html) {
			main.innerHTML = '<div class="p-4">' + html + '</div>';
		})
		.catch(function() {
			main.innerHTML = '<div class="alert alert-danger m-4">Ошибка загрузки страницы</div>';
		});
}

// ============================================
// 4. ИСТОРИЯ БРАУЗЕРА
// ============================================

// Обработка кнопки "Назад" / "Вперёд"
window.addEventListener('popstate', function(event) {
	if (event.state && event.state.page) {
		currentPage = event.state.page;
		loadPage(event.state.page);
	}
});
