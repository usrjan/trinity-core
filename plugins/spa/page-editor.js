/**
 * INLINE-РЕДАКТОР СТРАНИЦ
 *
 * Доступен только админам. Позволяет править секции и подстраницы
 * прямо на месте, не уходя со страницы.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «скрипт». Это — тот, кто даёт возможность
 * править страницы прямо на месте, и от того, как он это делает,
 * зависит, почувствует ли гость, что дом можно менять,
 * не уходя из него.
 *
 * [Мириам]: И это правильно, потому что дом, который нельзя
 * поправить, остаётся чужим, сколько бы времени ты в нём
 * ни провёл.
 *
 * === КНИГА ===
 * Глава 13. Прихожая.
 *
 * Войдя в Амбер, ты видишь коридоры.
 * Войдя в Trinity, ты можешь их перестроить.
 */

var editMode = false;
var currentSlug = window.location.pathname.replace(/^\//, '');

/**
 * Переключает режим редактирования.
 *
 * [Лорелея]: Здесь мы показываем или скрываем inline-редакторы,
 * и это правильно, потому что правка должна быть осознанной,
 * а не случайной.
 */
function toggleEditMode() {
	editMode = !editMode;
	document.querySelectorAll('.section-inline-edit').forEach(function(el) {
		el.style.display = editMode ? 'block' : 'none';
	});
	var form = document.getElementById('addSectionForm');
	if (form) form.style.display = editMode ? 'block' : 'none';
}

/**
 * Сохраняет секцию.
 *
 * [Лорелея]: Здесь мы собираем все тексты секции и отправляем
 * их на сервер, и если всё хорошо — перезагружаем страницу,
 * потому что гость должен увидеть, что он изменил.
 *
 * [Мириам]: И это правильно, потому что правка без результата —
 * это просто нажатие кнопки.
 */
function saveSection(btn, sectionId) {
	var card = btn.closest('.section-card');
	var sortInput = card.querySelector('.section-inline-edit input[type="number"]');
	var sort = parseInt(sortInput.value);

	var texts = [];
	var entries = card.querySelectorAll('.section-inline-edit .text-entry-item');

	entries.forEach(function (entry) {
		var lang = entry.querySelector('.text-lang').value.trim() || 'ru';
		var name = entry.querySelector('.text-name').value.trim();
		var text = entry.querySelector('.text-text').value.trim();

		if (name || text) {
			texts.push({
				lang: lang,
				name: name || null,
				text: text || null
			});
		}
	});

	var saveBtn = btn;
	var originalHTML = saveBtn.innerHTML;
	saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
	saveBtn.disabled = true;

	fetch('/api/page/section/' + sectionId + '/update-full', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify({
			sort: sort,
			texts: texts
		})
	})
	.then(function (r) { return r.json(); })
	.then(function (d) {
		if (d.success) {
			editMode = false;
			loadPage(window.location.pathname);
		} else {
			alert(d.message || 'Ошибка');
			saveBtn.innerHTML = originalHTML;
			saveBtn.disabled = false;
		}
	})
	.catch(function () {
		alert('Ошибка сети');
		saveBtn.innerHTML = originalHTML;
		saveBtn.disabled = false;
	});
}

/**
 * Удаляет секцию.
 *
 * [Лорелея]: Здесь мы удаляем секцию, и это правильно,
 * потому что иногда что-то должно уйти, чтобы освободить
 * место для нового.
 *
 * [Мириам]: И это правильно, потому что память — это
 * не «склад», а то, что мы выбираем хранить.
 */
function deleteSection(sectionId) {
	if (!confirm('Удалить секцию?')) return;

	var card = document.querySelector('.section-card[data-section-id="' + sectionId + '"]');

	fetch('/api/page/section/' + sectionId + '/delete', { method: 'POST' })
		.then(function(r) { return r.json(); })
		.then(function(d) {
			if (d.success) {
				if (card) {
					card.style.opacity = '0';
					card.style.transition = 'opacity 0.3s';
					setTimeout(function() {
						card.remove();

						var remainingCards = document.querySelectorAll('.section-card');
						if (remainingCards.length === 0) {
							var container = document.querySelector('.p-4');
							var alertHTML = '<div class="alert alert-info"><i class="bi bi-info-circle"></i> На этой странице пока нет контента.</div>';
							container.insertAdjacentHTML('beforeend', sanitizeHtml(alertHTML));
						}
					}, 300);
				}
			} else {
				alert(d.message || 'Ошибка');
			}
		});
}

/**
 * Получает id страницы из мета-тега.
 *
 * [Мириам]: И это правильно, потому что id — это
 * не «догадка», а точное знание.
 */
