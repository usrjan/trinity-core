/**
 * МОНИТОР — КЛИЕНТСКАЯ ЧАСТЬ
 * 
 * Перехватывает ошибки JavaScript и отправляет на сервер.
 * Работает тихо, в фоне.
 */
(function() {
	window.addEventListener('error', function(event) {
		sendError({
			type: 'unhandled',
			message: event.message || 'Unknown error',
			file: event.filename || 'unknown',
			line: event.lineno || 0,
			url: window.location.href,
		});
	});

	window.addEventListener('unhandledrejection', function(event) {
		sendError({
			type: 'promise',
			message: event.reason?.message || 'Unhandled promise rejection',
			file: 'promise',
			line: 0,
			url: window.location.href,
		});
	});

	function sendError(error) {
		try {
			var xhr = new XMLHttpRequest();
			xhr.open('POST', '/api/monitor/js-error', true);
			xhr.setRequestHeader('Content-Type', 'application/json');
			xhr.send(JSON.stringify(error));
		} catch (e) {
			// Тихо — мы уже в ошибке
		}
	}
})();