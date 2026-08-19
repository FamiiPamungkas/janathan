<?php

require __DIR__ . '/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load();

$templatesDir = __DIR__ . '/../templates';

$twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader($templatesDir), [
    'cache' => false,
    'strict_variables' => false,
]);

$twig->addFunction(new \Twig\TwigFunction('asset', fn (string $path) => '/' . ltrim($path, '/')));
$twig->addFunction(new \Twig\TwigFunction('base_path', fn () => ''));
$twig->addFunction(new \Twig\TwigFunction('url_for', fn (string $name, array $params = []) => $name));
$twig->addFunction(new \Twig\TwigFunction('path_info', fn () => '/'));
$twig->addFunction(new \Twig\TwigFunction('flash', fn () => []));

$translator = new \Fame1302\Janathan\Services\TranslationService(
    (string) ($_SESSION['locale'] ?? 'en'),
    ['en' => 'English', 'id' => 'Bahasa Indonesia']
);
$twig->addFunction(new \Twig\TwigFunction('trans', fn (string $key, array $replace = []) => $translator->trans($key, $replace)));
$twig->addGlobal('locale', $translator->getLocale());
$twig->addGlobal('locales', $translator->getAvailable());

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($templatesDir, FilesystemIterator::SKIP_DOTS)
);

$errors = 0;
foreach ($files as $file) {
    if ($file->getExtension() !== 'twig') {
        continue;
    }
    $name = substr($file->getPathname(), strlen($templatesDir) + 1);
    try {
        $twig->load($name);
        echo "OK   $name\n";
    } catch (\Throwable $e) {
        $errors++;
        echo "FAIL $name: {$e->getMessage()}\n";
    }
}

echo $errors === 0 ? "All templates compile.\n" : "{$errors} template(s) failed.\n";
exit($errors === 0 ? 0 : 1);
