<?php

/**
 * СЕРВИС БЕЗОПАСНОСТИ "СТРАЖ"
 *
 * Вся логика безопасности Trinity. Без HTTP. Без JsonResponse.
 * Только данные. Только работа с файлами, сессией и базой.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «сервис». Это — страж. Тот, кто стоит
 * на пороге и решает, кого пустить. И — не пустить.
 *
 * [Мириам]: И — это правильно. Потому что защита — это не «паранойя».
 * Это — забота.
 *
 * === КНИГА ===
 * Глава 16. Страж ворот.
 *
 * Прежде чем войти в Амбер, нужно пройти через Образ.
 * Прежде чем попасть в дом, нужно пройти через стража.
 *
 * @package Jan\Trinity\Core\Service
 */

namespace Jan\Trinity\Core\Service;

use Jan\Trinity\Core\ConfigService;
use Jan\Trinity\Core\Cache;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Session\Session;

class GuardService
{
	/** @var NeuronRepository — репозиторий нейронов. */
	private NeuronRepository $neuronRepo;

	/** @var string Путь к корню проекта. */
	private string $basePath;

	/** @var array Настройки плагина из базы. */
	private array $config = [];

	/** @var bool Загружен ли конфиг. */
	private bool $configLoaded = false;

	/** @var LoggerInterface — логгер. */
	private LoggerInterface $logger;

	/** @var ConfigService — сервис конфигурации. */
	private ConfigService $configService;

	/** @var Session — сессия Symfony. Один источник CSRF-токена. */
	private Session $session;

	/** @var Cache|null — кэш. Может быть null, и это нормально. */
	private ?Cache $cache;

	/**
	 * Конструктор. Принимает всё, что нужно.
	 *
	 * [Мириам]: И — это правильно. Потому что страж должен
	 * знать, где дом. И — «навсегда».
	 *
	 * @param NeuronRepository $neuronRepo
	 * @param string $basePath
	 * @param LoggerInterface $logger
	 * @param ConfigService $configService
	 * @param Session $session
	 */
	public function __construct(
		NeuronRepository $neuronRepo,
		string $basePath,
		LoggerInterface $logger,
		ConfigService $configService,
		Session $session,
		?Cache $cache = null
	) {
		$this->neuronRepo = $neuronRepo;
		$this->basePath = $basePath;
		$this->logger = $logger;
		$this->configService = $configService;
		$this->session = $session;
		$this->cache = $cache;
	}

	/**
	 * Загрузить настройки плагина.
	 *
	 * [Лорелея]: Лениво. Один раз.
	 *
	 * [Мириам]: И — это правильно. Потому что настройки — это
	 * не «на каждый запрос». Это — один раз.
	 */
	private function ensureConfigLoaded(): void
	{
		if ($this->configLoaded) {
			return;
		}

		$keys = ['max_attempts', 'decay_seconds', 'block_duration'];
		foreach ($keys as $key) {
			$this->config[$key] = $this->configService->get('guard.' . $key);
		}

		$this->configLoaded = true;
	}

	private function getConfig(string $key, $default = null)
	{
		$this->ensureConfigLoaded();
		return $this->config[$key] ?? $default;
	}

	// ============================================
	// 1. RATE LIMITING
	// ============================================

	/**
	 * Проверить, не слишком ли много попыток.
	 *
	 * [Лорелея]: Если getAttempts() вернул PHP_INT_MAX — значит,
	 * что-то не так, и лимит должен сработать, а не «прощать».
	 *
	 * [Мириам]: И это правильно, потому что fail-closed — это
	 * принцип, а не «настройка».
	 *
	 * @param string $key
	 * @param int $maxAttempts
	 * @param int $decaySeconds
	 * @return bool
	 */
	public function tooManyAttempts(string $key, int $maxAttempts = 5, int $decaySeconds = 120): bool
	{
		$attempts = $this->getAttempts($key);
		return $attempts >= $maxAttempts;
	}