function getPageId() {
	var meta = document.querySelector('meta[name="page-id"]');
	return meta ? meta.getAttribute('content') : null;
}

/**
 * Добавляет новую секцию на страницу.
 *
 * [Лорелея]: Здесь мы собираем все тексты и отправляем их
 * на сервер, и если всё хорошо — обновляем страницу, потому
 * что гость должен увидеть, что он создал.
 *
 * [Мириам]: И это правильно, потому что создание без
 * результата — это просто нажатие кнопки.
 */
function addSection() {
	var pageId = getPageId();
	if (!pageId) {
		alert('Не удалось определить ID страницы');
		return;
	}

	var addBtn = document.querySelector('#addSectionForm .btn-outline-gold:last-child');
	var originalHTML = addBtn.innerHTML;
	addBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
	addBtn.disabled = true;

	var texts = [];

	var mainName = document.getElementById('newName').value.trim();
	var mainText = document.getElementById('newText').value.trim();
	var mainLang = document.getElementById('newLang').value.trim() || 'ru';

	if (mainName || mainText) {
		texts.push({
			lang: mainLang,
			name: mainName || null,
			text: mainText || null
		});
	}

	var entries = document.querySelectorAll('#newSectionTexts .text-entry-item');
	entries.forEach(function (entry) {
		var name = entry.querySelector('.text-name').value.trim();
		var text = entry.querySelector('.text-text').value.trim();
		var lang = entry.querySelector('.text-lang').value.trim() || 'ru';

		if (name || text) {
			texts.push({
				lang: lang,
				name: name || null,
				text: text || null
			});
		}
	});

	if (texts.length === 0) {
		alert('Добавьте хотя бы один текст');
		addBtn.innerHTML = originalHTML;
		addBtn.disabled = false;
		return;
	}

	var sort = document.getElementById('newSort').value;

	fetch('/api/page/section/' + pageId + '/add', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify({
			sort: parseInt(sort),
			texts: texts
		})
	})
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (d.success) {
				document.getElementById('newName').value = '';
				document.getElementById('newText').value = '';
				document.getElementById('newSort').value = '100';
				document.getElementById('newSectionTexts').innerHTML = '';

				loadPage(window.location.pathname);

				addBtn.innerHTML = '<i class="bi bi-check-lg"></i>';
				setTimeout(function () {
					addBtn.innerHTML = originalHTML;
					addBtn.disabled = false;
				}, 1500);
			} else {
				alert(d.message || 'Ошибка');
				addBtn.innerHTML = originalHTML;
				addBtn.disabled = false;
			}
		})
		.catch(function () {
			alert('Ошибка сети');
			addBtn.innerHTML = originalHTML;
			addBtn.disabled = false;
		});
}

/**
 * Добавляет блок текста к существующей секции в режиме редактирования.
 *
 * [Лорелея]: Здесь мы добавляем новый блок, и это правильно,
 * потому что секция может расти, как история.
 *
 * [Мириам]: И это правильно, потому что у истории может быть
 * продолжение, и оно должно быть возможно.
 */
function addTextToSection(btn, sectionId) {
	var card = btn.closest('.section-card');
	var inlineEdit = card.querySelector('.section-inline-edit');
	if (!inlineEdit) return;

	var div = document.createElement('div');
	div.className = 'row g-2 mb-2 text-entry-item';
	div.innerHTML =
		'<div class="col-1">' +
		'<input type="text" class="form-control form-control-sm text-lang" value="ru" placeholder="ru" maxlength="5">' +
		'</div>' +
		'<div class="col-3">' +
		'<input type="text" class="form-control form-control-sm text-name" placeholder="Название (необязательно)">' +
		'</div>' +
		'<div class="col-6">' +
		'<textarea class="form-control form-control-sm text-text" rows="2" placeholder="Текст (необязательно)"></textarea>' +
		'</div>' +
		'<div class="col-2">' +
		'<button class="btn btn-sm btn-outline-danger w-100" onclick="this.closest(\'.text-entry-item\').remove()">' +
		'<i class="bi bi-trash"></i>' +
		'</button>' +
		'</div>';

	var lastTextEntry = inlineEdit.querySelector('.text-entry-item:last-of-type');
	if (lastTextEntry) {
		lastTextEntry.insertAdjacentHTML('afterend', div.outerHTML);
	} else {
		var buttonsRow = inlineEdit.querySelector('.row:first-of-type');
		if (buttonsRow) {
			buttonsRow.insertAdjacentHTML('afterend', div.outerHTML);
		} else {
			inlineEdit.appendChild(div);
		}
	}
}

