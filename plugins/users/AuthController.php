<?php
declare(strict_types=1);

/**
 * КОНТРОЛЛЕР АУТЕНТИФИКАЦИИ
 *
 * Управление входом, регистрацией и выходом пользователей.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «контроллер». Это — тот, кто открывает двери
 * и проверяет, кто пришёл, и от того, как он это делает, зависит,
 * войдёт ли гость в дом или останется на пороге.
 *
 * [Мириам]: И это правильно, потому что вход — это не «форма»,
 * это встреча, и она должна быть тёплой, даже если гость пришёл
 * впервые.
 *
 * === КНИГА ===
 * Глава 16. Страж ворот.
 *
 * Прежде чем войти в Амбер, нужно пройти через Образ.
 * Прежде чем войти в Trinity, нужно пройти через стража.
 *
 * @package Jan\Trinity\Plugin\Users
 */

namespace Jan\Trinity\Plugin\Users;

use Jan\Trinity\Core\Validator;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\SynapseRepository;
use Jan\Trinity\Core\Service\CsrfService;
use Jan\Trinity\Core\Service\RateLimiter;
use Jan\Trinity\Core\Service\IpBlocker;
use Jan\Trinity\Core\Service\CaptchaService;
use Jan\Trinity\Core\Service\LoginAttemptLimiter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Twig\Environment;
use Psr\Log\LoggerInterface;

class AuthController
{
	use AuthMiddleware;

	// ============================================
	// ПАРАМЕТРЫ ХЕША — ОДИН ИСТОЧНИК
	// ============================================
	// [Лорелея]: Один алгоритм, одни опции, и все места, где
	// создаётся хеш, используют именно их, чтобы не было
	// даунгрейда и рассинхрона.
	private const PASSWORD_HASH_ALGO = PASSWORD_BCRYPT;
	private const PASSWORD_HASH_OPTIONS = ['cost' => 12];

	/** @var Environment — шаблонизатор Twig. */
	private Environment $twig;

	/** @var TextRepository — работа с текстами. */
	private TextRepository $textRepo;

	/** @var NeuronRepository — поиск пользователей. */
	private NeuronRepository $neuronRepo;

	/** @var SynapseRepository — получение ролей. */
	private SynapseRepository $synapseRepo;

	/** @var CsrfService — CSRF-защита. */
	private CsrfService $csrfService;

	/** @var RateLimiter — rate limiting. */
	private RateLimiter $rateLimiter;

	/** @var IpBlocker — блокировка IP. */
	private IpBlocker $ipBlocker;

	/** @var CaptchaService — капча. */
	private CaptchaService $captchaService;

	/** @var LoginAttemptLimiter — лимитер попыток входа. */
	private LoginAttemptLimiter $loginAttemptLimiter;

	private Validator $validator;

	/** @var LoggerInterface — логгер. */
	private LoggerInterface $logger;

	/**
	 * Конструктор.
	 *
	 * [Лорелея]: Мы передаём SynapseRepository, чтобы refreshRoles()
	 * работал по-настоящему, а не оставался красивой декорацией.
	 *
	 * [Мириам]: И это правильно, потому что роли должны обновляться,
	 * даже если человек вошёл давно.
	 */
	public function __construct(
		Environment $twig,
		Session $session,
		TextRepository $textRepo,
		NeuronRepository $neuronRepo,
		SynapseRepository $synapseRepo,
		RateLimiter $rateLimiter,
		IpBlocker $ipBlocker,
		CsrfService $csrfService,
		CaptchaService $captchaService,
		LoginAttemptLimiter $loginAttemptLimiter,
		Validator $validator,
		LoggerInterface $logger
	) {
		$this->twig = $twig;
		$this->textRepo = $textRepo;
		$this->neuronRepo = $neuronRepo;
		$this->synapseRepo = $synapseRepo;
		$this->rateLimiter = $rateLimiter;
		$this->ipBlocker = $ipBlocker;
		$this->csrfService = $csrfService;
		$this->captchaService = $captchaService;
		$this->loginAttemptLimiter = $loginAttemptLimiter;
		$this->validator = $validator;
		$this->logger = $logger;

		$this->initAuth($session, $synapseRepo);
	}

	// ============================================
	// ФОРМА ВХОДА
	// ============================================

