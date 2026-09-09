/**
 * АДМИНКА TRINITY — vanilla JS
 * 
 * Управление нейронами: иерархическое дерево с разворачиванием,
 * просмотр, создание, редактирование, удаление, импорт.
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
	 */
	function init() {
		treePanel = document.getElementById('neuronTree');
		neuronPanel = document.getElementById('neuronPanel');

		loadTypes();
		loadTree();
		loadDashboard();
	}

	function loadDashboard() {
		fetch('/api/admin/dashboard')
			.then(function(r) { return r.json(); })
			.then(function(d) {
				if (d.success && neuronPanel) {
					neuronPanel.innerHTML = d.data.html;
				}
			});
	}

	/**
	 * Загружает список типов нейронов.
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
	 * Отрисовывает дерево нейронов.
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
		treePanel.innerHTML = html;
	}

	/**
	 * Отрисовывает один узел дерева.
	 */
	function renderNode(node, level) {
		var name = node.display_name || getSlug(node) || 'n' + node.id;
		var hasChildren = node.has_children || (node.children && node.children.length > 0);
		var expanded = node.expanded || false;
		var isActive = selectedNeuron && selectedNeuron.id === node.id;

		var html = '<div class="tree-node" data-id="' + node.id + '">';
		html += '<div class="tree-node-content' + (isActive ? ' active' : '') + '" onclick="adminSelectNeuron(' + node.id + ')" style="padding-left:' + (5 + level * 5) + 'px;">';

		// Значок для всех
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

		// Дети (если развёрнуты)
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
	 * Разворачивает/сворачивает узел.
	 */
	window.adminToggleChildren = function(id) {
		var node = findNode(tree, id);
		if (!node) return;

		if (node.expanded) {
			// Сворачиваем
			node.expanded = false;
			renderTree();
			return;
		}

		// Загружаем детей с сервера
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
	 * Ищет узел в дереве по id.
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
	 */
	function renderNeuronPanel() {
		if (!neuronPanel || !selectedNeuron) return;

		var html = '';

		// Хлебные крошки
		html += '<div class="breadcrumb-neuron">';
		html += '<strong>' + escapeHtml(getDisplayName(selectedNeuron)) + '</strong>';
		html += '<span class="badge bg-secondary ms-2">' + escapeHtml(selectedNeuron.type) + '</span>';
		html += '<span class="text-muted ms-2">ID: ' + selectedNeuron.id + '</span>';
		html += '</div>';

		// Кнопки действий
		html += '<div class="neuron-actions">';
		html += '<button class="btn btn-sm btn-outline-gold" onclick="adminStartEdit()"><i class="bi bi-pencil"></i> Редактировать</button>';
		html += '<button class="btn btn-sm btn-outline-gold" onclick="adminCreateNeuron(' + selectedNeuron.id + ')"><i class="bi bi-plus-lg"></i> Создать дочерний</button>';
		html += '<button class="btn btn-sm btn-outline-danger" onclick="adminDeleteNeuron(' + selectedNeuron.id + ')"><i class="bi bi-trash"></i> Удалить</button>';
		html += '</div>';

		if (!editing) {
			// Основные свойства
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

			// JSON Data
			html += '<div class="section-card">';
			html += '<div class="section-header">JSON Data</div>';
			html += '<div class="section-body">';
			html += '<pre class="json-preview">' + JSON.stringify(selectedNeuron.data, null, 2) + '</pre>';
			html += '</div></div>';

			// Тексты
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

			// Дочерние нейроны
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
			// Режим редактирования
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

		neuronPanel.innerHTML = html;
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
				// Обновляем родительский узел
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
	 */
	window.adminCancelEdit = function() {
		editing = false;
		renderNeuronPanel();
	};

	/**
	 * Сохранение изменений.
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
	 */
	window.importExcel = function(file) {
		if (!file) return;
		if (!confirm('Импортировать файл ' + file.name + '?')) {
			document.getElementById('importFile').value = '';
			return;
		}

		var formData = new FormData();
		formData.append('file', file);

		// Находим кнопку импорта и блокируем
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

	/**
	 * Экранирует HTML.
	 */
	function escapeHtml(text) {
		if (!text) return '';
		var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
		return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
	}

	// Старт
	document.addEventListener('DOMContentLoaded', init);
})();