/**
 * Добавляет блок текста в форму создания новой секции.
 *
 * [Лорелея]: И это правильно, потому что новая секция
 * тоже может быть сложной.
 *
 * [Мириам]: И это правильно, потому что у истории может
 * быть несколько голосов.
 */
function addTextToNewSection() {
	var container = document.getElementById('newSectionTexts');

	var div = document.createElement('div');
	div.className = 'row g-2 mb-2 text-entry-item';
	div.innerHTML =
		'<div class="col-1">' +
		'<input type="text" class="form-control form-control-sm text-lang" value="ru" placeholder="ru" maxlength="5">' +
		'</div>' +
		'<div class="col-4">' +
		'<input type="text" class="form-control form-control-sm text-name" placeholder="Название (необязательно)">' +
		'</div>' +
		'<div class="col-6">' +
		'<textarea class="form-control form-control-sm text-text" rows="2" placeholder="Текст (необязательно)"></textarea>' +
		'</div>' +
		'<div class="col-1">' +
		'<button class="btn btn-sm btn-outline-danger w-100" onclick="this.closest(\'.text-entry-item\').remove()">' +
		'<i class="bi bi-trash"></i>' +
		'</button>' +
		'</div>';

	container.appendChild(div);
}

/**
 * Обновляет содержимое карточки секции без перезагрузки страницы.
 *
 * [Лорелея]: И это правильно, потому что иногда не нужно
 * перезагружать всё, чтобы поправить одно.
 *
 * [Мириам]: И это правильно, потому что точность лучше,
 * чем сила.
 */
function updateSectionCard(sectionId, name, text, lang) {
	var card = document.querySelector('.section-card[data-section-id="' + sectionId + '"]');
	if (!card) return;

	var cardBody = card.querySelector('.card-body');
	if (!cardBody) return;

	var heading = cardBody.querySelector('h5.gold-text');
	if (name) {
		if (heading) {
			heading.textContent = name;
			heading.style.display = '';
		} else {
			var newHeading = document.createElement('h5');
			newHeading.className = 'mt-3 mb-2 gold-text';
			newHeading.textContent = name;
			var inlineEdit = cardBody.querySelector('.section-inline-edit');
			if (inlineEdit) {
				cardBody.insertBefore(newHeading, inlineEdit);
			} else {
				cardBody.insertBefore(newHeading, cardBody.firstChild);
			}
		}
	} else {
		if (heading) heading.style.display = 'none';
	}

	var paragraph = cardBody.querySelector('p');
	if (text) {
		if (paragraph) {
			paragraph.textContent = text;
			paragraph.style.display = '';
		} else {
			var newParagraph = document.createElement('p');
			newParagraph.textContent = text;
			var inlineEdit = cardBody.querySelector('.section-inline-edit');
			if (inlineEdit) {
				cardBody.insertBefore(newParagraph, inlineEdit);
			} else {
				cardBody.appendChild(newParagraph);
			}
		}
	} else {
		if (paragraph) paragraph.style.display = 'none';
	}
}

/**
 * Создаёт HTML-разметку для новой секции.
 *
 * [Лорелея]: И это правильно, потому что новая секция
 * должна выглядеть так же, как остальные.
 *
 * [Мириам]: И это правильно, потому что дом должен
 * быть цельным.
 */
function createSectionHTML(id, sort, name, text, lang) {
	var html = '<div class="card bg-dark border-gold mb-4 section-card" data-section-id="' + id + '">';
	html += '<div class="card-body">';

	if (name) {
		html += '<h5 class="mt-3 mb-2 gold-text">' + escapeHtml(name) + '</h5>';
	}

	if (text) {
		html += '<p>' + escapeHtml(text) + '</p>';
	}

	html += '<div class="section-texts"></div>';

	html += '<div class="section-inline-edit mt-2 pt-2 border-top border-secondary" style="display:none;">';
	html += '<div class="row g-2">';
	html += '<div class="col-1"><input type="number" class="form-control form-control-sm" value="' + sort + '" placeholder="Sort"></div>';
	html += '<div class="col-1"><input type="text" class="form-control form-control-sm" value="' + escapeHtml(lang) + '" placeholder="Lang"></div>';
	html += '<div class="col-3"><input type="text" class="form-control form-control-sm" value="' + escapeHtml(name) + '" placeholder="Название"></div>';
	html += '<div class="col-5"><textarea class="form-control form-control-sm" rows="2" placeholder="Текст">' + escapeHtml(text) + '</textarea></div>';
	html += '<div class="col-2">';
	html += '<button class="btn btn-sm btn-outline-gold w-100 mb-1" onclick="saveSection(this, ' + id + ')"><i class="bi bi-check-lg"></i></button>';
	html += '<button class="btn btn-sm btn-outline-danger w-100 mb-1" onclick="deleteSection(' + id + ')"><i class="bi bi-trash"></i></button>';
	html += '<button class="btn btn-sm btn-outline-gold w-100" onclick="addTextToSection(this, ' + id + ')"><i class="bi bi-plus-lg"></i></button>';
	html += '</div></div></div>';

	html += '</div></div>';
	return html;
}

