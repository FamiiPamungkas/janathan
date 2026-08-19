<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Middleware;

use Fame1302\Janathan\Services\TranslationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface as Middleware;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Twig\Environment;

class LocaleMiddleware implements Middleware
{
    public function __construct(
        private TranslationService $translator,
        private Environment $twig
    ) {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $locale = $_SESSION['locale'] ?? $this->detectFromHeader($request) ?? 'en';

        $this->translator->setLocale($locale);
        $this->twig->addGlobal('locale', $this->translator->getLocale());
        $this->twig->addGlobal('locales', $this->translator->getAvailable());

        return $handler->handle($request);
    }

    private function detectFromHeader(Request $request): ?string
    {
        $header = $request->getHeaderLine('Accept-Language');

        if ($header === '') {
            return null;
        }

        foreach (explode(',', $header) as $part) {
            $code = strtolower(trim(explode(';', $part)[0]));

            if (array_key_exists($code, $this->translator->getAvailable())) {
                return $code;
            }

            $base = substr($code, 0, 2);

            if (array_key_exists($base, $this->translator->getAvailable())) {
                return $base;
            }
        }

        return null;
    }
}