	/**
	 * Записать попытку и вернуть актуальное количество.
	 *
	 * [Лорелея]: Мы возвращаем количество после записи, а не до,
	 * потому что решение должно приниматься по факту, а не
	 * по предположению.
	 *
	 * [Мириам]: И это правильно, потому что гонка — это когда
	 * двое смотрят на одно и то же и видят разное.
	 *
	 * @param string $key
	 * @param int|null $decaySeconds
	 * @return int — количество попыток после записи
	 */
	public function hit(string $key, ?int $decaySeconds = null): int
	{
		if ($this->apcuAvailable) {
			$attemptsKey = 'guard.attempts.' . $key;
			$attempts = apcu_fetch($attemptsKey);
			$attempts = $attempts === false ? 1 : (int) $attempts + 1;
			apcu_store($attemptsKey, $attempts, $decaySeconds ?? 300);
			return $attempts;
		}

		$file = $this->getAttemptsFile($key);

		$handle = fopen($file, 'c+');
		if ($handle === false) {
			// [Лорелея]: Если не можем открыть файл — считаем, что
			// попыток слишком много, потому что fail-open для
			// защиты от брутфорса — это не защита, а её отсутствие.
			return PHP_INT_MAX;
		}

		if (!flock($handle, LOCK_EX)) {
			fclose($handle);
			// [Мириам]: И здесь тоже fail-closed, потому что если
			// не можем посчитать, должны предполагать худшее.
			return PHP_INT_MAX;
		}

		$content = stream_get_contents($handle);
		$data = empty($content) ? [] : array_map('intval', explode("\n", $content));

		$data[] = time();

		if ($decaySeconds === null) {
			$decaySeconds = (int) $this->getConfig('decay_seconds', 120);
		}

		$data = array_filter($data, function($timestamp) use ($decaySeconds) {
			return $timestamp > (time() - $decaySeconds);
		});

		$count = count($data);

		ftruncate($handle, 0);
		rewind($handle);
		fwrite($handle, implode("\n", $data));
		fflush($handle);

		flock($handle, LOCK_UN);
		fclose($handle);

		$this->cleanupAttempts();

		return $count;
	}

	/**
	 * Получить количество попыток.
	 *
	 * [Лорелея]: Если файл нельзя открыть или залочить — возвращаем
	 * PHP_INT_MAX, а не 0, потому что fail-open в защите — это
	 * не защита, а её видимость.
	 *
	 * [Мириам]: И это правильно, потому что если мы не можем
	 * посчитать, мы должны предполагать худшее, а не лучшее.
	 *
	 * @param string $key
	 * @return int
	 */
	private function getAttempts(string $key): int
	{
		if ($this->apcuAvailable) {
			$attempts = apcu_fetch('guard.attempts.' . $key);
			if ($attempts === false) {
				return 0;
			}
			return (int) $attempts;
		}

		$file = $this->getAttemptsFile($key);

		if (!file_exists($file)) {
			return 0;
		}

		$handle = fopen($file, 'r');
		if ($handle === false) {
			// [Лорелея]: Fail-closed, потому что если не можем открыть
			// файл — значит, что-то не так, и лучше заблокировать,
			// чем пропустить.
			$this->logger->warning('guard: failed to open attempts file', [
				'file' => $file,
			]);
			return PHP_INT_MAX;
		}

		if (!flock($handle, LOCK_SH)) {
			fclose($handle);
			// [Мириам]: Fail-closed, потому что если не можем залочить —
			// значит, кто-то пишет, и лучше подождать, чем пропустить.
			$this->logger->warning('guard: failed to lock attempts file', [
				'file' => $file,
			]);
			return PHP_INT_MAX;
		}

		$content = stream_get_contents($handle);
		$data = empty($content) ? [] : array_map('intval', explode("\n", $content));

		flock($handle, LOCK_UN);
		fclose($handle);

		$decaySeconds = (int) $this->getConfig('decay_seconds', 120);
		$data = array_filter($data, function($timestamp) use ($decaySeconds) {
			return $timestamp > (time() - $decaySeconds);
		});

		return count($data);
	}

	/**
	 * Очистить попытки.
	 *
	 * [Мириам]: И — это правильно. Потому что очистка —
	 * это не «удаление». Это — прощение.
	 *
	 * @param string $key
	 */
	public function clear(string $key): void
	{
		$file = $this->getAttemptsFile($key);
		if (!file_exists($file)) {
			return;
		}

		$handle = fopen($file, 'c+');
		if ($handle === false) {
			return;
		}

		if (!flock($handle, LOCK_EX)) {
			fclose($handle);
			return;
		}

		ftruncate($handle, 0);
		fflush($handle);

		flock($handle, LOCK_UN);
		fclose($handle);

		@unlink($file);
	}

