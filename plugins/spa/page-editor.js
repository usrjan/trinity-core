/**
 * INLINE-РЕДАКТОР СТРАНИЦ
 * Доступен только админам.
 */

var editMode = false;
var currentSlug = window.location.pathname.replace(/^\//, '');

function toggleEditMode() {
	editMode = !editMode;
	document.querySelectorAll('.section-inline-edit').forEach(function(el) {
		el.style.display = editMode ? 'block' : 'none';
	});
	var form = document.getElementById('addSectionForm');
	if (form) form.style.display = editMode ? 'block' : 'none';
}

function saveSection(btn, sectionId) {
    var card = btn.closest('.section-card');
    var sortInput = card.querySelector('.section-inline-edit input[type="number"]');
    var sort = parseInt(sortInput.value);

    // Собираем все тексты
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

function deleteSection(sectionId) {
	if (!confirm('Удалить секцию?')) return;
	
	var card = document.querySelector('.section-card[data-section-id="' + sectionId + '"]');
	
	fetch('/api/page/section/' + sectionId + '/delete', { method: 'POST' })
		.then(function(r) { return r.json(); })
		.then(function(d) {
			if (d.success) {
				// Удаляем карточку из DOM с анимацией
				if (card) {
					card.style.opacity = '0';
					card.style.transition = 'opacity 0.3s';
					setTimeout(function() {
						card.remove();
						
						// Если больше нет секций — показать заглушку
						var remainingCards = document.querySelectorAll('.section-card');
						if (remainingCards.length === 0) {
							var container = document.querySelector('.p-4');
							var alertHTML = '<div class="alert alert-info"><i class="bi bi-info-circle"></i> На этой странице пока нет контента.</div>';
							container.insertAdjacentHTML('beforeend', alertHTML);
						}
					}, 300);
				}
			} else {
				alert(d.message || 'Ошибка');
			}
		});
}

function getPageId() {
	var meta = document.querySelector('meta[name="page-id"]');
	return meta ? meta.getAttribute('content') : null;
}

/**
 * Добавляет новую секцию на страницу.
 * Собирает все тексты: основной + дополнительные.
 * Каждый текст может иметь только название, только текст, или и то и другое.
 * Язык указывается вручную (новые языки добавляются в ENUM автоматически).
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

	// Собираем все тексты
	var texts = [];

	// Основной текст (название + текст + язык)
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

	// Дополнительные тексты
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

	// Валидация: хотя бы один текст
	if (texts.length === 0) {
		alert('Добавьте хотя бы один текст');
		addBtn.innerHTML = originalHTML;
		addBtn.disabled = false;
		return;
	}

	var sort = document.getElementById('newSort').value;

	// Отправляем запрос
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
				// Очищаем форму
				document.getElementById('newName').value = '';
				document.getElementById('newText').value = '';
				document.getElementById('newSort').value = '100';
				document.getElementById('newSectionTexts').innerHTML = '';

				// Обновляем страницу
				loadPage(window.location.pathname);

				// Индикатор успеха
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
 * Новый блок добавляется в .section-inline-edit, перед кнопками сохранения.
 * 
 * @param {HTMLElement} btn — кнопка, которая вызвала функцию
 * @param {number} sectionId — ID нейрона секции
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

    // Вставляем после последнего .text-entry-item
    var lastTextEntry = inlineEdit.querySelector('.text-entry-item:last-of-type');
    if (lastTextEntry) {
        lastTextEntry.insertAdjacentHTML('afterend', div.outerHTML);
    } else {
        // Если нет ни одного текста — вставляем после ряда с кнопками
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
 */
function updateSectionCard(sectionId, name, text, lang) {
	var card = document.querySelector('.section-card[data-section-id="' + sectionId + '"]');
	if (!card) return;

	var cardBody = card.querySelector('.card-body');
	if (!cardBody) return;

	// Обновляем заголовок секции
	var heading = cardBody.querySelector('h5.gold-text');
	if (name) {
		if (heading) {
			heading.textContent = name;
			heading.style.display = '';
		} else {
			// Создаём заголовок если его не было
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

	// Обновляем текст секции
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
	
	// Контейнер для дополнительных текстов
	html += '<div class="section-texts"></div>';
	
	// Inline-редактор (скрыт по умолчанию)
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

function showAddSubPage() {
	document.getElementById('addSubPageModal').style.display = 'flex';
}

function closeAddSubPage() {
	document.getElementById('addSubPageModal').style.display = 'none';
}

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
			// Обновляем текущую страницу (на ней появится новая подстраница в списке)
			loadPage(window.location.pathname);
		} else {
			alert(d.message || 'Ошибка');
		}
	});
}

function loadSubPage(id) {
	fetch('/api/page/sub/' + id)
		.then(r => r.json())
		.then(d => {
			if (d.success) {
				document.getElementById('mainContent').innerHTML = d.data.html;
			}
		});
}

// Удаление подстраницы
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
			// Обновляем текущую страницу
			loadPage(window.location.pathname);
		} else {
			alert(d.message || 'Ошибка');
		}
	});
});