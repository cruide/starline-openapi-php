<?php namespace Cruide\StarlineApi\Auth;

/**
 * Pure-PHP распознаватель капчи на базе GD.
 *
 * Без внешних зависимостей, без вызовов командной строки.
 * Требуется расширение ext-gd.
 *
 * Алгоритм:
 *  - бинаризация адаптивным порогом;
 *  - очистка от шума (мелкие изолированные группы пикселей);
 *  - сегментация символов по разрывам вертикальной проекции;
 *  - нормализация символа в сетку 10×15;
 *  - распознавание по набору геометрических признаков.
 *
 * @author Alexander Tischenko (http://alex-tisch.ru)
 */
final class GdOcr implements OcrInterface
{
    /** Порог яркости по умолчанию (0..255). При 0 используется OTSU. */
    private int $threshold;

    /** Минимальный размер связной компоненты (меньше — шум). */
    private int $minComponentSize;

    /** Минимальная ширина (пикселей) сегмента, чтобы считаться символом. */
    private int $minSymbolWidth;

    /** Эталонные признаки для цифр 0-9 и букв a-z. */
    private array $templates;

    /**
     * @param int $threshold Порог бинаризации (0 = авто/OTSU, иначе фиксированный 0..255).
     * @param int $minComponentSize Минимальный размер компоненты-символа (пикселей), меньше — удаляется.
     * @param int $minSymbolWidth Минимальная ширина сегмента в пикселях.
     */
    public function __construct(
        int $threshold = 0,
        int $minComponentSize = 8,
        int $minSymbolWidth = 4
    ) {
        $this->threshold = $threshold;
        $this->minComponentSize = $minComponentSize;
        $this->minSymbolWidth = $minSymbolWidth;
        $this->templates = $this->buildTemplates();
    }

    /**
     * {@inheritDoc}
     */
    public function decode(string $imageData): ?string
    {
        $img = @imagecreatefromstring($imageData);

        if ($img === false) {
            return null;
        }

        $w = imagesx($img);
        $h = imagesy($img);

        // 1. Приведение к оттенкам серого
        imagefilter($img, IMG_FILTER_GRAYSCALE);

        // 2. Усиление контраста
        imagefilter($img, IMG_FILTER_CONTRAST, -50);

        // 3. Бинаризация
        $pixels = $this->binarize($img, $w, $h);
        imagedestroy($img);

        // 4. Очистка шума
        $this->removeNoise($pixels, $w, $h);

        // 5. Сегментация
        $segments = $this->segmentCharacters($pixels, $w, $h);

        if ($segments === []) {
            return null;
        }

        // 6. Распознавание
        $result = '';

        foreach ($segments as $charPixels) {
            $result .= $this->recognize($charPixels);
        }

        return $result !== '' ? $result : null;
    }

    /**
     * Получить яркость пикселя (0..255), корректно для truecolor и палитровых.
     */
    private function pixelGray(\GdImage $img, int $x, int $y): int
    {
        $rgb = imagecolorat($img, $x, $y);

        if ($rgb === false) {
            return 0;
        }

        if (imageistruecolor($img)) {
            // Truecolor: RGB упакован в int
            return $rgb & 0xFF;
        }

        // Палитровое: $rgb — индекс, нужен imagecolorsforindex
        $colors = imagecolorsforindex($img, $rgb);

        if ($colors === false) {
            return 0;
        }

        return (int) (($colors['red'] + $colors['green'] + $colors['blue']) / 3);
    }