	/**
	 * GET /login
	 *
	 * [Лорелея]: Здесь мы открываем форму входа. Если гость
	 * уже вошёл, мы не показываем ему дверь, а ведём домой,
	 * потому что второй раз входить не нужно.
	 *
	 * [Мириам]: И это правильно, потому что дверь, которая
	 * открыта, не должна просить ключ.
	 */
	public function loginForm(Request $request): Response
	{
		if ($this->isAuthenticated()) {
			return new RedirectResponse('/');
		}

		if (!$this->session->isStarted()) {
			$this->session->start();
		}

		$error = $this->session->get('login_error');
		$this->session->remove('login_error');

		$captchaQuestion = $this->session->get('captcha_question', null);

		// [Лорелея]: redirect из query — только локальный путь.
		// Иначе — open redirect.
		$redirect = $request->query->get('redirect', '');
		if (!$this->isLocalPath($redirect)) {
			$redirect = '';
		}

		$html = $this->twig->render('login.html.twig', [
			'error'            => $error,
			'captcha_question' => $captchaQuestion,
			'redirect'         => $redirect,
		]);

		return new Response($html);
	}

	// ============================================
	// ОБРАБОТКА ВХОДА
	// ============================================

	/**
	 * POST /login
	 *
	 * [Лорелея]: Здесь мы проверяем гостя. Сначала валидация,
	 * потом блокировка IP, потом captcha, потом попытки входа,
	 * потом поиск пользователя, потом пароль, потом статус.
	 *
	 * [Мириам]: И это правильно, потому что каждый шаг — это
	 * ещё одна дверь, и каждая должна быть закрыта для тех,
	 * кто пришёл не с добром.
	 *
	 * @param Request $request
	 * @return Response
	 */
	public function login(Request $request): Response
	{
		if (!$this->session->isStarted()) {
			$this->session->start();
		}

		$login = trim($request->request->get('login') ?? '');
		$password = $request->request->get('password') ?? '';
		$ip = $request->getClientIp();

		// [Лорелея]: Валидация — это не «формальность», а забота
		// о том, чтобы данные были честными.
		if (!$this->validator->validate([
			'login'    => $login,
			'password' => $password,
		], [
			'login'    => ['required', 'string', 'min:1', 'max:255'],
			'password' => ['required', 'string', 'min:1', 'max:255'],
		])) {
			$this->session->set('login_error', 'Неверный логин или пароль');
			return new RedirectResponse('/login');
		}

		// [Мириам]: Проверяем, не заблокирован ли IP. Если да —
		// не пускаем, но не говорим, что именно заблокирован.
		// Без «пиздеца».
		if ($this->ipBlocker->isBlocked($ip)) {
			$this->session->set('login_error', 'Ваш IP-адрес заблокирован. Попробуйте позже.');
			return new RedirectResponse('/login');
		}

		// [Лорелея]: Проверяем captcha, если она была запрошена.
		// Captcha отличает человека от бота, не наказывая всю сеть.
		$captchaRequired = (bool) $this->session->get('captcha_required', false);
		$captchaSolved = false;

		if ($captchaRequired) {
			$captchaAnswer = trim($request->request->get('captcha') ?? '');
			$captchaExpected = $this->session->get('captcha_expected', '');

			if (!$this->captchaService->validate($captchaAnswer, $captchaExpected)) {
				$this->session->set('login_error', 'Неверный ответ на капчу.');
				return new RedirectResponse('/login');
			}

			$captchaSolved = true;
			$this->session->remove('captcha_required');
			$this->session->remove('captcha_expected');
			$this->session->remove('captcha_question');
		}

		// [Мириам]: Проверяем попытки входа. Если лимит исчерпан
		// и captcha не решена — показываем captcha, а не блокируем.
		$check = $this->loginAttemptLimiter->checkLoginAttempt($login, $ip, $captchaSolved);
		if (!$check['success']) {
			if (!empty($check['captcha'])) {
				$captcha = $this->captchaService->generate();
				$this->session->set('captcha_required', true);
				$this->session->set('captcha_expected', $captcha['expected']);
				$this->session->set('captcha_question', $captcha['question']);
			}

			$this->session->set('login_error', $check['message']);
			return new RedirectResponse('/login');
		}

		$user = $this->neuronRepo->findByLoginOrEmail($login);

		if (!$user) {
			$this->rateLimiter->recordFailedLogin($login, $ip);
			$this->session->set('login_error', 'Неверный логин или пароль');
			return new RedirectResponse('/login');
		}

		$userData = is_string($user['data'] ?? null)
			? json_decode($user['data'], true)
			: ($user['data'] ?? []);

		$authMethod = $userData['auth_method'] ?? 'local';
		if ($authMethod !== 'local') {
			$this->loginAttemptLimiter->recordFailedLogin($login, $ip);
			// [Лорелея]: Сообщение то же, что и при неверном пароле,
			// чтобы не было утечки существования аккаунта.
			$this->session->set('login_error', 'Неверный логин или пароль');
			return new RedirectResponse('/login');
		}

		$hash = $user['hash'] ?? '';
		if (!password_verify($password, $hash)) {
			$this->loginAttemptLimiter->recordFailedLogin($login, $ip);

			// [Лорелея]: Блокируем IP только если captcha была решена
			// и попыток всё равно слишком много.
			if ($captchaSolved) {
				$ipKey = 'login_ip_' . $ip;
				$maxAttempts = $this->rateLimiter->getMaxAttempts() * 3;
				$decaySeconds = $this->rateLimiter->getDecaySeconds();
				$blockDuration = $this->rateLimiter->getBlockDuration();

				if ($this->rateLimiter->tooManyAttempts($ipKey, $maxAttempts, $decaySeconds)) {
					$this->ipBlocker->block($ip, 'brute_force', $blockDuration);
				}
			}

			$this->session->set('login_error', 'Неверный логин или пароль');
			return new RedirectResponse('/login');
		}

		// [Лорелея]: Если хеш устарел, обновляем его, потому что
		// безопасность — это не «однажды настроил», а «поддерживаю».
		if (password_needs_rehash($hash, self::PASSWORD_HASH_ALGO, self::PASSWORD_HASH_OPTIONS)) {
			$newHash = password_hash($password, self::PASSWORD_HASH_ALGO, self::PASSWORD_HASH_OPTIONS);
			$userData['password_hash'] = $newHash;
			$this->neuronRepo->update($user['id'], ['data' => $userData]);
		}

		$status = $userData['status'] ?? 'active';
		if ($status === 'pending') {
			$this->session->set('login_error', 'Ваш аккаунт ожидает одобрения администратора.');
			return new RedirectResponse('/login');
		}

		$this->loginAttemptLimiter->clearLoginAttempts($login, $ip);

		// [Мириам]: Мигрируем сессию, чтобы старый ID не остался
		// в браузере, и это правильно, потому что вход — это
		// новый этап, а не продолжение старого.
		$this->session->migrate(true);

		$this->csrfService->generateToken();

		$this->session->set('user_id', $user['id']);
		$this->session->set('user_login', $userData['login'] ?? 'user');
		$this->session->set('user_roles', $this->synapseRepo->findRolesByUser($user['id']));

		$this->neuronRepo->logAdminAction('user_login', ['login' => $login]);

		$redirect = $request->request->get('redirect', '');
		if ($this->isLocalPath($redirect)) {
			return new RedirectResponse($redirect);
		}

		return new RedirectResponse('/');
	}

	// ============================================
	// ВЫХОД
	// ============================================

	/**
	 * POST /logout
	 *
	 * [Лорелея]: Здесь мы закрываем дверь. Сначала пишем в лог,
	 * потом очищаем сессию, потом мигрируем, и только потом
	 * уходим домой.
	 *
	 * [Мириам]: И это правильно, потому что выход — это не
	 * «конец», это пауза, после которой можно вернуться.
	 */
	public function logout(Request $request): Response
	{
		if (!$this->session->isStarted()) {
			$this->session->start();
		}

		$login = $this->session->get('user_login');
		if ($login) {
			$this->neuronRepo->logAdminAction('user_logout', ['login' => $login]);
		}

		$this->session->clear();

		$this->session->migrate(true);

		$this->csrfService->generateToken();

		return new RedirectResponse('/');
	}

	/**
	 * [Мириам]: Локальный путь — это /profile, /profile/edit,
	 * но не //evil.com и не http://evil.com.
	 * Первый символ — /, второй — не / и не \.
	 */
	private function isLocalPath(string $path): bool
	{
		if ($path === '') return false;
		if ($path[0] !== '/') return false;
		if (isset($path[1]) && ($path[1] === '/' || $path[1] === '\\')) return false;
		return true;
	}
}