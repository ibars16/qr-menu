<?php

namespace App\Service\DishPhoto;

final class DishImageGenerationException extends \RuntimeException
{
    public function __construct(public readonly DishImageFailure $failure, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
