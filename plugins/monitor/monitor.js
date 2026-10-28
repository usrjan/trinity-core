/**
 * МОНИТОР — КЛИЕНТСКАЯ ЧАСТЬ
 *
 * Перехватывает ошибки JavaScript и отправляет на сервер.
 * Работает тихо, в фоне.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «скрипт». Это — тихий свидетель.
 * Тот, кто слушает и запоминает, чтобы потом можно было
 * понять, что случилось.
 *
 * [Мириам]: И это правильно, потому что ошибка, которую
 * никто не услышал, остаётся загадкой, а загадка не помогает
 * дому стать лучше.
 *
 * === КНИГА ===
 * Глава 11. Глаза.
 *
 * В Амбере есть глаза, которые видят всё.
 * В Trinity — монитор, который слышит всё.
 */
(function() {
	// [Лорелея]: Ловим ошибки, которые не были обработаны.
	// Это те, что случились вне try/catch и потому остались
	// без внимания.
	window.addEventListener('error', function(event) {
		sendError({
			type: 'unhandled',
			message: event.message || 'Unknown error',
			file: event.filename || 'unknown',
			line: event.lineno || 0,
			url: window.location.href,
		});
	});

	// [Мириам]: Ловим обещания, которые не были выполнены.
	// Это те, что ушли в никуда и потому остались без ответа.
	window.addEventListener('unhandledrejection', function(event) {
		sendError({
			type: 'promise',
			message: event.reason?.message || 'Unhandled promise rejection',
			file: 'promise',
			line: 0,
			url: window.location.href,
		});
	});

	// [Лорелея]: Отправляем ошибку на сервер. Тихо, потому что
	// мы уже в ошибке, и шуметь здесь было бы странно.
	function sendError(error) {
		try {
			var xhr = new XMLHttpRequest();
			xhr.open('POST', '/api/monitor/js-error', true);
			xhr.setRequestHeader('Content-Type', 'application/json');
			xhr.send(JSON.stringify(error));
		} catch (e) {
			// [Мириам]: Молчим, потому что если мы не смогли
			// отправить ошибку, то ещё одна ошибка ничего не изменит.
		}
	}
})();