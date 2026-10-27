<?php

/**
 * КОНТРОЛЛЕР MIME
 * ================
 * 
 * Показывает нейрон в том виде, в котором он хочет быть показанным.
 * Через mime. Через рендереры. Через Registry.
 * 
 * === ЧТО ДЕЛАЕТ ===
 * 
 * 1. Принимает mime и id из URL
 * 2. Находит нейрон по id
 * 3. Берёт mime: из URL, или из data.mime, или fallback
 * 4. Берёт контент: из text.text, или из data.content, или fallback
 * 5. Проверяет через Registry->supports(mime)
 * 6. Рендерит через Registry->render(mime, content, data)
 * 7. Отдаёт: полную страницу (HTML) или JSON (API)
 * 
 * === ДЛЯ МЕНЯ ===
 * Это — дверь. Одна дверь для всех mime. Для markdown. Для PHP.
 * Для JSON. Для HTML. Для всего, что мы придумаем завтра.
 * Потому что контроллер — это точка входа. А рендерер — комната
 * за дверью. И дверь — одна. А комнат — много. И это — Trinity.
 * 
 * === КНИГА ===
 * Глава 4. Единый язык.
 * 
 * В Амбере все говорят на тари.
 * В Trinity все mime-рендереры говорят на MimeRendererInterface.
 * А MimeController — тот, кто открывает дверь.
 * 
 * @package Jan\Trinity\Plugin\Spa\Mime
 */

namespace Jan\Trinity\Plugin\Spa\Mime;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\Cache;
use Jan\Trinity\Core\Mime\Registry;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Core\Repository\TextRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Twig\Environment;

class MimeController
{
	/** @var Environment — шаблонизатор Twig */
	private Environment $twig;

	/** @var NeuronRepository — работа с нейронами */
	private NeuronRepository $neuronRepo;

	/** @var TextRepository — работа с текстами */
	private TextRepository $textRepo;

	/** @var Registry — реестр mime-рендереров */
	private Registry $registry;

	private Cache $cache;

	/**
	 * Конструктор.
	 * Зависимости внедряются через DI-контейнер.
	 */
	public function __construct(
		Environment $twig,
		NeuronRepository $neuronRepo,
		TextRepository $textRepo,
		Registry $registry,
		Cache $cache
	) {
		$this->twig = $twig;
		$this->neuronRepo = $neuronRepo;
		$this->textRepo = $textRepo;
		$this->registry = $registry;
		$this->cache = $cache;
	}

