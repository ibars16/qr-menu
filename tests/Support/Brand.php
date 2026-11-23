<?php

namespace App\Tests\Support;

use Symfony\Component\Yaml\Yaml;

/**
 * The product name as config/services.yaml defines it (app.brand_name), for
 * tests that run without the container. Tests compare against this, never
 * against the name written out.
 */
final class Brand
{
    public static function name(): string
    {
        $config = Yaml::parseFile(dirname(__DIR__, 2) . '/config/services.yaml', Yaml::PARSE_CUSTOM_TAGS);

        return $config['parameters']['app.brand_name'];
    }
}
