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
    private const VOUCHERS_PER_PROFILE = 1;

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
                $price = ($profile['price'] === '' || $profile['price'] === '0') ? '999999' : $profile['price'];
                $items .= "<div data-item>\n"
                    . '        <p data-profile>' . htmlspecialchars($profile['name']) . "</p>\n"
                    . '        <p data-price>' . htmlspecialchars($price) . "</p>\n"
                    . '        <p data-color>' . htmlspecialchars($profile['color']) . "</p>\n"
                    . '        <p data-user>' . htmlspecialchars('1234') . "</p>\n"
                    . '        <p data-pass>' . htmlspecialchars('1234') . "</p>\n"
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
     * @param array{name: string, password: string} $user
     * @param array{name: string, color: string, price: string} $profile
     */
    public function renderDefaultUser(array $user, array $profile): string
    {
        $file = file_get_contents($this->defaultTemplatePath);

        if ($file === false) {
            return '<!DOCTYPE html><html><body><p>Default voucher template file is missing.</p></body></html>';
        }

        $price = (string)($profile['price'] ?? '');
        $price = ($price === '' || $price === '0') ? '999999' : $price;
        $items = "<div data-item>\n"
            . '        <p data-profile>' . htmlspecialchars((string)($profile['name'] ?? '')) . "</p>\n"
            . '        <p data-price>' . htmlspecialchars($price) . "</p>\n"
            . '        <p data-color>' . htmlspecialchars((string)($profile['color'] ?? '')) . "</p>\n"
            . '        <p data-user>' . htmlspecialchars((string)($user['name'] ?? '')) . "</p>\n"
            . '        <p data-pass>' . htmlspecialchars((string)($user['password'] ?? '')) . "</p>\n"
            . "    </div>\n";

        return preg_replace(
            '#<div id="data"[^>]*>.*?<div id="result">#s',
            '<div id="data" class="d-none">' . "\n" . $items . "</div>\n<div id=\"result\">",
            $file,
            1
        ) ?? $file;
    }

    /**
     * @param array{header: string, row: string, footer: string} $template
     * @param array{name: string, password: string} $user
     * @param array{name: string, color: string, price: string} $profile
     */
    public function renderCustomUser(array $template, array $user, array $profile): string
    {
        $row = $this->fillUser($template['row'], $profile, $user);

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
            . $row
            . $template['footer']
            . '</body>'
            . '</html>';
    }

    /**
     * @param array{name: string, color: string, price: string} $profile
     * @param array{name: string, password: string} $user
     */
    private function fillUser(string $html, array $profile, array $user): string
    {
        $price = (string)($profile['price'] ?? '');
        $price = ($price === '' || $price === '0') ? '999999' : $price;

        return str_replace(
            ['{username}', '{password}', '{profile}', '{price}', '{color}'],
            [
                htmlspecialchars((string)($user['name'] ?? ''), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string)($user['password'] ?? ''), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string)($profile['name'] ?? ''), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($price, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string)($profile['color'] ?? ''), ENT_QUOTES, 'UTF-8'),
            ],
            $html
        );
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
        $price = ($profile['price'] === '' || $profile['price'] === '0') ? '999999' : $profile['price'];
        return str_replace(
            ['{username}', '{password}', '{profile}', '{price}', '{color}'],
            [
                '1234',
                '1234',
                htmlspecialchars($profile['name'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($price, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($profile['color'], ENT_QUOTES, 'UTF-8'),
            ],
            $html
        );
    }
}