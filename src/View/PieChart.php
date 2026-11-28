<?php
declare(strict_types=1);

namespace Saa\View;

use InvalidArgumentException;

/**
 * Gráfico de pizza em SVG gerado no servidor: funciona sem JS e respeita a CSP (só atributos de apresentação).
 * As fatias começam às 12 h e seguem no sentido horário, na ordem recebida.
 */
final class PieChart
{
    /** Cor da superfície dos cartões; a separação de 2px entre as fatias usa essa cor. */
    public const SURFACE = '#ffffff';
    private const EMPTY_RING = '#c3cfcd';
    private const MIN_SIZE = 16;
    private const MAX_SIZE = 1024;

    /** @var list<array{label: string, value: int|float, color: string}> */
    private array $slices = [];
    private int|float $total = 0;

    /** @param list<array{label: string, value: int|float, color: string}> $slices */
    public function __construct(array $slices)
    {
        if ($slices === [] || !array_is_list($slices)) {
            throw new InvalidArgumentException('Informe as fatias do gráfico em uma lista.');
        }
        foreach ($slices as $slice) {
            $label = is_array($slice) ? ($slice['label'] ?? null) : null;
            $value = is_array($slice) ? ($slice['value'] ?? null) : null;
            $color = is_array($slice) ? ($slice['color'] ?? null) : null;
            if (!is_string($label) || trim($label) === '') {
                throw new InvalidArgumentException('Cada fatia precisa de um rótulo.');
            }
            if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < 0) {
                throw new InvalidArgumentException('O valor da fatia deve ser um número finito e não negativo.');
            }
            if (!is_string($color) || preg_match('/^#[0-9a-f]{6}\z/i', $color) !== 1) {
                throw new InvalidArgumentException('A cor da fatia deve estar no formato #rrggbb.');
            }
            $this->slices[] = ['label' => $label, 'value' => $value, 'color' => strtolower($color)];
            $this->total += $value;
        }
        if (!is_finite((float)$this->total)) {
            throw new InvalidArgumentException('A soma das fatias é grande demais.');
        }
    }

    public function total(): int|float
    {
        return $this->total;
    }

    /**
     * Ângulos em graus a partir das 12 h (sentido horário) e porcentagem de cada fatia.
     *
     * @return list<array{label: string, value: int|float, color: string, percent: ?float,
     *     start: float, end: float, sweep: float, large_arc: bool}>
     */
    public function geometry(): array
    {
        $result = [];
        $before = 0;
        foreach ($this->slices as $slice) {
            $after = $before + $slice['value'];
            // Ângulos pelo valor acumulado, e não somando aberturas, para a última fatia fechar em 360° exatos.
            $start = $this->total > 0 ? (float)(360 * $before / $this->total) : 0.0;
            $end = $this->total > 0 ? (float)(360 * $after / $this->total) : 0.0;
            $result[] = $slice + ['percent' => self::percent($slice['value'], $this->total),
                'start' => $start, 'end' => $end, 'sweep' => $end - $start, 'large_arc' => $end - $start > 180];
            $before = $after;
        }
        return $result;
    }

    /** Porcentagem truncada em uma casa, a mesma regra do relatório: 89,995% aparece como 89,9%, nunca 90,0%. */
    public static function percent(int|float $value, int|float $total): ?float
    {
        return $total > 0 ? floor(1000 * $value / $total) / 10 : null;
    }

    /** Frase lida pelos leitores de tela, por exemplo "Presenças 45 de 50 (90,0%), ausências 5 (10,0%)". */
    public function accessibleLabel(): string
    {
        if ($this->total <= 0) {
            return 'Sem dados';
        }
        $parts = [];
        foreach ($this->geometry() as $index => $slice) {
            $parts[] = ($index === 0 ? $slice['label'] : self::lowerFirst($slice['label'])) . ' '
                . self::number($slice['value']) . ($index === 0 ? ' de ' . self::number($this->total) : '')
                . ' (' . self::percentText($slice['percent']) . ')';
        }
        return implode(', ', $parts);
    }

    /** Pizza em SVG; com $decorative o gráfico fica oculto dos leitores de tela porque o texto ao lado já traz os números. */
    public function svg(int $size, bool $decorative = false): string
    {
        if ($size < self::MIN_SIZE || $size > self::MAX_SIZE) {
            throw new InvalidArgumentException('Tamanho do gráfico fora do intervalo aceito.');
        }
        $radius = $size / 2;
        $center = self::coord($radius);
        $svg = sprintf('<svg xmlns="http://www.w3.org/2000/svg" class="pie-chart" width="%1$d" height="%1$d" viewBox="0 0 %1$d %1$d" %2$s>',
            $size, $decorative ? 'aria-hidden="true"' : 'role="img" aria-label="' . self::escape($this->accessibleLabel()) . '"');
        if ($this->total <= 0) {
            $ring = max(2, $size / 10);
            return $svg . sprintf('<circle cx="%1$s" cy="%1$s" r="%2$s" fill="none" stroke="%3$s" stroke-width="%4$s"><title>Sem dados</title></circle></svg>',
                $center, self::coord($radius - $ring / 2 - 1), self::EMPTY_RING, self::coord($ring));
        }
        $base = '';
        $wedges = '';
        foreach ($this->geometry() as $slice) {
            if ($slice['sweep'] <= 0) {
                continue; // Fatia zerada não desenha nada; o número continua no rótulo e na legenda.
            }
            // O contorno na cor da superfície abre a separação de 2px entre fatias vizinhas.
            $paint = sprintf('fill="%s" stroke="%s" stroke-width="2" stroke-linejoin="round"', $slice['color'], self::SURFACE);
            $title = '<title>' . self::escape($this->sliceTitle($slice)) . '</title>';
            [$fromX, $fromY] = self::point($radius, $slice['start']);
            [$toX, $toY] = self::point($radius, $slice['end']);
            $sameEnds = $fromX === $toX && $fromY === $toY;
            if ($slice['sweep'] >= 360 || ($sameEnds && $slice['large_arc'])) {
                // Um arco não pode voltar ao próprio ponto: a fatia (quase) inteira vira círculo, por baixo das demais.
                $base .= sprintf('<circle cx="%1$s" cy="%1$s" r="%1$s" %2$s>%3$s</circle>', $center, $paint, $title);
                continue;
            }
            if ($sameEnds) {
                continue; // Fina demais para aparecer no tamanho pedido.
            }
            $wedges .= sprintf('<path d="M%1$s %1$sL%2$s %3$sA%1$s %1$s 0 %4$d 1 %5$s %6$sZ" %7$s>%8$s</path>',
                $center, $fromX, $fromY, $slice['large_arc'] ? 1 : 0, $toX, $toY, $paint, $title);
        }
        return $svg . $base . $wedges . '</svg>';
    }

    /** Legenda com amostra da cor, rótulo, valor e porcentagem; o texto fica nas cores de texto, nunca na cor da fatia. */
    public function legend(string $id = ''): string
    {
        $html = '<ul class="pie-legend"' . ($id === '' ? '' : ' id="' . self::escape($id) . '"') . '>';
        foreach ($this->geometry() as $slice) {
            $share = $slice['percent'] === null ? ''
                : ' <span class="pie-legend-share">· ' . self::percentText($slice['percent']) . '</span>';
            $html .= '<li>' . self::swatch($slice['color'])
                . '<span class="pie-legend-label">' . self::escape($slice['label']) . '</span> '
                . '<strong>' . self::number($slice['value']) . '</strong>' . $share . '</li>';
        }
        return $html . '</ul>';
    }

    /**
     * Chave de cores sem números, para uma grade de pizzas pequenas com as mesmas fatias.
     * Fica oculta dos leitores de tela porque só explica as cores de gráficos decorativos.
     */
    public function key(): string
    {
        $html = '<ul class="pie-legend pie-key" aria-hidden="true">';
        foreach ($this->slices as $slice) {
            $html .= '<li>' . self::swatch($slice['color'])
                . '<span class="pie-legend-label">' . self::escape($slice['label']) . '</span></li>';
        }
        return $html . '</ul>';
    }

    private static function swatch(string $color): string
    {
        return '<svg class="pie-swatch" width="12" height="12" viewBox="0 0 12 12" aria-hidden="true">'
            . '<rect width="12" height="12" rx="3" fill="' . $color . '"/></svg>';
    }

    /** Dica nativa ao passar o mouse na fatia. */
    private function sliceTitle(array $slice): string
    {
        return $slice['label'] . ': ' . self::number($slice['value']) . ' de ' . self::number($this->total)
            . ' (' . self::percentText($slice['percent']) . ')';
    }

    /** @return array{0: string, 1: string} */
    private static function point(float $radius, float $degrees): array
    {
        $radians = deg2rad($degrees);
        return [self::coord($radius + $radius * sin($radians)), self::coord($radius - $radius * cos($radians))];
    }

    private static function coord(float $value): string
    {
        $text = rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');
        return $text === '-0' ? '0' : $text;
    }

    private static function number(int|float $value): string
    {
        if (is_int($value) || floor($value) === $value) {
            return number_format($value, 0, ',', '.');
        }
        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
    }

    private static function percentText(?float $percent): string
    {
        return $percent === null ? '' : number_format($percent, 1, ',', '.') . '%';
    }

    /** "Ausências" vira "ausências" no meio da frase; siglas como "AOE" ficam como estão. */
    private static function lowerFirst(string $label): string
    {
        $second = mb_substr($label, 1, 1);
        if ($second === '' || mb_strtolower($second) !== $second) {
            return $label;
        }
        return mb_strtolower(mb_substr($label, 0, 1)) . mb_substr($label, 1);
    }

    /** Saída válida tanto em HTML quanto em XML: caracteres proibidos no XML viram U+FFFD. */
    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_DISALLOWED | ENT_XML1, 'UTF-8');
    }
}
