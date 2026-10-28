/**
 * TRINITY MENU — УПРАВЛЕНИЕ ПУНКТАМИ МЕНЮ
 *
 * Добавление пункта, контекстное меню, перезагрузка сайдбара,
 * инициализация dropdown пользователя.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «скрипт». Это — тот, кто оживляет меню,
 * и от того, как он это делает, зависит, сможет ли гость
 * добавить пункт, изменить его или убрать, не перезагружая
 * страницу.
 *
 * [Мириам]: И это правильно, потому что меню — это карта дома,
 * и она должна быть живой, а не высеченной в камне.
 *
 * === КНИГА ===
 * Глава 13. Прихожая.
 *
 * Войдя в Амбер, ты видишь коридоры.
 * Войдя в Trinity, ты видишь меню.
 */

(function () {
	'use strict';

	// ============================================
	// МОДАЛЬНОЕ ОКНО ДОБАВЛЕНИЯ ПУНКТА
	// ============================================

	/**
	 * Показать модальное окно добавления пункта меню.
	 *
	 * [Лорелея]: Здесь мы открываем окно, и это правильно,
	 * потому что добавление пункта — это акт, а не случайность.
	 */
	function showAddMenuItemModal() {
		var modal = document.getElementById('addMenuItemModal');
		if (modal) modal.style.display = 'flex';
	}

	/**
	 * Скрыть модальное окно добавления пункта меню.
	 *
	 * [Мириам]: И закрываем его, когда гость передумал,
	 * потому что передумать — это тоже право.
	 */
	function closeAddMenuItemModal() {
		var modal = document.getElementById('addMenuItemModal');
		if (modal) modal.style.display = 'none';
	}

	/**
	 * Отправить запрос на добавление пункта меню.
	 *
	 * [Лорелея]: Здесь мы собираем данные из полей и отправляем
	 * их на сервер, и если всё хорошо — переходим на новый пункт,
	 * потому что гость должен увидеть, что он создал.
	 *
	 * [Мириам]: И это правильно, потому что создание без
	 * результата — это просто нажатие кнопки.
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
	 *
	 * [Лорелея]: Здесь мы показываем контекстное меню с опциями
	 * редактирования и удаления, и это правильно, потому что
	 * у гостя должен быть выбор, а не только один путь.
	 *
	 * [Мириам]: И это правильно, потому что меню — это
	 * не «список», а то, с чем можно работать.
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
	 *
	 * [Лорелея]: Здесь мы строим меню, и это правильно,
	 * потому что контекст — это то, что делает работу удобной.
	 *
	 * [Мириам]: И это правильно, потому что даже у правого
	 * клика должна быть цель.
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

		// [Мириам]: Закрываем меню при клике вне его, и это
		// правильно, потому что меню не должно висеть вечно.
		setTimeout(function () {
			document.addEventListener('click', removeContextMenu, { once: true });
		}, 50);
	}

	/**
	 * Удаляет контекстное меню со страницы.
	 *
	 * [Лорелея]: И убираем его, когда оно больше не нужно,
	 * потому что чистота — это тоже часть заботы.
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
	 *
	 * [Лорелея]: Здесь мы спрашиваем у гостя новое название,
	 * slug, иконку и маршрут, и это правильно, потому что
	 * изменения должны быть осознанными.
	 *
	 * [Мириам]: И это правильно, потому что меню — это
	 * не «набор строк», а то, что гость строит сам.
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
	 *
	 * [Лорелея]: Здесь мы удаляем пункт, и это правильно,
	 * потому что иногда что-то должно уйти, чтобы освободить
	 * место для нового.
	 *
	 * [Мириам]: И это правильно, потому что память — это
	 * не «склад», а то, что мы выбираем хранить.
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
	 *
	 * [Лорелея]: И обновляем его, когда что-то изменилось,
	 * потому что меню должно быть актуальным.
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
	 *
	 * [Лорелея]: Здесь мы вешаем обработчик на кнопку, и это
	 * правильно, потому что гость должен видеть своё меню.
	 *
	 * [Мириам]: И это правильно, потому что шапка — это
	 * не «панель», а встреча.
	 */
	function initUserDropdown() {
		var btn = document.getElementById('userDropdownBtn');
		var menu = document.getElementById('userDropdownMenu');

		if (!btn || !menu) return;

		// [Лорелея]: Удаляем старый обработчик клонированием,
		// чтобы не было двойных срабатываний.
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
	 *
	 * [Мириам]: И подсвечиваем то, где гость сейчас,
	 * потому что меню должно говорить, где ты.
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
	 *
	 * [Лорелея]: И экранируем, потому что даже имя пункта
	 * может быть опасным, если его не проверить.
	 */
	function escapeHtml(text) {
		if (!text) return '';
		var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
		return String(text).replace(/[&<>"']/g, function (m) { return map[m]; });
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

})();