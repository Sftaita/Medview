<?php

declare(strict_types=1);

namespace App\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Safety net for the whole /api/ surface: without this, any exception not
 * explicitly caught by a controller/handler falls through to Symfony's
 * default error rendering, which — in APP_ENV=dev — is a full HTML debug
 * page with the exception message, file paths and stack trace. UAT found
 * two ways to reach that fallthrough on public, unauthenticated endpoints
 * (a registration race condition, and a malformed Content-Type on login) —
 * see docs/decisions.md. Rather than patch every individual cause, this
 * guarantees /api/ never renders anything but clean JSON, regardless of
 * environment or which exception type is involved.
 *
 * The exception itself is still logged in full (visible via `docker logs`
 * / the app log) — only the HTTP response is sanitized.
 */
#[AsEventListener(event: 'kernel.exception', priority: -10)]
final class ApiExceptionListener
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $exception = $event->getThrowable();

        if ($exception instanceof HttpExceptionInterface) {
            // Symfony's own HTTP exceptions (404, 405, ...) carry messages
            // that are already safe to show ("No route found for GET ...").
            $status = $exception->getStatusCode();
            $message = $exception->getMessage() ?: 'An error occurred.';
            $headers = $exception->getHeaders();
        } else {
            // Anything else is unexpected: never echo its message/trace.
            $this->logger->error('Unhandled exception on {path}: {message}', [
                'path' => $request->getPathInfo(),
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
            $status = 500;
            $message = 'An unexpected error occurred.';
            $headers = [];
        }

        $event->setResponse(new JsonResponse(['error' => 'server_error', 'message' => $message], $status, $headers));
    }
}
