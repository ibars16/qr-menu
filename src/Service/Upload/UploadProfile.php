<?php

namespace App\Service\Upload;

/**
 * Selects which size/dimension limits UploadValidator enforces for a given
 * upload point. Add a case here (and its limits in UploadValidator::LIMITS)
 * rather than hand-rolling validation in a new controller.
 */
enum UploadProfile: string
{
    case Logo = 'logo';
    case DishImage = 'dish_image';
    case HeroImage = 'hero_image';
    case MenuImportPage = 'menu_import_page';
}
