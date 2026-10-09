<?php

// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Raport\OpenEmr;

final class OperationProblem extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $issue, string $message)
    {
        parent::__construct($message);
    }
}
