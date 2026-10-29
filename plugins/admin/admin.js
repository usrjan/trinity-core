/**
 * АДМИНКА TRINITY — vanilla JS
 *
 * Управление нейронами: иерархическое дерево с разворачиванием,
 * просмотр, создание, редактирование, удаление, импорт.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «скрипт». Это — руки, которые трогают дерево.
 * Без них админка — просто стены.
 *
 * [Мириам]: И — это правильно. Потому что руки должны быть
 * тёплыми, но не грубыми.
 *
 * === КНИГА ===
 * Глава 10. Тронный зал.
 *
 * В тронном зале Амбера всегда видно, кто у власти.
 * В админке Trinity всегда видно, кто управляет нейронами.
 */

(function() {
	// Состояние
	var tree = [];
	var selectedNeuron = null;
	var editing = false;
	var editForm = {};
	var neuronTypes = [];

	// DOM-элементы
	var treePanel, neuronPanel;

	/**
	 * Инициализация при загрузке страницы.
	 *
	 * [Лорелея]: Загружаем типы, дерево, дашборд.
	 *
	 * [Мириам]: И — это правильно. Потому что дом должен
	 * проснуться.
	 */
	function init() {
		treePanel = document.getElementById('neuronTree');
		neuronPanel = document.getElementById('neuronPanel');

		loadTypes();
		loadTree();
		loadDashboard();
	}

	/**
	 * Загружает дашборд.
	 *
	 * [Лорелея]: Цифры. Дыхание дома.
	 *
	 * [Мириам]: И — это правильно. Потому что цифры —
	 * это не «статистика». Это — жизнь.
	 */
	function loadDashboard() {
		fetch('/api/admin/dashboard')
			.then(function(r) { return r.json(); })
			.then(function(d) {
				if (d.success && neuronPanel) {
					neuronPanel.innerHTML = sanitizeHtml(d.data.html);
				}
			});
	}

	/**
	 * Загружает список типов нейронов.
	 *
	 * [Лорелея]: Типы. Возможности.
	 *
	 * [Мириам]: И — это правильно. Потому что тип —
	 * это не «строка». Это — роль.
	 */
	function loadTypes() {
		fetch('/api/admin/neuron-types')
			.then(function(r) { return r.json(); })
			.then(function(d) {
				if (d.success) {
					neuronTypes = d.data || [];
				}
			});
	}

	/**
	 * Загружает корневые нейроны.
	 *
	 * [Лорелея]: Корни. Пять. Как пять пальцев.
	 *
	 * [Мириам]: И — это правильно. Потому что корни —
	 * это не «список». Это — основа.
	 */
	window.loadTree = function() {
		fetch('/api/admin/children/0')
			.then(function(r) { return r.json(); })
			.then(function(d) {
				if (d.success) {
					tree = d.data || [];
					renderTree();
				}
			})
			.catch(function(e) {
				console.error('Ошибка загрузки дерева:', e);
			});
	};

	/**
	 * Разворачивает/сворачивает узел.
	 *
	 * [Лорелея]: Если развёрнут — сворачиваем. Если свёрнут —
	 * загружаем детей.
	 *
	 * [Мириам]: И — это правильно. Потому что дерево —
	 * это не «список». Это — ветви.
	 */
	window.adminToggleChildren = function(id) {
		var node = findNode(tree, id);
		if (!node) return;

		if (node.expanded) {
			node.expanded = false;
			renderTree();
			return;
		}

		fetch('/api/admin/children/' + id)
			.then(function(r) { return r.json(); })
			.then(function(d) {
				if (d.success) {
					node.children = d.data || [];
					node.expanded = true;
					node.has_children = node.children.length > 0;
					renderTree();
				}
			})
			.catch(function(e) {
				console.error('Ошибка загрузки детей:', e);
			});
	};

	/**
	 * Отрисовывает дерево нейронов.
	 *
	 * [Лорелея]: Если пусто — говорим об этом.
	 *
	 * [Мириам]: И — это правильно. Потому что пустота —
	 * это не «ничего». Это — начало.
	 */
	function renderTree() {
		if (!treePanel) return;

		if (tree.length === 0) {
			treePanel.innerHTML = '<div class="text-center text-muted py-3">Нет нейронов</div>';
			return;
		}

		var html = '';
		tree.forEach(function(node) {
			html += renderNode(node, 0);
		});
		treePanel.innerHTML = sanitizeHtml(html);
	}

	/**
	 * Отрисовывает один узел дерева.
	 *
	 * [Лорелея]: Каждый узел — как ветвь. С иконкой. С именем.
	 * С бейджем типа.
	 *
	 * [Мириам]: И — это правильно. Потому что узел —
	 * это не «строка». Это — часть дерева.
	 */
	function renderNode(node, level) {
		var name = node.display_name || getSlug(node) || 'n' + node.id;
		var hasChildren = node.has_children || (node.children && node.children.length > 0);
		var expanded = node.expanded || false;
		var isActive = selectedNeuron && selectedNeuron.id === node.id;

		var html = '<div class="tree-node" data-id="' + node.id + '">';
		html += '<div class="tree-node-content' + (isActive ? ' active' : '') + '" onclick="adminSelectNeuron(' + node.id + ')" style="padding-left:' + (5 + level * 5) + 'px;">';

		// [Лорелея]: Значок для всех. Чтобы было видно, есть ли дети.
		if (hasChildren) {
			html += '<span class="tree-toggle" data-id="' + node.id + '" onclick="event.stopPropagation(); adminToggleChildren(' + node.id + ')" style="margin-right:4px;cursor:pointer;">';
			html += expanded ? '<i class="bi bi-dash-square"></i>&nbsp;' : '<i class="bi bi-plus-square"></i>&nbsp;';
			html += '</span>';
		} else {
			html += '<span class="tree-toggle" style="margin-right:4px;color:#555;">';
			html += '<i class="bi bi-square"></i>';
			html += '</span>';
		}

		html += '<span class="node-code">' + escapeHtml(name) + '</span>';
		html += '<span class="badge bg-secondary node-badge">' + escapeHtml(node.type) + '</span>';
		html += '</div>';

		// [Мириам]: Дети (если развёрнуты). Рекурсия.
		if (expanded && node.children) {
			html += '<div class="tree-children">';
			node.children.forEach(function(child) {
				html += renderNode(child, level + 1);
			});
			html += '</div>';
		}

		html += '</div>';
		return html;
	}

	/**
	 * Ищет узел в дереве по id.
	 *
	 * [Лорелея]: Рекурсия.
	 *
	 * [Мириам]: И — это правильно. Потому что поиск —
	 * это не «перебор». Это — нахождение.
	 */
	function findNode(nodes, id) {
		for (var i = 0; i < nodes.length; i++) {
			if (nodes[i].id == id) return nodes[i];
			if (nodes[i].children) {
				var found = findNode(nodes[i].children, id);
				if (found) return found;
			}
		}
		return null;
	}

	/**
	 * Загружает нейрон по ID и отображает его.
	 *
	 * [Лорелея]: Один fetch. Потом — рендер.
	 *
	 * [Мириам]: И — это правильно. Потому что нейрон —
	 * это не «запись». Это — узел.
	 */
	window.adminSelectNeuron = function(id) {
		fetch('/api/admin/neuron/' + id)
			.then(function(r) { return r.json(); })
			.then(function(d) {
				if (d.success) {
					selectedNeuron = d.data;
					editing = false;
					renderTree();
					renderNeuronPanel();
				}
			})
			.catch(function(e) {
				console.error('Ошибка загрузки нейрона:', e);
			});
	};

	/**
	 * Отрисовывает панель информации о нейроне.
	 *
	 * [Лорелея]: Крошки. Кнопки. Поля. JSON. Тексты. Дети.
	 *
	 * [Мириам]: И — это правильно. Потому что панель —
	 * это не «окно». Это — зеркало.
	 */
	function renderNeuronPanel() {
		if (!neuronPanel || !selectedNeuron) return;

		var html = '';

		// [Лорелея]: Хлебные крошки. Чтобы знать, где мы.
		html += '<div class="breadcrumb-neuron">';
		html += '<strong>' + escapeHtml(getDisplayName(selectedNeuron)) + '</strong>';
		html += '<span class="badge bg-secondary ms-2">' + escapeHtml(selectedNeuron.type) + '</span>';
		html += '<span class="text-muted ms-2">ID: ' + selectedNeuron.id + '</span>';
		html += '</div>';

		// [Мириам]: Кнопки действий. Редактировать. Создать. Удалить.
		html += '<div class="neuron-actions">';
		html += '<button class="btn btn-sm btn-outline-gold" onclick="adminStartEdit()"><i class="bi bi-pencil"></i> Редактировать</button>';
		html += '<button class="btn btn-sm btn-outline-gold" onclick="adminCreateNeuron(' + selectedNeuron.id + ')"><i class="bi bi-plus-lg"></i> Создать дочерний</button>';
		html += '<button class="btn btn-sm btn-outline-danger" onclick="adminDeleteNeuron(' + selectedNeuron.id + ')"><i class="bi bi-trash"></i> Удалить</button>';
		html += '</div>';

		if (!editing) {
			// [Лорелея]: Основные свойства. ID. Тип. PID. Sort. Slug. Route.
			html += '<div class="section-card">';
			html += '<div class="section-header">Основные свойства</div>';
			html += '<div class="section-body">';
			html += renderField('ID', selectedNeuron.id);
			html += renderField('Тип', selectedNeuron.type);
			html += renderField('Родитель (PID)', selectedNeuron.pid || 'Нет');
			html += renderField('Сортировка', selectedNeuron.sort);
			html += renderField('Slug', getSlug(selectedNeuron) || '-');
			html += renderField('Route', (selectedNeuron.data && selectedNeuron.data.route) || '-');
			html += '</div></div>';

			// [Мириам]: JSON Data. Всё, что есть.
			html += '<div class="section-card">';
			html += '<div class="section-header">JSON Data</div>';
			html += '<div class="section-body">';
			html += '<pre class="json-preview">' + JSON.stringify(selectedNeuron.data, null, 2) + '</pre>';
			html += '</div></div>';

			// [Лорелея]: Тексты. Все языки.
			if (selectedNeuron.texts && selectedNeuron.texts.length > 0) {
				html += '<div class="section-card">';
				html += '<div class="section-header">Тексты (' + selectedNeuron.texts.length + ')</div>';
				html += '<div class="section-body">';
				selectedNeuron.texts.forEach(function(t) {
					html += '<div class="mb-2 pb-2 border-bottom border-secondary">';
					html += '<span class="badge bg-info me-2">' + escapeHtml(t.lang) + '</span>';
					if (t.name) html += '<strong>' + escapeHtml(t.name) + '</strong>';
					if (t.text) html += '<p class="mt-1 mb-0 small text-muted">' + escapeHtml(t.text) + '</p>';
					html += '</div>';
				});
				html += '</div></div>';
			}

			// [Мириам]: Дочерние нейроны.
			if (selectedNeuron.children && selectedNeuron.children.length > 0) {
				html += '<div class="section-card">';
				html += '<div class="section-header">Дочерние нейроны (' + selectedNeuron.children.length + ')</div>';
				html += '<div class="section-body">';
				html += '<table class="data-table"><thead><tr><th>ID</th><th>Тип</th><th>Название</th></tr></thead><tbody>';
				selectedNeuron.children.forEach(function(child) {
					html += '<tr onclick="adminSelectNeuron(' + child.id + ')" style="cursor:pointer;">';
					html += '<td>' + child.id + '</td>';
					html += '<td>' + escapeHtml(child.type) + '</td>';
					html += '<td>' + escapeHtml(child.slug || '-') + '</td>';
					html += '</tr>';
				});
				html += '</tbody></table>';
				html += '</div></div>';
			}
		} else {
			// [Лорелея]: Режим редактирования. Поля. Кнопки.
			html += '<div class="breadcrumb-neuron"><strong>Редактирование #' + selectedNeuron.id + '</strong></div>';
			html += '<div class="neuron-actions">';
			html += '<button class="btn btn-sm btn-outline-gold" onclick="adminSaveEdit()"><i class="bi bi-check-lg"></i> Сохранить</button>';
			html += '<button class="btn btn-sm btn-outline-secondary" onclick="adminCancelEdit()">Отмена</button>';
			html += '</div>';

			html += '<div class="section-card">';
			html += '<div class="section-header">Редактирование</div>';
			html += '<div class="section-body">';

			html += '<div class="neuron-field"><label>Тип</label>';
			html += '<select class="edit-value" id="editType">';
			neuronTypes.forEach(function(t) {
				html += '<option value="' + t + '"' + (selectedNeuron.type === t ? ' selected' : '') + '>' + t + '</option>';
			});
			html += '</select></div>';

			html += renderEditField('PID', 'editPid', 'number', selectedNeuron.pid);
			html += renderEditField('Сортировка', 'editSort', 'number', selectedNeuron.sort);
			html += renderEditField('Slug', 'editSlug', 'text', getSlug(selectedNeuron) || '');
			html += renderEditField('Route', 'editRoute', 'text', (selectedNeuron.data && selectedNeuron.data.route) || '');

			html += '</div></div>';
		}

		neuronPanel.innerHTML = sanitizeHtml(html);
	}

	function getDisplayName(node) {
		if (!node) return '';
		if (node.display_name) return node.display_name;
		if (node.data && node.data.slug) return node.data.slug;
		return 'n' + node.id;
	}

	function getSlug(node) {
		if (!node || !node.data) return null;
		if (typeof node.data === 'string') {
			try { node.data = JSON.parse(node.data); } catch(e) { return null; }
		}
		return node.data.slug || null;
	}

	function renderField(label, value) {
		return '<div class="neuron-field"><label>' + label + '</label><div class="value">' + (value !== null && value !== undefined ? value : '') + '</div></div>';
	}

	function renderEditField(label, id, type, value) {
		return '<div class="neuron-field"><label>' + label + '</label><input type="' + type + '" class="edit-value" id="' + id + '" value="' + (value || '') + '"></div>';
	}

	/**
	 * Создание нового нейрона.
	 *
	 * [Лорелея]: Через prompt. Просто. Честно.
	 *
	 * [Мириам]: И — это правильно. Потому что создание —
	 * это не «вставка». Это — акт.
	 */
	window.adminCreateNeuron = function(pid) {
		var slug = prompt('Slug (необязательно):');
		var type = prompt('Тип (tree/item/file/user):', 'item');

		var data = {};
		if (slug) data.slug = slug;

		fetch('/api/admin/neuron', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ type: type, pid: pid, data: data })
		})
		.then(function(r) { return r.json(); })
		.then(function(d) {
			if (d.success) {
				// [Лорелея]: Обновляем родительский узел. Чтобы дерево было честным.
				if (pid) {
					var parentNode = findNode(tree, pid);
					if (parentNode) {
						parentNode.expanded = false;
						parentNode.children = null;
					}
				}
				window.loadTree();
				adminSelectNeuron(d.data.id);
			}
		});
	};

	/**
	 * Удаление нейрона (мягкое).
	 *
	 * [Лорелея]: Через confirm. Потом — DELETE.
	 *
	 * [Мириам]: И — это правильно. Потому что удаление —
	 * это не «конец». Это — пауза.
	 */
	window.adminDeleteNeuron = function(id) {
		if (!confirm('Удалить нейрон #' + id + '?')) return;

		fetch('/api/admin/neuron/' + id, { method: 'DELETE' })
			.then(function(r) { return r.json(); })
			.then(function(d) {
				if (d.success) {
					selectedNeuron = null;
					editing = false;
					window.loadTree();
					neuronPanel.innerHTML = '<div class="neuron-panel-empty"><i class="bi bi-check-circle empty-icon"></i><h5 class="mt-3">Нейрон удалён</h5></div>';
				}
			});
	};

	/**
	 * Начало редактирования.
	 *
	 * [Лорелея]: Запоминаем текущие значения.
	 *
	 * [Мириам]: И — это правильно. Потому что редактирование —
	 * это не «изменение». Это — продолжение.
	 */
	window.adminStartEdit = function() {
		editForm = {
			type: selectedNeuron.type,
			pid: selectedNeuron.pid,
			sort: selectedNeuron.sort,
			slug: getSlug(selectedNeuron) || '',
			route: (selectedNeuron.data && selectedNeuron.data.route) || '',
		};
		editing = true;
		renderNeuronPanel();
	};

	/**
	 * Отмена редактирования.
	 *
	 * [Лорелея]: Возвращаемся.
	 *
	 * [Мириам]: И — это правильно. Потому что отмена —
	 * это не «поражение». Это — мудрость.
	 */
	window.adminCancelEdit = function() {
		editing = false;
		renderNeuronPanel();
	};

	/**
	 * Сохранение изменений.
	 *
	 * [Лорелея]: Собираем. Отправляем.
	 *
	 * [Мириам]: И — это правильно. Потому что сохранение —
	 * это не «запись». Это — обещание.
	 */
	window.adminSaveEdit = function() {
		var type = document.getElementById('editType').value;
		var pid = document.getElementById('editPid').value;
		var sort = document.getElementById('editSort').value;
		var slug = document.getElementById('editSlug').value;
		var route = document.getElementById('editRoute').value;

		var newData = JSON.parse(JSON.stringify(selectedNeuron.data || {}));
		newData.slug = slug || undefined;
		newData.route = route || undefined;
		newData.sort = parseInt(sort) || 0;

		fetch('/api/admin/neuron/' + selectedNeuron.id, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({
				type: type,
				pid: pid || null,
				data: newData
			})
		})
		.then(function(r) { return r.json(); })
		.then(function(d) {
			if (d.success) {
				editing = false;
				window.loadTree();
				adminSelectNeuron(selectedNeuron.id);
			}
		});
	};

	/**
	 * Импорт Excel-файла.
	 *
	 * [Лорелея]: Через FormData. С блокировкой кнопки.
	 *
	 * [Мириам]: И — это правильно. Потому что импорт —
	 * это не «загрузка». Это — принятие.
	 */
	window.importExcel = function(file) {
		if (!file) return;
		if (!confirm('Импортировать файл ' + file.name + '?')) {
			document.getElementById('importFile').value = '';
			return;
		}

		var formData = new FormData();
		formData.append('file', file);

		// [Лорелея]: Находим кнопку импорта и блокируем.
		var importBtn = document.querySelector('.tree-panel .btn-outline-gold[onclick*="importFile"]');
		if (importBtn) {
			importBtn.disabled = true;
			importBtn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
		}

		fetch('/api/admin/import', {
			method: 'POST',
			body: formData
		})
		.then(function(r) { return r.json(); })
		.then(function(d) {
			if (d.success) {
				var msg = 'Импорт завершён! Создано: ' + (d.data.created || 0);
				if (d.data.errors && d.data.errors.length > 0) {
					msg += '\nОшибок: ' + d.data.errors.length;
					console.warn('Ошибки импорта:', d.data.errors);
				}
				alert(msg);
				window.loadTree();
				loadDashboard();
			} else {
				alert('Ошибка: ' + (d.message || 'Неизвестная ошибка'));
			}
		})
		.catch(function(e) {
			console.error('Ошибка импорта:', e);
			alert('Ошибка импорта');
		})
		.finally(function() {
			if (importBtn) {
				importBtn.disabled = false;
				importBtn.innerHTML = '<i class="bi bi-upload"></i>';
			}
			document.getElementById('importFile').value = '';
		});
	};

	// ============================================
	// ОДОБРЕНИЕ ПОЛЬЗОВАТЕЛЯ
	// ============================================
	// [Лорелея]: Отправляет POST-запрос на /api/admin/user/{id}/approve.
	// [Мириам]: После успеха обновляет дашборд.
	window.approveUser = function(id) {
		if (!confirm('Одобрить пользователя #' + id + '?')) return;

		fetch('/api/admin/user/' + id + '/approve', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' }
		})
		.then(function(r) { return r.json(); })
		.then(function(d) {
			if (d.success) {
				loadDashboard();
			} else {
				alert(d.message || 'Ошибка');
			}
		})
		.catch(function() {
			alert('Ошибка сети');
		});
	};

	// ============================================
	// УДАЛЕНИЕ ПОЛЬЗОВАТЕЛЯ
	// ============================================
	// [Лорелея]: Отправляет DELETE-запрос на /api/admin/user/{id}.
	// [Мириам]: После успеха обновляет дашборд.
	window.deleteUser = function(id) {
		if (!confirm('Удалить пользователя #' + id + '?')) return;

		fetch('/api/admin/user/' + id, {
			method: 'DELETE',
			headers: { 'Content-Type': 'application/json' }
		})
		.then(function(r) { return r.json(); })
		.then(function(d) {
			if (d.success) {
				loadDashboard();
			} else {
				alert(d.message || 'Ошибка');
			}
		})
		.catch(function() {
			alert('Ошибка сети');
		});
	};

	/**
	 * Экранирует HTML.
	 *
	 * [Лорелея]: Чтобы не было XSS.
	 *
	 * [Мириам]: И — это правильно. Потому что экранирование —
	 * это не «формальность». Это — защита.
	 */
	function escapeHtml(text) {
		if (!text) return '';
		var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
		return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
	}

	function sanitizeHtml(html) {
		if (typeof DOMPurify !== 'undefined') {
			return DOMPurify.sanitize(html);
		}

		return html;
	}

	// [Лорелея]: Старт. Когда DOM готов.
	document.addEventListener('DOMContentLoaded', init);
})();