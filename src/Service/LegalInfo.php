<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * The provider's legal details and the legal pages' fixed dates, read from
 * config/legal.yaml — the one place they're written (see that file).
 */
class LegalInfo
{
    public const array FIELDS = ['name', 'nif', 'address', 'email', 'hosting'];
    public const array PAGES  = ['legal', 'terms', 'privacy', 'cookies'];

    /** @var array{owner: array<string, string>, updated: array<string, string>}|null */
    private ?array $data = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/legal.yaml')] private readonly string $file,
    ) {
    }

    /** The field's value, '' while not filled in. */
    public function get(string $field): string
    {
        return trim((string) ($this->load()['owner'][$field] ?? ''));
    }

    /** True while any owner field is still empty (the pages show the draft notice). */
    public function isIncomplete(): bool
    {
        foreach (self::FIELDS as $field) {
            if ($this->get($field) === '') {
                return true;
            }
        }

        return false;
    }

    /** A legal page's fixed "last updated" date. */
    public function updated(string $page): \DateTimeImmutable
    {
        return new \DateTimeImmutable((string) ($this->load()['updated'][$page] ?? 'today'));
    }

    /** The terms' version saved on each new account: their date. */
    public function termsVersion(): string
    {
        return $this->updated('terms')->format('Y-m-d');
    }

    private function load(): array
    {
        return $this->data ??= Yaml::parseFile($this->file) ?? [];
    }
}
