<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\Admin\TechnicalErrorLog;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * A Messenger message whose handler threw — i.e. a crash the planning job
 * handler could not even record as a FAILED job (D149) — is a technical
 * error worth seeing on the infrastructure page (docs/decisions.md D177).
 * Only the exception class (the handler's, not Messenger's wrapper) and the
 * message class are kept.
 */
final class WorkerFailureListener
{
    public function __construct(private readonly TechnicalErrorLog $technicalErrors)
    {
    }

    #[AsEventListener]
    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $exception = $event->getThrowable();
        if ($exception instanceof HandlerFailedException) {
            $exception = array_values($exception->getWrappedExceptions())[0] ?? $exception;
        }

        $this->technicalErrors->recordWorker($exception, $event->getEnvelope()->getMessage()::class);
    }
}