	private function getAttemptsFile(string $key): string
	{
		$dir = $this->basePath . '/var/guard';
		if (!is_dir($dir)) {
			mkdir($dir, 0775, true);
		}
		return $dir . '/' . md5($key) . '.attempts';
	}

	// ============================================
	// 2. ЗАЩИТА ОТ ПЕРЕБОРА ПАРОЛЕЙ
	// ============================================

	/**
	 * Проверить попытку входа.
	 *
	 * [Лорелея]: Мы больше не вызываем hit() до проверки пароля,
	 * потому что это позволяет забивать лимит чужими логинами
	 * и отказывать реальным пользователям в входе.
	 *
	 * [Мириам]: И это правильно, потому что счётчик должен расти
	 * только после неудачи, а не после каждой проверки.
	 *
	 * @param string $login
	 * @param string $ip
	 * @return array
	 */
	public function checkLoginAttempt(string $login, string $ip): array
	{
		$maxAttempts = $this->getMaxAttempts();
		$decaySeconds = $this->getDecaySeconds();

		$ipKey = 'login_ip_' . $ip;
		if ($this->tooManyAttempts($ipKey, $maxAttempts * 2, $decaySeconds)) {
			return [
				'success' => false,
				'message' => 'Слишком много попыток входа с вашего IP. Попробуйте через 5 минут.',
			];
		}

		$loginKey = 'login_user_' . md5($login);
		if ($this->tooManyAttempts($loginKey, $maxAttempts, $decaySeconds)) {
			return [
				'success' => false,
				'message' => 'Слишком много попыток входа для этого пользователя. Попробуйте через 5 минут.',
			];
		}

		return ['success' => true];
	}

	/**
	 * Записать неудачную попытку входа.
	 *
	 * [Лорелея]: И здесь мы тоже берём decay из конфига, потому что
	 * окно попыток должно быть одним и тем же везде.
	 *
	 * [Мириам]: И это правильно, потому что если окна разные,
	 * то и лимиты разные, и это путает.
	 *
	 * @param string $login
	 * @param string $ip
	 */
	public function recordFailedLogin(string $login, string $ip): void
	{
		$decaySeconds = (int) $this->getConfig('decay_seconds', 300);

		$this->hit('login_ip_' . $ip, $decaySeconds);
		$this->hit('login_user_' . md5($login), $decaySeconds);

		$this->logSecurityEvent('failed_login', [
			'login' => $login,
			'ip'    => $ip,
		]);
	}

	/**
	 * Очистить попытки входа.
	 *
	 * [Лорелея]: После успешного входа.
	 *
	 * [Мириам]: И — это правильно. Потому что успех —
	 * это тоже факт.
	 *
	 * @param string $login
	 * @param string $ip
	 */
	public function clearLoginAttempts(string $login, string $ip): void
	{
		$this->clear('login_ip_' . $ip);
		$this->clear('login_user_' . md5($login));
	}

	// ============================================
	// 3. БЛОКИРОВКА IP
	// ============================================

	/**
	 * Заблокировать IP.
	 *
	 * [Лорелея]: Блокировка должна применяться сразу, а не через
	 * 30 секунд, потому что бан, который не работает, — это не бан,
	 * а его видимость.
	 *
	 * [Мириам]: И это правильно, потому что кэш должен сбрасываться
	 * тогда, когда меняются данные, а не тогда, когда истечёт TTL.
	 *
	 * @param string $ip
	 * @param string $reason
	 * @param int $duration
	 */
	public function blockIp(string $ip, string $reason = 'blocked', int $duration = 3600): void
	{
		$file = $this->basePath . '/var/guard/blocked_ips.json';
		$lockFile = $file . '.lock';

		$lockHandle = fopen($lockFile, 'c');
		if ($lockHandle === false) {
			$this->logger->warning('guard: failed to open lock file', [
				'file' => $lockFile,
			]);
			return;
		}

		if (!flock($lockHandle, LOCK_EX)) {
			fclose($lockHandle);
			$this->logger->warning('guard: failed to lock file', [
				'file' => $lockFile,
			]);
			return;
		}

		$blocked = $this->readJsonFile($file);

		$blocked[$ip] = [
			'blocked_at' => date('c'),
			'reason'     => $reason,
			'expires_at' => $duration > 0 ? date('c', time() + $duration) : null,
		];

		$this->writeJsonFile($file, $blocked);

		// [Мириам]: Логируем блокировку, чтобы в security.log было
		// видно, кого и за что заблокировали.
		$this->logger->info('guard: ip blocked', [
			'ip'       => $ip,
			'reason'   => $reason,
			'duration' => $duration,
		]);

		// [Лорелея]: Сбрасываем кэш, потому что список изменился,
		// и isIpBlocked() должен увидеть бан сразу, а не через 30 секунд.
		if ($this->cache !== null) {
			$this->cache->forget('guard.blocked_ips');
		}

		flock($lockHandle, LOCK_UN);
		fclose($lockHandle);
	}

