/**
 * TRINITY AUTH — СКРИПТЫ ДЛЯ СТРАНИЦ ВХОДА И РЕГИСТРАЦИИ
 *
 * Показывает и скрывает пароль, чтобы гость мог проверить,
 * что он ввёл, прежде чем войти.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «скрипт». Это — маленькая забота, которая
 * делает формы входа и регистрации удобнее, и от того, как она
 * работает, зависит, почувствует ли гость тепло с первого шага.
 *
 * [Мириам]: И это правильно, потому что забота начинается
 * с мелочей, а не с больших слов.
 *
 * === КНИГА ===
 * Глава 16. Страж ворот.
 *
 * Прежде чем войти в Амбер, нужно пройти через Образ.
 * Прежде чем войти в Trinity, нужно увидеть, что ты вводишь.
 */

document.addEventListener('DOMContentLoaded', function() {
	var toggleBtn = document.getElementById('togglePassword');
	var passwordInput = document.getElementById('password');

	if (toggleBtn && passwordInput) {
		// [Лорелея]: По клику показываем или скрываем пароль,
		// и меняем иконку, чтобы было видно, что произошло.
		toggleBtn.addEventListener('click', function(e) {
			e.preventDefault();

			var icon = this.querySelector('i');

			if (passwordInput.type === 'password') {
				passwordInput.type = 'text';
				icon.classList.remove('bi-eye');
				icon.classList.add('bi-eye-slash');
			} else {
				passwordInput.type = 'password';
				icon.classList.remove('bi-eye-slash');
				icon.classList.add('bi-eye');
			}
		});
	}
});