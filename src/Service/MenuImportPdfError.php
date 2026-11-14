<?php

namespace App\Service;

/** Why a menu-import PDF couldn't be turned into pages (see MenuImportPdfSplitter). */
enum MenuImportPdfError
{
    case PasswordProtected;
    /** Broken, not really a PDF, or took too long to read. */
    case Unreadable;
    case NoPages;
}
