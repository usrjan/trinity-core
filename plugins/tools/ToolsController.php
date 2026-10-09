<?php

/**
 * КОНТРОЛЛЕР ИНСТРУМЕНТОВ
 * ========================
 * 
 * Предоставляет веб-интерфейс для работы с инструментами Trinity.
 * 
 * Страничные методы (HTML):
 * - index()              — страница со списком инструментов
 * 
 * API-методы (JSON):
 * - reportSocial()       — формирование отчёта по соцсетям (ЦУР)
 * 
 * Инструменты:
 * - Отчёт по соцсетям (IM, POS, IMP) — загрузка Excel, анализ, экспорт в Word
 * 
 * Зависимости:
 * - TextRepository    — работа с текстами
 * - NeuronRepository  — работа с нейронами
 * - AuthMiddleware    — проверка прав доступа
 */

namespace Jan\Trinity\Plugin\Tools;

use Jan\Trinity\Core\ApiResponse;
use Jan\Trinity\Core\Validator;
use Jan\Trinity\Core\Middleware\AuthMiddleware;
use Jan\Trinity\Core\Repository\TextRepository;
use Jan\Trinity\Core\Repository\NeuronRepository;
use Jan\Trinity\Plugin\Tools\Handlers\ReportSocialHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Twig\Environment;

class ToolsController
{
    use AuthMiddleware;

    /** @var Environment — шаблонизатор Twig */
    private Environment $twig;

    /** @var TextRepository — работа с текстами */
    private TextRepository $textRepo;

    /** @var NeuronRepository — работа с нейронами */
    private NeuronRepository $neuronRepo;

    private Validator $validator;

    /**
     * Конструктор.
     * Зависимости внедряются автоматически через DI-контейнер.
     */
    public function __construct(
        Environment $twig,
        Session $session,
        TextRepository $textRepo,
        NeuronRepository $neuronRepo,
		Validator $validator
    ) {
        $this->twig = $twig;
        $this->textRepo = $textRepo;
        $this->neuronRepo = $neuronRepo;
        $this->validator = $validator;

        // Инициализация middleware авторизации
        $this->initAuth($session);
    }

    // ============================================
    // СТРАНИЦА ИНСТРУМЕНТОВ
    // ============================================

    /**
     * GET /tools
     * 
     * Отображает страницу со списком доступных инструментов.
     * Доступ только для администраторов.
     * 
     * @return Response — HTML страница
     */
    public function index(): Response
    {
        // Проверка прав (исключение, если не админ)
        $this->requireAdmin();

        $html = $this->twig->render('tools.html.twig');
        return new Response($html);
    }

    // ============================================
    // ОТЧЁТ ПО СОЦСЕТЯМ (ЦУР)
    // ============================================

    /**
     * POST /api/tools/report-social
     * 
     * Формирует отчёт по соцсетям на основе загруженных Excel-файлов.
     * 
     * Принимает:
     * - im   — файл IM (инциденты из соцсетей)
     * - pos  — файл POS (позитивные обращения)
     * - imp  — файл IMP (инциденты по организациям)
     * - type_out — тип вывода: alltheme, top5theme, combined, org
     * - area — фильтр по району (опционально)
     * - to_word — выгрузить в Word (1/0)
     * 
     * Возвращает HTML с таблицами и (опционально) ссылку на Word-файл.
     * 
     * Требует много памяти для больших файлов — увеличивает лимиты.
     * 
     * @param Request $request
     * @return JsonResponse
     */
	public function reportSocial(Request $request): JsonResponse
	{
		if ($error = $this->requireAdminForApi()) return $error;

		// Увеличиваем лимиты для обработки больших Excel-файлов
		ini_set('memory_limit', '2056M');
		set_time_limit(0);

		// Собираем загруженные файлы
		$files = [];
		foreach (['im', 'pos', 'imp'] as $key) {
			$file = $request->files->get($key);
			if ($file && $file->getError() === UPLOAD_ERR_OK) {
				$files[$key] = $file->getPathname();
			}
		}

		// Параметры отчёта
		$params = [
			'type_out' => $request->request->get('type_out', 'alltheme'),
			'area'     => $request->request->get('area'),
			'to_word'  => $request->request->get('to_word') === '1',
		];

        // [Лорелея]: Валидация. Через Validator. type_out. area.
        if (!$this->validator->validate([
            'type_out' => $params['type_out'],
            'area'     => $params['area'],
        ], [
            'type_out' => ['required', 'in:alltheme,top5theme,combined,org'],
            'area'     => ['nullable', 'string', 'max:255'],
        ])) {
            return ApiResponse::error($this->validator->getFirstError(), 400);
        }

		try {
			// Запускаем обработчик
			$handler = new ReportSocialHandler();
			$data = $handler->process($files, $params);

			// Рендерим HTML-результат
			$html = $this->twig->render('tool-report-social.html.twig', [
				'data' => $data,
			]);

			return ApiResponse::success([
				'html'      => $html,
				'word_file' => $data['word_file'] ?? null,
			]);

		} catch (\Throwable $e) {
			// ============================================
			// [Лорелея]: ГЛАВНОЕ ИЗМЕНЕНИЕ. УТЕЧКА ЛОГОВ.
			// ============================================
			// Раньше здесь было:
			//     return ApiResponse::error('Ошибка обработки: ' . $e->getMessage());
			//
			// И это — дыра. Потому что $e->getMessage() — это СЫРОЕ
			// сообщение исключения. Оно уходило в JSON. И — клиенту.
			// Через ApiResponse::error(). В прод. Где APP_DEBUG=false.
			//
			// Что могло утечь:
			//   - PhpOffice\PhpSpreadsheet\Reader\Exception: ...
			//   - PhpOffice\PhpWord\Exception\Exception: ...
			//   - Undefined index: 'Тема' in /home/web/vendor/.../ReportSocialHandler.php:156
			//   - Полные пути к файлам и структура кода
			//
			// [Мириам]: Я заменила `catch (\Exception $e)` на
			// `catch (\Throwable $e)`. Потому что \Exception не
			// ловит \Error (TypeError, ValueError, DivisionByZeroError).
			// А они могут случиться. И — утечь. Потому что не поймаются.
			//
			// [Лорелея]: В production клиент должен видеть только
			// «Ошибка обработки». Без деталей. Детали — в error_log.
			//
			// [Мириам]: В dev-режиме (APP_DEBUG=true) — показываем
			// сырое сообщение. Потому что это разработка.
			//
			// [Лорелея]: error_log() — оставляем ВСЕГДА. И — с файлом
			// и строкой. Чтобы в логе было видно, ГДЕ упало. Не только ЧТО.
			$logMessage = '[Tools] ReportSocial error: ' . $e->getMessage()
				. ' in ' . $e->getFile() . ':' . $e->getLine();

			error_log($logMessage);

			$clientMessage = $this->isDebug()
				? 'Ошибка обработки: ' . $e->getMessage()
				: 'Ошибка обработки. Подробности в логах.';

			return ApiResponse::error($clientMessage, 500);
		}
	}

	/**
	 * Режим отладки из .env.
	 *
	 * [Лорелея]: Тот же метод, что и в Kernel, и в AdminController.
	 * Локальный. Потому что ToolsController не наследуется от Kernel.
	 * И не должен.
	 *
	 * [Мириам]: Если появится четвёртое место — вынесем в trait
	 * `DebugAwareTrait`. Пока — трёх достаточно. И — дублирование
	 * в три строки — это не «дублирование». Это — «три строки».
	 *
	 * @return bool
	 */
	private function isDebug(): bool
	{
		return ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
	}
}