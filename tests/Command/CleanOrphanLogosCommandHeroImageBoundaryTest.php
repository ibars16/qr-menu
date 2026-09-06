<?php

namespace App\Tests\Command;

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic guard for the task requirement that Restaurant::$heroImage
 * (its own field/directory, public/uploads/heroes/) must stay completely
 * out of reach of app:logos:clean-orphans — a command hardcoded to
 * public/uploads/logos/ and to querying only the logo column
 * (CleanOrphanLogosCommand), meant to purge orphaned *logo* files. If it
 * ever scanned the hero directory too, --force there would delete live
 * hero images the moment a restaurant's current file wasn't also coincidentally
 * a "logo" reference.
 *
 * Asserts against the command's actual source rather than mocking its
 * dependencies, since the guarantee here is specifically "this command's
 * code never mentions heroImage/hero_image/uploads/heroes" — no DB or
 * kernel needed, so this runs even where pdo_pgsql (and so the full
 * functional suite) isn't available.
 */
final class CleanOrphanLogosCommandHeroImageBoundaryTest extends TestCase
{
    public function testCommandSourceNeverReferencesHeroImage(): void
    {
        $path = __DIR__ . '/../../src/Command/CleanOrphanLogosCommand.php';
        self::assertFileExists($path);

        $source = file_get_contents($path);

        self::assertStringNotContainsString('heroImage', $source);
        self::assertStringNotContainsString('hero_image', $source);
        self::assertStringNotContainsString('uploads/heroes', $source);
        // The one directory this command is allowed to know about.
        self::assertStringContainsString('uploads/logos', $source);
    }
}
