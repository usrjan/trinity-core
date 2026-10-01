/**
 * TRINITY AUTH — скрипты для страниц входа и регистрации
 */

// Показать/скрыть пароль
document.addEventListener('DOMContentLoaded', function() {
    var toggleBtn = document.getElementById('togglePassword');
    var passwordInput = document.getElementById('password');

    if (toggleBtn && passwordInput) {
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