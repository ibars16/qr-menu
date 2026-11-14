<?php

namespace App\Service;

final class MenuImportPdfException extends \RuntimeException
{
    public function __construct(public readonly MenuImportPdfError $error)
    {
        parent::__construct('Menu import PDF: ' . $error->name);
    }
}