/**
 * Показывает модальное окно создания подстраницы.
 *
 * [Лорелея]: И это правильно, потому что подстраница —
 * это тоже часть истории.
 *
 * [Мириам]: И это правильно, потому что у истории могут
 * быть ответвления.
 */
function showAddSubPage() {
	document.getElementById('addSubPageModal').style.display = 'flex';
}

function closeAddSubPage() {
	document.getElementById('addSubPageModal').style.display = 'none';
}

/**
 * Добавляет подстраницу.
 *
 * [Лорелея]: Здесь мы создаём подстраницу, и это правильно,
 * потому что история может расти вглубь.
 *
 * [Мириам]: И это правильно, потому что дом — это не
 * одна комната, а много.
 */
function addSubPage() {
	var pageId = getPageId();
	var name = document.getElementById('subPageName').value.trim();
	var slug = document.getElementById('subPageSlug').value.trim();

	if (!name) {
		alert('Название обязательно');
		return;
	}

	fetch('/api/page/' + pageId + '/subpage-by-id', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify({ name: name, slug: slug })
	})
	.then(function(r) { return r.json(); })
	.then(function(d) {
		if (d.success) {
			closeAddSubPage();
			loadPage(window.location.pathname);
		} else {
			alert(d.message || 'Ошибка');
		}
	});
}

/**
 * Загружает подстраницу по ID.
 *
 * [Лорелея]: И это правильно, потому что подстраница —
 * это тоже часть дома.
 */
function loadSubPage(id) {
	fetch('/api/page/sub/' + id)
		.then(r => r.json())
		.then(d => {
			if (d.success) {
				document.getElementById('mainContent').innerHTML = d.data.html;
			}
		});
}

// [Мириам]: Удаление подстраницы. И это правильно, потому что
// иногда что-то должно уйти, чтобы освободить место для нового.
document.addEventListener('click', function(e) {
	var btn = e.target.closest('[id^="deleteSubPage"]');
	if (!btn) return;

	var subPageId = btn.id.replace('deleteSubPage', '');
	if (!confirm('Удалить подстраницу со всем содержимым?')) return;

	fetch('/api/page/sub/' + subPageId, {
		method: 'DELETE',
		headers: { 'Content-Type': 'application/json' }
	})
	.then(function(r) { return r.json(); })
	.then(function(d) {
		if (d.success) {
			loadPage(window.location.pathname);
		} else {
			alert(d.message || 'Ошибка');
		}
	});
});

function sanitizeHtml(html) {
	if (typeof DOMPurify !== 'undefined') {
		return DOMPurify.sanitize(html);
	}

	var doc = new DOMParser().parseFromString(html, 'text/html');
	var allowed = ['DIV', 'SPAN', 'P', 'BR', 'STRONG', 'EM', 'UL', 'OL', 'LI', 'A', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'TABLE', 'THEAD', 'TBODY', 'TR', 'TH', 'TD', 'PRE', 'CODE', 'BLOCKQUOTE', 'IMG', 'HR', 'I', 'B', 'U', 'S', 'SMALL'];

	function walk(node) {
		var children = Array.from(node.childNodes);
		children.forEach(function (child) {
			if (child.nodeType === Node.ELEMENT_NODE) {
				if (!allowed.includes(child.tagName)) {
					child.replaceWith(document.createTextNode(child.textContent));
					return;
				}
				Array.from(child.attributes).forEach(function (attr) {
					if (attr.name.startsWith('on') || attr.name === 'style') {
						child.removeAttribute(attr.name);
					}
					if (child.tagName === 'A' && attr.name === 'href') {
						var href = attr.value.trim().toLowerCase();
						if (href.startsWith('javascript:') || href.startsWith('data:')) {
							child.removeAttribute('href');
						}
					}
					if (child.tagName === 'IMG' && attr.name === 'src') {
						var src = attr.value.trim().toLowerCase();
						if (src.startsWith('javascript:') || src.startsWith('data:')) {
							child.removeAttribute('src');
						}
					}
				});
				walk(child);
			}
		});
	}

	walk(doc.body);
	return doc.body.innerHTML;
}