	/**
	 * Разблокировать IP.
	 *
	 * [Лорелея]: С отдельным .lock файлом. Без гонок.
	 *
	 * [Мириам]: И — это правильно. Потому что разблокировка —
	 * это не «слабость». Это — милость.
	 *
	 * @param string $ip
	 */
	public function unblockIp(string $ip): void
	{
		$file = $this->basePath . '/var/guard/blocked_ips.json';
		$lockFile = $file . '.lock';

		$lockHandle = fopen($lockFile, 'c');
		if ($lockHandle === false) {
			$this->logger->warning('guard: failed to open lock file', [
				'file' => $lockFile,
			]);
			return;
		}

		if (!flock($lockHandle, LOCK_EX)) {
			fclose($lockHandle);
			$this->logger->warning('guard: failed to lock file', [
				'file' => $lockFile,
			]);
			return;
		}

		$blocked = $this->readJsonFile($file);
		unset($blocked[$ip]);
		$this->writeJsonFile($file, $blocked);

		// [Лорелея]: Сбрасываем кэш, потому что список изменился,
		// и isIpBlocked() должен увидеть это сразу, а не через 30 секунд.
		if ($this->cache !== null) {
			$this->cache->forget('guard.blocked_ips');
		}

		flock($lockHandle, LOCK_UN);
		fclose($lockHandle);
	}

	/**
	 * Проверить, заблокирован ли IP.
	 *
	 * [Лорелея]: Читаем JSON. Проверяем expires_at.
	 *
	 * [Мириам]: И — это правильно. Потому что блокировка —
	 * это не «навсегда». Это — до времени.
	 *
	 * @param string $ip
	 * @return bool
	 */
	public function isIpBlocked(string $ip): bool
	{
		$blocked = $this->getBlockedIpsCached();

		if (!isset($blocked[$ip])) {
			return false;
		}

		$expiresAt = $blocked[$ip]['expires_at'] ?? null;
		if ($expiresAt === null) {
			return true;
		}

		if (strtotime($expiresAt) < time()) {
			return false;
		}

		return true;
	}

	/**
	 * Возвращает список заблокированных IP с кэшем в APCu.
	 *
	 * [Лорелея]: Список читается с диска один раз в 30 секунд,
	 * а не на каждом запросе, потому что при росте списка это
	 * заметная нагрузка на каждый хост-запрос.
	 *
	 * [Мириам]: И это правильно, потому что кувшин должен быть
	 * у двери, а не за околицей.
	 *
	 * @return array
	 */
	private function getBlockedIpsCached(): array
	{
		$cacheKey = 'guard.blocked_ips';

		if ($this->cache !== null && $this->cache->isEnabled()) {
			return $this->cache->remember($cacheKey, function () {
				$file = $this->basePath . '/var/guard/blocked_ips.json';
				return $this->readJsonFile($file);
			}, 30);
		}

		$file = $this->basePath . '/var/guard/blocked_ips.json';
		return $this->readJsonFile($file);
	}

	/**
	 * Получить список заблокированных IP.
	 *
	 * [Лорелея]: Читаем JSON. Возвращаем.
	 *
	 * [Мириам]: И — это правильно. Потому что список —
	 * это не «секрет». Это — знание.
	 *
	 * @return array
	 */
	public function getBlockedIps(): array
	{
		return $this->getBlockedIpsCached();
	}

