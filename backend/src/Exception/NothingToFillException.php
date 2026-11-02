<?php

declare(strict_types=1);

namespace App\Exception;

use App\Service\FillPreview;

/**
 * "Générer les postes non pourvus" / "Générer cette ligne" asked for lines
 * that have nothing to receive (docs/decisions.md D222): no job is queued,
 * nothing is written. The preview says, line by line, why.
 */
final class NothingToFillException extends \RuntimeException
{
    public function __construct(public readonly FillPreview $preview)
    {
        parent::__construct('None of the lines asked for has a duty to generate or to complete.');
    }
}
