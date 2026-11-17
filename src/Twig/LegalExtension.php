<?php

namespace App\Twig;

use App\Service\LegalInfo;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * config/legal.yaml in the templates (LegalInfo):
 *   legal('nif', locale)    the value, or a highlighted translated gap
 *                           ("[NIF pendiente]") while it's empty;
 *   legal_email(locale)     the contact email as a mailto: link, or its gap;
 *   legal_incomplete()      true while any field is empty (draft notice);
 *   legal_updated('terms')  the page's fixed "last updated" date.
 * Gap texts: public_site's legal.gap.* keys.
 */
class LegalExtension extends AbstractExtension
{
    public function __construct(
        private readonly LegalInfo $legal,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('legal', $this->value(...), ['is_safe' => ['html']]),
            new TwigFunction('legal_email', $this->email(...), ['is_safe' => ['html']]),
            new TwigFunction('legal_incomplete', $this->legal->isIncomplete(...)),
            new TwigFunction('legal_updated', $this->legal->updated(...)),
        ];
    }

    public function value(string $field, string $locale): string
    {
        $value = $this->legal->get($field);

        return $value !== '' ? htmlspecialchars($value, ENT_QUOTES) : $this->gap($field, $locale);
    }

    public function email(string $locale): string
    {
        $email = $this->legal->get('email');
        if ($email === '') {
            return $this->gap('email', $locale);
        }
        $e = htmlspecialchars($email, ENT_QUOTES);

        return '<a href="mailto:' . $e . '">' . $e . '</a>';
    }

    private function gap(string $field, string $locale): string
    {
        return '<mark class="legal-gap">[' . htmlspecialchars($this->translator->trans('legal.gap.' . $field, [], 'public_site', $locale), ENT_QUOTES) . ']</mark>';
    }
}
