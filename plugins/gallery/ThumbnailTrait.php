<?php

/**
 * TRAIT ДЛЯ СОЗДАНИЯ МИНИАТЮР
 * 
 * Вынесен из GalleryController и GalleryImportService,
 * потому что код был идентичен в обоих классах.
 * Теперь — один источник правды.
 * 
 * Использование:
 *   use ThumbnailTrait;
 * 
 * Метод:
 *   createThumbnail(string $sourcePath, string $thumbPath, int $maxSize = 400): array
 */

namespace Jan\Trinity\Plugin\Gallery;

trait ThumbnailTrait
{
	/**
	 * Создаёт миниатюру изображения.
	 * Сохраняет пропорции, максимальный размер — 400px по большей стороне.
	 * 
	 * @param string $sourcePath — путь к оригиналу
	 * @param string $thumbPath — путь для сохранения миниатюры
	 * @param int $maxSize — максимальный размер (по умолчанию 400)
	 * @return array — ['width' => int, 'height' => int] или []
	 */
	private function createThumbnail(string $sourcePath, string $thumbPath, int $maxSize = 400): array
	{
		if (!function_exists('getimagesize')) {
			copy($sourcePath, $thumbPath);
			return [];
		}

		$info = @getimagesize($sourcePath);
		if (!$info) {
			copy($sourcePath, $thumbPath);
			return [];
		}

		$width = $info[0];
		$height = $info[1];
		$mime = $info['mime'];

		// Вычисляем размеры миниатюры с сохранением пропорций
		if ($width > $height) {
			$newWidth = $maxSize;
			$newHeight = (int) ($height * ($maxSize / $width));
		} else {
			$newHeight = $maxSize;
			$newWidth = (int) ($width * ($maxSize / $height));
		}

		// Создаём исходное изображение
		$source = match ($mime) {
			'image/jpeg' => @imagecreatefromjpeg($sourcePath),
			'image/png'  => @imagecreatefrompng($sourcePath),
			'image/gif'  => @imagecreatefromgif($sourcePath),
			'image/webp' => @imagecreatefromwebp($sourcePath),
			default      => null,
		};

		if (!$source) {
			copy($sourcePath, $thumbPath);
			return ['width' => $width, 'height' => $height];
		}

		// Создаём миниатюру
		$thumb = imagecreatetruecolor($newWidth, $newHeight);

		// Сохраняем прозрачность для PNG
		if ($mime === 'image/png') {
			imagealphablending($thumb, false);
			imagesavealpha($thumb, true);
		}

		// Масштабируем
		imagecopyresampled($thumb, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

		// Сохраняем
		match ($mime) {
			'image/jpeg' => imagejpeg($thumb, $thumbPath, 85),
			'image/png'  => imagepng($thumb, $thumbPath, 8),
			'image/gif'  => imagegif($thumb, $thumbPath),
			'image/webp' => imagewebp($thumb, $thumbPath, 85),
			default      => copy($sourcePath, $thumbPath),
		};

		imagedestroy($source);
		imagedestroy($thumb);

		return ['width' => $width, 'height' => $height];
	}
}