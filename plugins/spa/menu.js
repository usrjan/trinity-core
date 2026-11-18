/**
 * TRINITY MENU — УПРАВЛЕНИЕ ПУНКТАМИ МЕНЮ
 *
 * Добавление пункта, контекстное меню, перезагрузка сайдбара,
 * инициализация dropdown пользователя.
 */

(function () {
	'use strict';

	// ============================================
	// МОДАЛЬНОЕ ОКНО ДОБАВЛЕНИЯ ПУНКТА
	// ============================================

	/**
	 * Показать модальное окно добавления пункта меню.
	 */
	function showAddMenuItemModal() {
		var modal = document.getElementById('addMenuItemModal');
		if (modal) modal.style.display = 'flex';
	}

	/**
	 * Скрыть модальное окно добавления пункта меню.
	 */
	function closeAddMenuItemModal() {
		var modal = document.getElementById('addMenuItemModal');
		if (modal) modal.style.display = 'none';
	}

	/**
	 * Отправить запрос на добавление пункта меню.
	 */
	function addMenuItem() {
		var name = document.getElementById('menuItemName').value.trim();
		var slug = document.getElementById('menuItemSlug').value.trim();
		var icon = document.getElementById('menuItemIcon').value.trim();
		var route = document.getElementById('menuItemRoute').value.trim();
		var isPlugin = document.getElementById('menuItemIsPlugin').checked ? 1 : 0;

		if (!name || !slug) {
			alert('Название и slug обязательны');
			return;
		}

		fetch('/api/menu/add-item', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({
				name: name,
				slug: slug,
				icon: icon,
				route: route,
				is_plugin: isPlugin
			})
		})
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (d.success) {
				closeAddMenuItemModal();
				window.location.href = route;
			} else {
				alert(d.message || 'Ошибка');
			}
		});
	}

	// ============================================
	// КОНТЕКСТНОЕ МЕНЮ
	// ============================================

	/**
	 * Отслеживает правый клик на пунктах меню.
	 */
	document.addEventListener('contextmenu', function (e) {
		var link = e.target.closest('.sidebar-link');
		if (!link) return;
		if (link.classList.contains('sidebar-add-btn')) return;

		e.preventDefault();

		var id = link.getAttribute('data-menu-id');
		var slug = link.getAttribute('data-menu-slug');
		var name = link.querySelector('.menu-text') ? link.querySelector('.menu-text').textContent.trim() : '';

		if (!id) return;

		showContextMenu(e.pageX, e.pageY, id, slug, name);
	});

	/**
	 * Показывает контекстное меню по координатам.
	 */
	function showContextMenu(x, y, id, slug, name) {
		removeContextMenu();

		var menu = document.createElement('div');
		menu.className = 'menu-context-menu';
		menu.id = 'contextMenu';
		menu.style.cssText =
			'position:fixed; left:' + x + 'px; top:' + y + 'px; ' +
			'background:#1a1a1a; border:1px solid rgba(255,170,0,0.3); ' +
			'border-radius:8px; padding:0.5rem 0; min-width:180px; ' +
			'z-index:10000; box-shadow:0 10px 30px rgba(0,0,0,0.5);';

		menu.innerHTML =
			'<a class="topbar-dropdown-item" href="#" onclick="editMenuItem(' + id + ', \'' + escapeHtml(slug) + '\', \'' + escapeHtml(name) + '\'); removeContextMenu(); return false;">' +
			'<i class="bi bi-pencil gold-icon" style="width:20px;margin-right:8px;"></i>Редактировать' +
			'</a>' +
			'<a class="topbar-dropdown-item" href="#" onclick="deleteMenuItem(' + id + '); removeContextMenu(); return false;" style="color:#ff4444;">' +
			'<i class="bi bi-trash" style="width:20px;margin-right:8px;"></i>Удалить' +
			'</a>';

		document.body.appendChild(menu);

		setTimeout(function () {
			document.addEventListener('click', removeContextMenu, { once: true });
		}, 50);
	}

	/**
	 * Удаляет контекстное меню со страницы.
	 */
	function removeContextMenu() {
		var menu = document.getElementById('contextMenu');
		if (menu) menu.remove();
	}

	// ============================================
	// РЕДАКТИРОВАНИЕ И УДАЛЕНИЕ ПУНКТОВ
	// ============================================

	/**
	 * Редактирование пункта меню через prompt-диалоги.
	 */
	function editMenuItem(id, slug, name) {
		var newName = prompt('Название:', name);
		if (!newName) return;

		var newSlug = prompt('Slug:', slug);
		if (!newSlug) return;

		var newIcon = prompt('Иконка (Bootstrap Icons):', 'bi-file');
		var newRoute = prompt('Маршрут:', '/');

		fetch('/api/menu/update-item', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({
				id: id,
				name: newName,
				slug: newSlug,
				icon: newIcon || 'bi-file',
				route: newRoute || '/'
			})
		})
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (d.success) {
				reloadSidebar();
			} else {
				alert(d.message || 'Ошибка');
			}
		});
	}

	/**
	 * Удаление пункта меню.
	 */
	function deleteMenuItem(id) {
		if (!confirm('Удалить пункт меню?')) return;

		fetch('/api/admin/neuron/' + id, { method: 'DELETE' })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				if (d.success) {
					reloadSidebar();
				} else {
					alert(d.message || 'Ошибка');
				}
			});
	}

	/**
	 * Перезагружает боковое меню через API.
	 */
	function reloadSidebar() {
		fetch('/api/menu/sidebar')
			.then(function (r) { return r.text(); })
			.then(function (html) {
				document.getElementById('sidebar').innerHTML = html;
			});
	}

	// ============================================
	// DROPDOWN ПОЛЬЗОВАТЕЛЯ
	// ============================================

	/**
	 * Инициализирует dropdown-меню пользователя в верхней панели.
	 */
	function initUserDropdown() {
		var btn = document.getElementById('userDropdownBtn');
		var menu = document.getElementById('userDropdownMenu');

		if (!btn || !menu) return;

		var newBtn = btn.cloneNode(true);
		btn.parentNode.replaceChild(newBtn, btn);
		btn = newBtn;

		btn.addEventListener('click', function (e) {
			e.preventDefault();
			e.stopPropagation();
			menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
		});

		document.addEventListener('click', function (e) {
			if (!btn.contains(e.target) && !menu.contains(e.target)) {
				menu.style.display = 'none';
			}
		});
	}

	// ============================================
	// ВСПОМОГАТЕЛЬНЫЕ
	// ============================================

	/**
	 * Обновляет активный пункт меню по текущему URL.
	 */
	function updateActiveMenuItem(currentUrl) {
		var links = document.querySelectorAll('#sidebar .sidebar-link');
		links.forEach(function (link) {
			if (link.getAttribute('href') === currentUrl) {
				link.classList.add('active');
			} else {
				link.classList.remove('active');
			}
		});
	}

	/**
	 * Экранирует HTML-сущности.
	 */
	function escapeHtml(text) {
		if (!text) return '';
		var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
		return String(text).replace(/[&<>"']/g, function (m) { return map[m]; });
	}

	/**
	 * Инициализирует кнопку "Добавить пункт" в сайдбаре.
	 * Вызывается после того, как сайдбар загружен.
	 */
	function initAddMenuItemButton() {
		var btn = document.getElementById('addMenuItemBtn');
		if (btn) {
			btn.addEventListener('click', function(e) {
				e.preventDefault();
				showAddMenuItemModal();
			});
		}
	}

	// ============================================
	// СВОРАЧИВАНИЕ БОКОВОГО МЕНЮ
	// ============================================

	document.addEventListener('click', function (e) {
		if (e.target.closest('#sidebarToggle')) {
			var sidebar = document.getElementById('sidebar');
			if (sidebar) {
				sidebar.classList.toggle('collapsed');
			}
		}
	});

	// ============================================
	// ЭКСПОРТ В ГЛОБАЛЬНУЮ ОБЛАСТЬ
	// ============================================

	window.showAddMenuItemModal = showAddMenuItemModal;
	window.closeAddMenuItemModal = closeAddMenuItemModal;
	window.addMenuItem = addMenuItem;
	window.editMenuItem = editMenuItem;
	window.deleteMenuItem = deleteMenuItem;
	window.removeContextMenu = removeContextMenu;
	window.initUserDropdown = initUserDropdown;
	window.updateActiveMenuItem = updateActiveMenuItem;
	window.escapeHtml = escapeHtml;
	window.initAddMenuItemButton = initAddMenuItemButton;

})();