<?php

namespace App\Service\Upload;

enum UploadValidationError
{
    case InvalidFile;
    case UnsupportedType;
    case TooLarge;
    case DimensionsTooSmall;
    case DimensionsTooLarge;
    case DurationTooLong;
    case Rejected;
}