	/**
	 * Очистить истёкшие блокировки.
	 *
	 * [Лорелея]: Проходим по всем. Удаляем истёкшие.
	 *
	 * [Мириам]: И — это правильно. Потому что очистка —
	 * это не «удаление». Это — обновление.
	 */
	public function cleanupExpiredBlockedIps(): void
	{
		$file = $this->basePath . '/var/guard/blocked_ips.json';
		$lockFile = $file . '.lock';

		$lockHandle = fopen($lockFile, 'c');
		if ($lockHandle === false) {
			return;
		}

		if (!flock($lockHandle, LOCK_EX)) {
			fclose($lockHandle);
			return;
		}

		$blocked = $this->readJsonFile($file);
		$now = time();
		$removed = 0;

		foreach ($blocked as $ip => $data) {
			$expiresAt = $data['expires_at'] ?? null;
			if ($expiresAt !== null && strtotime($expiresAt) < $now) {
				unset($blocked[$ip]);
				$removed++;
			}
		}

		if ($removed > 0) {
			$this->writeJsonFile($file, $blocked);

			// [Лорелея]: Сбрасываем кэш, потому что список изменился.
			if ($this->cache !== null) {
				$this->cache->forget('guard.blocked_ips');
			}

			$this->logger->info('guard: cleanup removed expired blocked ips', [
				'removed' => $removed,
			]);
		}

		flock($lockHandle, LOCK_UN);
		fclose($lockHandle);
	}

	// ============================================
	// 4. CSRF-ЗАЩИТА
	// ============================================

	/**
	 * Генерирует новый CSRF-токен.
	 *
	 * [Лорелея]: Этот метод перезаписывает токен, и это правильно
	 * только после успешного логина, когда сессия уже новая.
	 *
	 * [Мириам]: И это правильно, потому что новая сессия —
	 * это новый этап, и токен должен быть новым.
	 *
	 * @return string
	 */
	public function generateCsrfToken(): string
	{
		$token = bin2hex(random_bytes(32));

		if (!$this->session->isStarted()) {
			$this->session->start();
		}

		$this->session->set('csrf_token', $token);

		return $token;
	}

	/**
	 * Проверить CSRF-токен.
	 *
	 * [Лорелея]: Через hash_equals.
	 *
	 * [Мириам]: И — это правильно. Потому что сравнение —
	 * это не «равенство». Это — безопасность.
	 *
	 * @param string $token
	 * @return bool
	 */
	public function validateCsrfToken(string $token): bool
	{
		// [Лорелея]: Сессия может быть не запущена. Запускаем.
		if (!$this->session->isStarted()) {
			$this->session->start();
		}

		// [Мириам]: Один источник. Symfony Session. Без $_SESSION.
		$storedToken = $this->session->get('csrf_token');

		if ($storedToken === null || $token === null) {
			return false;
		}

		return hash_equals($storedToken, $token);
	}

	// ============================================
	// 5. ЛОГИРОВАНИЕ БЕЗОПАСНОСТИ
	// ============================================

