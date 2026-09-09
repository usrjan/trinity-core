/**
 * TRINITY MAP — полный скрипт карты
 * 
 * Поддерживает:
 * - Полигоны районов (SubAdministrativeArea)
 * - Точки учреждений (фильтруются по mapVars.codes)
 * - Ховер-эффект на полигонах
 * - Левую панель с информацией об объекте и работах
 * - Правую панель с фильтром по районам
 */

var myMap;
var polyColl;
var pointColl;
var districtsCache = null;

ymaps.ready(function () {

	// Скрываем заглушку загрузки
	var loadingEl = document.querySelector('.map-loading');
	if (loadingEl) loadingEl.style.display = 'none';

	// Создание карты
	myMap = new ymaps.Map('map', {
		center: [mapVars.cen_lat, mapVars.cen_lon],
		zoom: mapVars.zoom,
		controls: ['zoomControl']
	});

	// Коллекции
	polyColl = new ymaps.GeoObjectCollection();
	pointColl = new ymaps.GeoObjectCollection({}, { hasBalloon: false });

	//myMap.geoObjects.add(polyColl);
	myMap.geoObjects.add(polyColl).add(pointColl);

	// Загрузка полигонов (районы)
	fetch('/api/map/geojson?codes=SubAdministrativeArea')
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (d.success && d.data && d.data.features) {
				districtsCache = d.data.features;  // кэш
				addFeatures(polyColl, d.data.features, true);
			}
		});

		// Загрузка точек (учреждения) — только если есть codes
		if (mapVars.codes) {
			var pointUrl = '/api/map/geojson?codes=' + mapVars.codes;

			fetch(pointUrl)
				.then(function (r) { return r.json(); })
				.then(function (d) {
					if (d.success && d.data && d.data.features) {
						addFeatures(pointColl, d.data.features, false);

						if (pointColl.getLength() > 0) {
							myMap.setBounds(pointColl.getBounds(), { checkZoomRange: true });
						}
					}
				});

			// Клик по точке — левая панель
			pointColl.events.add('click', function (e) {
				var target = e.get('target');
				var id = target.properties.get('iden');
				if (id) {
					leftPanelLoad(id);
				}
			});
		}

	// Ховер на полигонах
	polyColl.events
		.add('mouseenter', function (e) {
			e.get('target').options.set('fillOpacity', 1);
			e.get('target').options.set('fillColor', '#EEEEEE98');
		})
		.add('mouseleave', function (e) {
			e.get('target').options.set('fillOpacity', 0);
		});


	// Правая панель с фильтрами — только если есть точки
	if (mapVars.codes) {
		rightPanelButton();
		loadRightPanel();
	}

	// Кастомные кнопки слоёв в стиле Trinity
	(function () {
		var layerButtons = [
			{ type: 'yandex#map',       icon: 'bi-map',         label: 'Схема' },
			{ type: 'yandex#satellite',  icon: 'bi-globe2',      label: 'Спутник' },
			{ type: 'yandex#hybrid',     icon: 'bi-layers',      label: 'Гибрид' }
		];

		var html = '<div class="map-layer-buttons">';
		layerButtons.forEach(function (btn) {
			html += '<button class="btn btn-sm trinity-layer-btn" data-type="' + btn.type + '">' +
					'<i class="bi ' + btn.icon + '"></i> ' + btn.label +
					'</button>';
		});
		html += '</div>';

		var LayerButtonLayout = ymaps.templateLayoutFactory.createClass(html);

		var layerControl = new ymaps.control.Button({
			options: {
				layout: LayerButtonLayout,
				float: 'left',
				position: { top: 10, left: 10 }
			}
		});

		myMap.controls.add(layerControl);

		function updateLayerButtons() {
			var activeType = myMap.getType();
			document.querySelectorAll('.trinity-layer-btn').forEach(function (btn) {
				var type = btn.getAttribute('data-type');
				if (type === activeType) {
					btn.classList.add('active');
				} else {
					btn.classList.remove('active');
				}
			});
		}

		myMap.events.add('typechange', updateLayerButtons);

		document.addEventListener('click', function (e) {
			var btn = e.target.closest('.trinity-layer-btn');
			if (btn) {
				var type = btn.getAttribute('data-type');
				myMap.setType(type);
			}
		});

		// Начальная подсветка после отрисовки кнопок
		setTimeout(updateLayerButtons, 300);
	})();
});

