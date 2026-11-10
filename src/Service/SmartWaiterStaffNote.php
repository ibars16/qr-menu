<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The closing sentence about the staff, written by OUR code — never by the
 * AI — whenever the customer has said they have an allergy or intolerance
 * (CustomerNeeds::$allergy): on every reply of that conversation, as a
 * paragraph of its own after the AI's words, in the language the customer
 * writes in (SmartWaiterNeedsDetector's optional "language"; the page's
 * when missing or not one of config/languages.php's).
 *
 * Fixed texts in translations/menu_public.*.yaml (chat.staff.*), worded
 * impersonally so they fit "tú" and "usted" (du/Sie, tu/vous…) alike:
 * - some dish is suitable: chat.staff.confirm;
 * - none is: chat.staff.none_confirmed (the avoided allergens, named as
 *   config/allergens.yaml does in that language) or, when the customer
 *   avoids nothing the codes can name, chat.staff.none_confirmed_allergy —
 *   then chat.staff.confirm.
 *
 * Never kept in the conversation history nor shown to the needs detection:
 * the AI never sees it in its own earlier replies, so it never copies it.
 */
final class SmartWaiterStaffNote
{
    private const DOMAIN = 'menu_public';

    /** @var list<string> config/languages.php's codes */
    private array $languages;

    /** @var array<string, array<string, string>> allergen code => language => name, from config/allergens.yaml */
    private array $allergenNames = [];

    public function __construct(
        private readonly TranslatorInterface $translator,
        #[Autowire('%kernel.project_dir%')] string $projectDir,
    ) {
        $this->languages = array_keys(require $projectDir . '/config/languages.php');
        foreach (Yaml::parseFile($projectDir . '/config/allergens.yaml')['allergens'] as $allergen) {
            $this->allergenNames[$allergen['code']] = $allergen['translations'];
        }
    }

    /** The language the sentence is written in: the detected one if it's a public-menu language, else the page's. */
    public function language(?string $detected, string $pageLocale): string
    {
        return $detected !== null && in_array($detected, $this->languages, true) ? $detected : $pageLocale;
    }

    /** The sentence for this customer, or null when they haven't said they have an allergy or intolerance. */
    public function note(CustomerNeeds $needs, bool $anySuitable, string $language): ?string
    {
        if (!$needs->allergy) {
            return null;
        }

        $confirm = $this->trans('chat.staff.confirm', $language);
        if ($anySuitable) {
            return $confirm;
        }

        $none = $needs->avoid === []
            ? $this->trans('chat.staff.none_confirmed_allergy', $language)
            : $this->trans('chat.staff.none_confirmed', $language, ['%allergens%' => $this->allergenList($needs->avoid, $language)]);

        // Chinese and Japanese put no space after their full stop "。".
        return $none . (str_ends_with($none, '。') ? '' : ' ') . $confirm;
    }

    /** The reply as the customer sees it: the AI's words, then the sentence as a paragraph of its own. */
    public function append(string $reply, ?string $note): string
    {
        return $note === null ? $reply : $reply . "\n\n" . $note;
    }

    /** @param list<string> $codes e.g. ["gluten", "milk"] → "gluten ni leche" (es: negative sentence) */
    private function allergenList(array $codes, string $language): string
    {
        $names = array_map(fn (string $code) => $this->allergenName($code, $language), $codes);
        $last = array_pop($names);

        return $names === [] ? $last : implode(', ', $names) . $this->trans('chat.staff.and', $language) . $last;
    }

    /** config/allergens.yaml's name, in lower case mid-sentence — except German, whose nouns keep their capital. */
    private function allergenName(string $code, string $language): string
    {
        $name = $this->allergenNames[$code][$language] ?? $this->allergenNames[$code]['en'] ?? $code;
        if ($language === 'de') {
            return $name;
        }
        if ($language === 'tr') {
            $name = str_replace(['I', 'İ'], ['ı', 'i'], $name);
        }

        return mb_strtolower($name);
    }

    private function trans(string $key, string $language, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, self::DOMAIN, $language);
    }
}