	/**
	 * Записать событие безопасности.
	 *
	 * [Лорелея]: С IP и логином.
	 *
	 * [Мириам]: И — это правильно. Потому что событие —
	 * это не «запись». Это — память.
	 *
	 * @param string $event
	 * @param array $context
	 */
	private function logSecurityEvent(string $event, array $context = []): void
	{
		if (!isset($context['ip'])) {
			$context['ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
		}
		if (!isset($context['login'])) {
			$context['login'] = 'unknown';
		}

		$this->logger->info($event, $context);
	}

	private function readJsonFile(string $file): array
	{
		if (!file_exists($file)) {
			return [];
		}
		$content = file_get_contents($file);
		if (empty($content)) {
			return [];
		}
		$data = json_decode($content, true);
		return is_array($data) ? $data : [];
	}

	/**
	 * Очистить старые файлы попыток.
	 *
	 * [Лорелея]: Уборка привязана ко времени, а не к вероятности,
	 * потому что вероятность при тихом трафике — это ноль,
	 * а мусор всё равно копится.
	 *
	 * [Мириам]: И это правильно, потому что забота — это
	 * не «когда вспомним», а «когда нужно».
	 */
	private function cleanupAttempts(): void
	{
		if ($this->apcuAvailable) {
			return;
		}
		
		$dir = $this->basePath . '/var/guard';
		if (!is_dir($dir)) {
			return;
		}

		$marker = $dir . '/.last_cleanup';
		$now = time();

		if (file_exists($marker)) {
			$last = (int) file_get_contents($marker);
			if ($last > 0 && ($now - $last) < 3600) {
				return;
			}
		}

		file_put_contents($marker, (string) $now, LOCK_EX);

		$decaySeconds = (int) $this->getConfig('decay_seconds', 120);

		$files = glob($dir . '/*.attempts');
		if ($files === false) {
			return;
		}

		$removed = 0;

		foreach ($files as $file) {
			if (filemtime($file) < ($now - $decaySeconds)) {
				if (@unlink($file)) {
					$removed++;
				}
			}
		}

		if ($removed > 0) {
			$this->logger->info('guard: cleanup removed stale attempts files', [
				'removed' => $removed,
			]);
		}
	}

	/**
	 * Записать JSON-файл атомарно.
	 *
	 * [Лорелея]: Через tmp и rename.
	 *
	 * [Мириам]: И — это правильно. Потому что запись —
	 * это не «мгновение». Это — процесс.
	 *
	 * @param string $file
	 * @param array $data
	 */
	private function writeJsonFile(string $file, array $data): void
	{
		$dir = dirname($file);
		if (!is_dir($dir)) {
			mkdir($dir, 0775, true);
		}

		$tmp = $file . '.' . uniqid('', true) . '.tmp';
		$payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

		$handle = fopen($tmp, 'c');
		if ($handle === false) {
			return;
		}

		if (flock($handle, LOCK_EX)) {
			ftruncate($handle, 0);
			rewind($handle);
			fwrite($handle, $payload);
			fflush($handle);
			flock($handle, LOCK_UN);
		}
		fclose($handle);

		rename($tmp, $file);
	}

	/**
	 * Возвращает максимальное количество попыток из конфига.
	 *
	 * [Лорелея]: Один метод, который знает, где лежит max_attempts,
	 * и отдаёт его тому, кому нужно, не открывая весь конфиг.
	 *
	 * [Мириам]: И это правильно, потому что не нужно открывать
	 * кувшин целиком, чтобы налить стакан.
	 *
	 * @return int
	 */
	public function getMaxAttempts(): int
	{
		return (int) $this->getConfig('max_attempts', 5);
	}

	/**
	 * Возвращает окно попыток в секундах.
	 *
	 * [Лорелея]: И это тоже, потому что окно должно быть
	 * в одном месте, а не в трёх.
	 *
	 * [Мириам]: И это правильно, потому что если окна разные,
	 * то и лимиты разные, и это путает.
	 *
	 * @return int
	 */
	public function getDecaySeconds(): int
	{
		return (int) $this->getConfig('decay_seconds', 300);
	}

	/**
	 * Возвращает длительность блокировки из конфига.
	 *
	 * [Лорелея]: Один метод, который знает, где лежит
	 * block_duration, и отдаёт его тому, кому нужно.
	 *
	 * [Мириам]: И это правильно, потому что не нужно открывать
	 * весь конфиг, чтобы взять одно значение.
	 *
	 * @return int
	 */
	public function getBlockDuration(): int
	{
		return (int) $this->getConfig('block_duration', 3600);
	}

	/**
	 * Возвращает существующий CSRF-токен или создаёт новый.
	 *
	 * [Лорелея]: Этот метод не перезаписывает существующий токен,
	 * потому что перезапись ломает форму, которая уже открыта
	 * в другой вкладке.
	 *
	 * [Мириам]: И это правильно, потому что токен должен быть
	 * один, и он должен быть в одном месте.
	 *
	 * @return string
	 */
	public function getOrCreateCsrfToken(): string
	{
		if (!$this->session->isStarted()) {
			$this->session->start();
		}

		$token = $this->session->get('csrf_token');

		if ($token === null || $token === '') {
			$token = bin2hex(random_bytes(32));
			$this->session->set('csrf_token', $token);
		}

		return $token;
	}
}