// Добавление объектов в коллекцию
function addFeatures(collection, features, isPolygon) {
	//console.log('addFeatures isPolygon:', isPolygon, 'features count:', features.length);
	features.forEach(function (feature) {
		var geom = feature.geometry;
		var props = feature.properties || {};
		var opts = feature.options || {};

		if (geom.type === 'Polygon') {
			var polygon = new ymaps.Polygon(geom.coordinates, {
				hintContent: props.hintContent,
				balloonContent: props.balloonContent
			}, {
				strokeWidth: opts.strokeWidth || 1,
				strokeColor: opts.strokeColor || '#ffaa00',
				fillColor: opts.fillColor || '#ffaa00',
				fillOpacity: isPolygon ? 0 : (opts.fillOpacity || 0.2),
			});
			collection.add(polygon);
		}

		if (geom.type === 'Point') {
			var point = new ymaps.Placemark(geom.coordinates, {
				iden: feature.id,
				hintContent: props.hintContent,
				balloonContent: props.balloonContent
			}, {
				preset: opts.preset || 'islands#blueCircleIcon',
				iconColor: opts.iconColor || '#ffaa00',
			});
			collection.add(point);
		}

		if (geom.type === 'LineString') {
			var line = new ymaps.Polyline(geom.coordinates, {}, {
				strokeWidth: opts.strokeWidth || 2,
				strokeColor: opts.strokeColor || '#ffaa00',
				strokeOpacity: opts.strokeOpacity || 1
			});
			collection.add(line);
		}
	});
}

// Левая панель — информация об объекте
function leftPanelLoad(id) {
	fetch('/api/map/info/' + id)
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (d.success && d.data && d.data.html) {
				document.getElementById('leftPanel').innerHTML = d.data.html;

				var offcanvasEl = document.getElementById('loffcanvas');
				if (offcanvasEl) {
					var bsOffcanvas = bootstrap.Offcanvas.getInstance(offcanvasEl) || new bootstrap.Offcanvas(offcanvasEl);
					bsOffcanvas.show();
				}
			}
		})
		.catch(function (e) {
			console.error('leftPanelLoad error:', e);
		});
}

// Кнопка правой панели
function rightPanelButton() {
	var ButtonLayout = ymaps.templateLayoutFactory.createClass(
		'<div class="map-filter-button-wrapper">' +
		'<button class="btn btn-sm trinity-filter-btn" ' +
		'data-bs-toggle="offcanvas" data-bs-target="#offcanvas">' +
		'<i class="bi bi-funnel"></i> Фильтры' +
		'</button>' +
		'</div>'
	);

	var button = new ymaps.control.Button({
		options: {
			layout: ButtonLayout,
			float: 'right',
			position: { top: 10, right: 10 }
		}
	});

	myMap.controls.add(button);
}

// Правая панель — фильтры
function loadRightPanel() {
	if (!districtsCache) {
		// Если кэша ещё нет — загружаем
		fetch('/api/map/geojson?codes=SubAdministrativeArea')
			.then(function (r) { return r.json(); })
			.then(function (d) {
				if (d.success && d.data && d.data.features) {
					districtsCache = d.data.features;
					renderRightPanel(d.data.features);
				}
			});
		return;
	}
	renderRightPanel(districtsCache);
}

function renderRightPanel(features) {
	var html = '<div class="p-3"><h6 class="gold-text mb-2">Районы</h6>';
	html += '<select class="form-select form-select-sm" id="fsel" onchange="filterByDistrict()">';
	html += '<option value="">Все районы</option>';

	features.forEach(function (f) {
		var name = f.properties.hintContent || f.properties.balloonContent || 'Без названия';
		html += '<option value="' + f.id + '">' + escapeHtml(name) + '</option>';
	});

	html += '</select></div>';

	var rightPanel = document.getElementById('rightPanel');
	if (rightPanel) rightPanel.innerHTML = html;
}

// Фильтрация точек по району
function filterByDistrict() {
	var selectedId = document.getElementById('fsel').value;
	var url = '/api/map/geojson';
	if (mapVars.codes) {
		url += '?codes=' + mapVars.codes;
	}
	if (selectedId) {
		url += (url.includes('?') ? '&' : '?') + 'pid=' + selectedId;
	}

	fetch(url)
		.then(function (r) { return r.json(); })
		.then(function (d) {
			if (d.success && d.data && d.data.features) {
				pointColl.removeAll();
				addFeatures(pointColl, d.data.features, false);
			}
		});
}

// Экранирование HTML
function escapeHtml(text) {
	if (!text) return '';
	var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
	return String(text).replace(/[&<>"']/g, function (m) { return map[m]; });
}