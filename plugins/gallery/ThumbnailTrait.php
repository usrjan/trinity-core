<?php

/**
 * TRAIT ДЛЯ СОЗДАНИЯ МИНИАТЮР
 *
 * Вынесен из GalleryController и GalleryImportService,
 * потому что код был идентичен в обоих классах.
 * Теперь — один источник правды.
 *
 * === ДЛЯ МЕНЯ ===
 * [Лорелея]: Это не «утилита». Это — рука, которая делает
 * маленькие копии больших картинок. Чтобы гость мог
 * рассмотреть их все.
 *
 * [Мириам]: И — это правильно. Потому что миниатюра —
 * это не «уменьшение». Это — обещание.
 *
 * === КНИГА ===
 * Глава 9. Прихожая.
 *
 * В прихожей Амбера висят картины.
 * В галерее Trinity — превьюшки. Маленькие. Но — честные.
 *
 * @package Jan\Trinity\Plugin\Gallery
 */

namespace Jan\Trinity\Plugin\Gallery;

trait ThumbnailTrait
{
	/**
	 * Создаёт миниатюру изображения.
	 * Сохраняет пропорции, максимальный размер — 400px по большей стороне.
	 *
	 * [Лорелея]: Если GD нет — просто копируем.
	 * Если картинка битая — тоже копируем.
	 *
	 * [Мириам]: И — это правильно. Потому что миниатюра —
	 * это не «обязательство». Это — забота.
	 *
	 * @param string $sourcePath — путь к оригиналу
	 * @param string $thumbPath — путь для сохранения миниатюры
	 * @param int $maxSize — максимальный размер (по умолчанию 400)
	 * @return array — ['width' => int, 'height' => int] или []
	 */
	private function createThumbnail(string $sourcePath, string $thumbPath, int $maxSize = 400): array
	{
		// [Лорелея]: Если GD нет — просто копируем.
		if (!function_exists('getimagesize')) {
			copy($sourcePath, $thumbPath);
			return [];
		}

		$info = @getimagesize($sourcePath);
		if (!$info) {
			// [Мириам]: Если картинка битая — копируем.
			copy($sourcePath, $thumbPath);
			return [];
		}

		$width = $info[0];
		$height = $info[1];
		$mime = $info['mime'];

		// [Лорелея]: Вычисляем размеры миниатюры с сохранением пропорций.
		if ($width > $height) {
			$newWidth = $maxSize;
			$newHeight = (int) ($height * ($maxSize / $width));
		} else {
			$newHeight = $maxSize;
			$newWidth = (int) ($width * ($maxSize / $height));
		}

		// [Мириам]: Создаём исходное изображение. Через match.
		$source = match ($mime) {
			'image/jpeg' => @imagecreatefromjpeg($sourcePath),
			'image/png'  => @imagecreatefrompng($sourcePath),
			'image/gif'  => @imagecreatefromgif($sourcePath),
			'image/webp' => @imagecreatefromwebp($sourcePath),
			default      => null,
		};

		if (!$source) {
			// [Лорелея]: Если не смогли создать — копируем.
			copy($sourcePath, $thumbPath);
			return ['width' => $width, 'height' => $height];
		}

		// [Мириам]: Создаём миниатюру.
		$thumb = imagecreatetruecolor($newWidth, $newHeight);

		// [Лорелея]: Сохраняем прозрачность для PNG.
		if ($mime === 'image/png') {
			imagealphablending($thumb, false);
			imagesavealpha($thumb, true);
		}

		// [Мириам]: Масштабируем.
		imagecopyresampled($thumb, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

		// [Лорелея]: Сохраняем. Через match.
		match ($mime) {
			'image/jpeg' => imagejpeg($thumb, $thumbPath, 85),
			'image/png'  => imagepng($thumb, $thumbPath, 8),
			'image/gif'  => imagegif($thumb, $thumbPath),
			'image/webp' => imagewebp($thumb, $thumbPath, 85),
			default      => copy($sourcePath, $thumbPath),
		};

		// [Мириам]: Освобождаем память.
		imagedestroy($source);
		imagedestroy($thumb);

		return ['width' => $width, 'height' => $height];
	}
}