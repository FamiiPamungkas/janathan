<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Services;

/**
 * Renders voucher templates to a standalone printable HTML document using
 * dummy data, one voucher per profile selection.
 *
 * The default (system) template is a self-contained page in
 * resources/voucher_template.html: it renders cards itself from a hidden
 * `#data` div, so rendering means injecting generated `[data-item]` blocks.
 * Custom templates are header/row/footer HTML fragments; the row is repeated
 * per voucher with placeholders replaced by dummy values.
 */
class VoucherTemplateRenderer
{
    private const VOUCHERS_PER_PROFILE = 5;

    private const CHARSET = 'abcdefghijklmnopqrstu1234567890';

    public function __construct(
        private readonly string $defaultTemplatePath = VoucherTemplateRepository::DEFAULT_TEMPLATE_PATH
    ) {
    }

    /**
     * @param list<array{name: string, color: string, price: string}> $profiles
     */
    public function renderDefault(array $profiles): string
    {
        $file = file_get_contents($this->defaultTemplatePath);

        if ($file === false) {
            return '<!DOCTYPE html><html><body><p>Default voucher template file is missing.</p></body></html>';
        }

        $items = '';
        foreach ($profiles as $profile) {
            for ($i = 0; $i < self::VOUCHERS_PER_PROFILE; $i++) {
                $items .= "<div data-item>\n"
                    . '        <p data-profile>' . htmlspecialchars($profile['name']) . "</p>\n"
                    . '        <p data-price>' . htmlspecialchars($profile['price']) . "</p>\n"
                    . '        <p data-color>' . htmlspecialchars($profile['color']) . "</p>\n"
                    . '        <p data-user>' . self::randomString(6) . "</p>\n"
                    . '        <p data-pass>' . self::randomString(4) . "</p>\n"
                    . "    </div>\n";
            }
        }

        return preg_replace(
            '#<div id="data"[^>]*>.*?<div id="result">#s',
            '<div id="data" class="d-none">' . "\n" . $items . "</div>\n<div id=\"result\">",
            $file,
            1
        ) ?? $file;
    }

    /**
     * @param array{header: string, row: string, footer: string} $template
     * @param list<array{name: string, color: string, price: string}> $profiles
     */
    public function renderCustom(array $template, array $profiles): string
    {
        $rows = '';
        foreach ($profiles as $profile) {
            for ($i = 0; $i < self::VOUCHERS_PER_PROFILE; $i++) {
                $rows .= $this->fill($template['row'], $profile);
            }
        }

        return '<!DOCTYPE html>'
            . '<html lang="en">'
            . '<head>'
            . '<meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<style>'
            . '@media print { body { background: #fff; } }'
            . '* { box-sizing: border-box; }'
            . 'body { margin: 0; padding: 16px; font-family: \'Segoe UI\', Helvetica, Arial, sans-serif; }'
            . '</style>'
            . '</head>'
            . '<body>'
            . $template['header']
            . $rows
            . $template['footer']
            . '</body>'
            . '</html>';
    }

    /**
     * Replace placeholders with the profile's dummy values.
     *
     * @param array{name: string, color: string, price: string} $profile
     */
    private function fill(string $html, array $profile): string
    {
        return str_replace(
            ['{username}', '{password}', '{profile}', '{price}', '{color}'],
            [
                self::randomString(6),
                self::randomString(4),
                htmlspecialchars($profile['name'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($profile['price'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($profile['color'], ENT_QUOTES, 'UTF-8'),
            ],
            $html
        );
    }

    private static function randomString(int $length): string
    {
        $string = '';
        $max = strlen(self::CHARSET) - 1;

        for ($i = 0; $i < $length; $i++) {
            $string .= self::CHARSET[random_int(0, $max)];
        }

        return $string;
    }
}