    /**
     * Бинаризовать изображение: каждый пиксель — true (чёрный/текст) или false (белый/фон).
     *
     * Использует OTSU (авто) или фиксированный порог.
     *
     * @param \GdImage $img
     * @return array<int, array<int, bool>>
     */
    private function binarize(\GdImage $img, int $w, int $h): array
    {
        $pixels = [];
        $histogram = [];

        // Первый проход: собираем яркости и гистограмму
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $gray = $this->pixelGray($img, $x, $y);

                if (!isset($histogram[$gray])) {
                    $histogram[$gray] = 0;
                }
                $histogram[$gray]++;
                $pixels[$y][$x] = $gray;
            }
        }

        if ($this->threshold === 0) {
            $threshold = $this->otsuThreshold($histogram, $w * $h);
        } else {
            $threshold = $this->threshold;
        }

        // Второй проход: бинаризация
        $binary = [];

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                // Тёмные пиксели (ниже порога) считаем текстом (true)
                $binary[$y][$x] = $pixels[$y][$x] < $threshold;
            }
        }

        return $binary;
    }

    /**
     * Вычислить OTSU-порог по гистограмме.
     *
     * @param array<int, int> $histogram Яркость => количество пикселей.
     * @param int $total Общее количество пикселей.
     */
    private function otsuThreshold(array $histogram, int $total): int
    {
        $sum = 0;

        for ($i = 0; $i < 256; $i++) {
            $sum += $i * ($histogram[$i] ?? 0);
        }

        $sumB = 0;
        $wB = 0;
        $maxVariance = 0.0;
        $bestThreshold = 128;

        for ($t = 0; $t < 256; $t++) {
            $count = $histogram[$t] ?? 0;
            $wB += $count;

            if ($wB === 0) {
                continue;
            }

            $wF = $total - $wB;

            if ($wF === 0) {
                break;
            }

            $sumB += $t * $count;
            $mB = $sumB / $wB;
            $mF = ($sum - $sumB) / $wF;
            $variance = (float) $wB * (float) $wF * ($mB - $mF) * ($mB - $mF);

            if ($variance >= $maxVariance) {
                $bestThreshold = $t;
                $maxVariance = $variance;
            }
        }

        return $bestThreshold;
    }

    /**
     * Удалить изолированные группы пикселей (шум).
     *
     * Использует алгоритм заливки (flood fill) для поиска связных компонент.
     * Компоненты размером меньше $minComponentSize удаляются.
     *
     * @param array<int, array<int, bool>> $pixels
     */
    private function removeNoise(array &$pixels, int $w, int $h): void
    {
        $visited = [];
        $components = [];

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if (!($pixels[$y][$x] ?? false) || ($visited[$y][$x] ?? false)) {
                    continue;
                }

                // Flood fill для поиска компоненты
                $component = [];
                $stack = [[$x, $y]];
                $visited[$y][$x] = true;

                while ($stack !== []) {
                    [$cx, $cy] = array_pop($stack);
                    $component[] = [$cx, $cy];

                    // 4-связные соседи
                    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                        $nx = $cx + $dx;
                        $ny = $cy + $dy;

                        if ($nx >= 0 && $nx < $w && $ny >= 0 && $ny < $h
                            && ($pixels[$ny][$nx] ?? false)
                            && !($visited[$ny][$nx] ?? false)
                        ) {
                            $visited[$ny][$nx] = true;
                            $stack[] = [$nx, $ny];
                        }
                    }
                }

                $components[] = $component;
            }
        }

        // Удаляем мелкие компоненты
        foreach ($components as $component) {
            if (count($component) < $this->minComponentSize) {
                foreach ($component as [$px, $py]) {
                    $pixels[$py][$px] = false;
                }
            }
        }
    }

    /**
     * Сегментировать изображение на отдельные символы.
     *
     * Использует вертикальную проекцию: столбцы без тёмных пикселей
     * считаются разрывами между символами.
     *
     * @param array<int, array<int, bool>> $pixels
     * @return array<int, array{minX: int, maxX: int, minY: int, maxY: int, pixels: array<int, array<int, bool>>}>
     */
    private function segmentCharacters(array $pixels, int $w, int $h): array
    {
        // Вертикальная проекция: количество тёмных пикселей в каждом столбце
        $vProjection = [];

        for ($x = 0; $x < $w; $x++) {
            $count = 0;

            for ($y = 0; $y < $h; $y++) {
                if ($pixels[$y][$x] ?? false) {
                    $count++;
                }
            }

            $vProjection[$x] = $count;
        }

        // Находим диапазоны столбцов с тёмными пикселями
        $ranges = [];
        $inChar = false;
        $startX = 0;

        for ($x = 0; $x < $w; $x++) {
            if ($vProjection[$x] > 0 && !$inChar) {
                $inChar = true;
                $startX = $x;
            } elseif ($vProjection[$x] === 0 && $inChar) {
                $inChar = false;
                $endX = $x - 1;

                if (($endX - $startX + 1) >= $this->minSymbolWidth) {
                    $ranges[] = [$startX, $endX];
                }
            }
        }

        // Последний символ (доходит до края)
        if ($inChar) {
            $endX = $w - 1;

            if (($endX - $startX + 1) >= $this->minSymbolWidth) {
                $ranges[] = [$startX, $endX];
            }
        }

        // Извлекаем сегменты
        $segments = [];

        foreach ($ranges as [$sx, $ex]) {
            // Находим вертикальные границы символа
            $minY = $h;
            $maxY = 0;

            for ($y = 0; $y < $h; $y++) {
                for ($x = $sx; $x <= $ex; $x++) {
                    if ($pixels[$y][$x] ?? false) {
                        if ($y < $minY) $minY = $y;
                        if ($y > $maxY) $maxY = $y;
                    }
                }
            }

            if ($minY > $maxY) {
                continue;
            }

            // Копируем пиксели сегмента
            $charPixels = [];

            for ($y = $minY; $y <= $maxY; $y++) {
                for ($x = $sx; $x <= $ex; $x++) {
                    $charPixels[$y][$x] = $pixels[$y][$x] ?? false;
                }
            }

            $segments[] = [
                'minX' => $sx,
                'maxX' => $ex,
                'minY' => $minY,
                'maxY' => $maxY,
                'pixels' => $charPixels,
            ];
        }

        return $segments;
    }

    /**
     * Распознать символ, нормализовав его и сравнив с эталонами.
     *
     * Сравнивает попиксельно (Hamming distance) с эталонными сетками.
     *
     * @param array $segment Информация о сегменте из segmentCharacters().
     */
    private function recognize(array $segment): string
    {
        $pixels = $segment['pixels'];
        $cw = $segment['maxX'] - $segment['minX'] + 1;
        $ch = $segment['maxY'] - $segment['minY'] + 1;

        if ($cw <= 0 || $ch <= 0) {
            return '';
        }

        // Нормализация в сетку 10×15
        $gridW = 10;
        $gridH = 15;
        $norm = [];

        for ($gy = 0; $gy < $gridH; $gy++) {
            for ($gx = 0; $gx < $gridW; $gx++) {
                $count = 0;
                $total = 0;

                $sy = (int) ($segment['minY'] + ($gy / $gridH) * $ch);
                $ey = (int) ($segment['minY'] + (($gy + 1) / $gridH) * $ch);
                $sx = (int) ($segment['minX'] + ($gx / $gridW) * $cw);
                $ex = (int) ($segment['minX'] + (($gx + 1) / $gridW) * $cw);

                for ($y = $sy; $y <= $ey && $y <= $segment['maxY']; $y++) {
                    for ($x = $sx; $x <= $ex && $x <= $segment['maxX']; $x++) {
                        $total++;

                        if ($pixels[$y][$x] ?? false) {
                            $count++;
                        }
                    }
                }

                // Бинаризуем ячейку: > 50% заполнения → 1
                $norm[$gy][$gx] = $total > 0 && ($count / $total) > 0.5 ? 1 : 0;
            }
        }

        // Поиск ближайшего эталона (Hamming distance по бинарной сетке)
        $bestChar = '';
        $bestDist = PHP_INT_MAX;

        foreach ($this->templates as $char => $templateGrid) {
            $dist = 0;

            for ($gy = 0; $gy < $gridH; $gy++) {
                for ($gx = 0; $gx < $gridW; $gx++) {
                    if (($norm[$gy][$gx] ?? 0) !== ($templateGrid[$gy][$gx] ?? 0)) {
                        $dist++;
                    }
                }
            }

            if ($dist < $bestDist) {
                $bestDist = $dist;
                $bestChar = $char;
            }
        }

        return $bestChar;
    }

    /**
     * Построить эталонные бинарные сетки для символов 0-9, a-z.
     *
     * Каждый символ рендерится шрифтом GD, находится bounding box,
     * и только он нормализуется в сетку 10×15 (бинаризуется).
     *
     * @return array<string, array<int, array<int, int>>>
     */
    private function buildTemplates(): array
    {
        $chars = '0123456789abcdefghijklmnopqrstuvwxyz';
        $templates = [];

        $gridW = 10;
        $gridH = 15;

        $imgW = 14;
        $imgH = 18;
        $fontSize = 5;

        for ($i = 0; $i < strlen($chars); $i++) {
            $char = $chars[$i];
            $img = imagecreatetruecolor($imgW, $imgH);

            if ($img === false) {
                continue;
            }

            $white = (int) imagecolorallocate($img, 255, 255, 255);
            $black = (int) imagecolorallocate($img, 0, 0, 0);

            imagefill($img, 0, 0, $white);
            imagestring($img, $fontSize, 2, 2, $char, $black);

            // Бинаризуем изображение чтобы найти bounding box
            $binary = [];

            for ($y = 0; $y < $imgH; $y++) {
                for ($x = 0; $x < $imgW; $x++) {
                    $binary[$y][$x] = $this->pixelGray($img, $x, $y) < 128;
                }
            }

            // Находим bounding box символа
            $minX = $imgW;
            $maxX = 0;
            $minY = $imgH;
            $maxY = 0;

            for ($y = 0; $y < $imgH; $y++) {
                for ($x = 0; $x < $imgW; $x++) {
                    if ($binary[$y][$x]) {
                        if ($x < $minX) $minX = $x;
                        if ($x > $maxX) $maxX = $x;
                        if ($y < $minY) $minY = $y;
                        if ($y > $maxY) $maxY = $y;
                    }
                }
            }

            $cw = $maxX - $minX + 1;
            $ch = $maxY - $minY + 1;

            if ($cw <= 0 || $ch <= 0) {
                imagedestroy($img);
                continue;
            }

            // Нормализуем bounding box в сетку 10×15
            $norm = [];

            for ($gy = 0; $gy < $gridH; $gy++) {
                for ($gx = 0; $gx < $gridW; $gx++) {
                    $count = 0;
                    $total = 0;

                    $sy = (int) ($minY + ($gy / $gridH) * $ch);
                    $ey = (int) ($minY + (($gy + 1) / $gridH) * $ch);
                    $sx = (int) ($minX + ($gx / $gridW) * $cw);
                    $ex = (int) ($minX + (($gx + 1) / $gridW) * $cw);

                    for ($y = $sy; $y <= $ey && $y < $imgH; $y++) {
                        for ($x = $sx; $x <= $ex && $x < $imgW; $x++) {
                            $total++;
                            if ($binary[$y][$x]) {
                                $count++;
                            }
                        }
                    }

                    $norm[$gy][$gx] = $total > 0 && ($count / $total) > 0.5 ? 1 : 0;
                }
            }

            imagedestroy($img);

            $templates[$char] = $norm;
        }

        return $templates;
    }
}