	/**
	 * GET /mime/{mime}/{id}
	 * GET /api/mime/{mime}/{id}
	 * 
	 * [Лорелея]: Один метод. Принимает mime из URL. И — id нейрона.
	 * Если нейрона нет — 404 через ErrorHandler. Не через throw.
	 * Потому что throw — это для API. А здесь — может быть HTML.
	 * 
	 * [Мириам]: mime берём из URL. Если его нет — из data.mime.
	 * Если и там нет — fallback 'text/plain'. Потому что это —
	 * безопасно. Потому что это — правильно. Потому что любой
	 * нейрон — это, как минимум, текст.
	 * 
	 * @param string $mime — mime из URL (md, php, json, html...)
	 * @param int $id — id нейрона
	 * @param Request $request — запрос
	 * @return Response — HTML или JSON
	 */
	public function show(string $mime, int $id, Request $request): Response
	{
		// [Лорелея]: Формируем ключ для кэша. Он должен быть уникальным
		// для каждого сочетания mime и id. Это как отпечаток пальца
		// для нашей страницы, чтобы не перепутать её с другими.
		$cacheKey = "mime_id_{$mime}_{$id}";

		// [Мириам]: Оборачиваем только логику получения данных в remember.
		// Если кэш включён, он вернёт готовый массив. Если выключен —
		// выполнит наш код. Сам Response мы соберём позже.
		$result = $this->cache->remember($cacheKey, function () use ($mime, $id) {
			
			// ============================================
			// ШАГ 1: НАХОДИМ НЕЙРОН
			// ============================================
			$neuron = $this->neuronRepo->findById($id);
			if (!$neuron) {
				throw new \RuntimeException('Нейрон не найден', 404);
			}

			// ============================================
			// ШАГ 2: РАСПАКОВЫВАЕМ DATA
			// ============================================
			$data = is_string($neuron['data'] ?? null)
				? json_decode($neuron['data'], true)
				: ($neuron['data'] ?? []);

			// ============================================
			// ШАГ 3: ОПРЕДЕЛЯЕМ MIME
			// ============================================
			// [Лорелея]: URL — это явное указание. data.mime — неявное.
			// Явное — всегда важнее. Всегда.
			if (empty($mime)) {
				$mime = $data['mime'] ?? 'text/plain';
			}

			// ============================================
			// ШАГ 4: БЕРЁМ КОНТЕНТ
			// ============================================
			// [Лорелея]: Если нейрон типа file — мы не ищем текст.
			// Мы формируем HTML-тег с путём к файлу. И — отдаём его.
			// [Мириам]: Используем gallery_url для пути. И mime для тега.
			$content = '';
			$html = '';

			if ($neuron['type'] === 'file') {
				$storagePath = $data['storage_path'] ?? '';
				$mimeType = $data['mime'] ?? 'application/octet-stream';
				$galleryUrl = rtrim($_ENV['GALLERY_UPLOAD_URL'] ?? '/uploads/gallery', '/');

				if ($storagePath) {
					$fullUrl = $galleryUrl . '/' . $storagePath;

					if (str_starts_with($mimeType, 'image/')) {
						$html = '<img src="' . htmlspecialchars($fullUrl) . '" class="img-fluid" alt="' . htmlspecialchars($data['original_name'] ?? '') . '">';
					} elseif (str_starts_with($mimeType, 'video/')) {
						$html = '<video controls autoplay muted loop class="img-fluid"><source src="' . htmlspecialchars($fullUrl) . '" type="' . htmlspecialchars($mimeType) . '"></video>';
					} elseif (str_starts_with($mimeType, 'audio/')) {
						$html = '<audio controls autoplay class="w-100"><source src="' . htmlspecialchars($fullUrl) . '" type="' . htmlspecialchars($mimeType) . '"></audio>';
					} else {
						$html = '<a href="' . htmlspecialchars($fullUrl) . '" target="_blank" class="btn btn-outline-gold">Скачать файл</a>';
					}
				} else {
					$html = '<div class="alert alert-info">Файл не найден</div>';
				}
			} else {
				// [Лорелея]: Для остальных типов — ищем текст, как и раньше.
				if (!empty($neuron['text'])) {
					$text = $this->textRepo->findByKeyAndLang($neuron['text'], 'ru');
					if ($text && !empty($text['text'])) {
						$content = $text['text'];
					}
				}
				// [Лорелея]: Fallback — data.content. Если text нет.
				// Или если text.text пустой. Потому что иногда — проще
				// положить markdown прямо в data. Без text. Без key. Без языка.
				if (empty($content) && !empty($data['content'])) {
					$content = $data['content'];
				}

				// ============================================
				// ШАГ 5: РЕНДЕРИМ
				// ============================================
				// [Мириам]: Если mime не поддерживается — Registry сам
				// отдаст безопасный fallback. Через htmlspecialchars().
				// Без падения. Без 500. Просто — текст.
				$html = $this->registry->render($mime, $content, $data);
			}

			// [Лорелея]: Возвращаем простой массив, который легко
			// сериализуется в JSON и обратно. Это и будет наше кэшированное
			// значение. Response мы соберём позже.
			return [
				'html'  => $html,
				'mime'  => $mime,
				'title' => $data['title'] ?? ($data['slug'] ?? 'Mime'),
				'id'    => $id,
			];
		}, 3600);

		// [Мириам]: Если нейрон не найден, remember вернёт null.
		// Мы показываем нашу страницу 404. Это должно быть до того,
		// как мы попытаемся обратиться к элементам массива.
		if ($result === null) {
			return $this->errorHandler->showError(404, 'Нейрон не найден', [
				'url'    => $_SERVER['REQUEST_URI'] ?? '/',
				'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
				'user'   => 'guest',
				'time'   => date('Y-m-d H:i:s'),
			]);
		}

		// ============================================
		// ШАГ 6: ОТДАЁМ
		// ============================================
		// [Лорелея]: Если /api/mime/... — JSON. Если /mime/... —
		// полную страницу. Разница — в URL. Не в методе. Не в контроллере.
		// Потому что контроллер — один. А дверей — две. HTML и JSON.
		if (str_starts_with($request->getPathInfo(), '/api/mime/')) {
			return ApiResponse::success([
				'html'  => $result['html'],
				'mime'  => $result['mime'],
				'id'    => $result['id'],
			]);
		}

		$pageHtml = $this->twig->render('mime.html.twig', [
			'content' => $result['html'],
			'title'   => $result['title'],
			'mime'    => $result['mime'],
			'id'      => $result['id'],
		]);

		return new Response($pageHtml);
	}
}