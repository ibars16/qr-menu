<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * The dish cards under a Smart Waiter reply: the prompt (rule 11) asks the
 * AI to end with a line "DISHES: 12, 45" — the "id" of each dish it
 * suggests, as MenuContextBuilder sends it. This class takes that line out
 * of the reply and keeps only the ids the app can vouch for.
 *
 * split() — the line is NEVER shown: not on screen, not in the history, not
 * in the model comparison. It is removed however it comes:
 * - "DISHES: 12, 45" / "dishes = [12]" / "**DISHES:** 12" on a line of its
 *   own (any case, ids only after the colon);
 * - "DISHES" in capitals, with anything after it, from there to the end of
 *   its line — also in the middle of a sentence ("…te encantará. DISHES: 12");
 * - the reply's last line when it is one word + ":" + ids only (e.g. a
 *   translated "PLATOS: 12, 45") — only when every number in it is one of
 *   the ids that were sent, so a closing "Total: 24" is never taken;
 * - a line cut off while being written ("DI", "DISH" at the very end).
 *
 * It also strips a dish id the AI wrote INSIDE a sentence, against rule 11
 * ("el gazpacho (id 6546)", "[id 6546]", "(#6546)", "(6546)", "id 6546",
 * "#6546") — only when every number is an id of a dish sent in THIS
 * request ($menuIds, SmartWaiterDishFit::sentIds()), so "(2 personas)" or
 * a price stays. Those ids never become cards: only the DISHES line asks.
 *
 * pick() — an id is kept only if it is one of $allowedIds: the dishes sent
 * in THIS request that may get a card (SmartWaiterDishFit::cardIds(): this
 * restaurant's visible dishes, only "suitable" ones when the customer has
 * needs). Anything else — an id that doesn't exist, another restaurant's,
 * a hidden or not-suitable dish — is dropped and logged, never shown.
 * Duplicates go, the AI's order stays, at most MAX_CARDS.
 */
final class SmartWaiterDishRefs
{
    public const MAX_CARDS = 3;

    /** Formatting a model may put before the keyword ("**", "- ", "[", "`"…). */
    private const DECOR = '[ \t>*_#`\-\[(]*';

    public function __construct(private readonly LoggerInterface $logger) {}

    /**
     * @param list<int> $allowedIds SmartWaiterDishFit::cardIds()
     * @param list<int> $menuIds    SmartWaiterDishFit::sentIds(): every dish id sent, stripped from the text
     *
     * @return array{0: string, 1: list<int>} the reply without the line, and the dish ids to show as cards
     */
    public function pick(string $content, array $allowedIds, int $restaurantId, array $menuIds = []): array
    {
        [$text, $rawIds] = self::split($content, $allowedIds, $menuIds);

        $allowed = array_flip($allowedIds);
        $kept = [];
        $discarded = [];
        foreach ($rawIds as $id) {
            if (isset($allowed[$id])) {
                $kept[$id] = $id;
            } else {
                $discarded[] = $id;
            }
        }
        if ($discarded !== []) {
            $this->logger->warning('Smart Waiter: dish ids discarded (not among the dishes that may get a card)', [
                'restaurant_id' => $restaurantId,
                'discarded' => $discarded,
            ]);
        }

        return [$text, array_slice(array_values($kept), 0, self::MAX_CARDS)];
    }

    /**
     * @param list<int> $sentIds the ids this request sent (SmartWaiterDishFit::cardIds()) — only for the
     *   translated-keyword last line; the DISHES line itself is removed whatever ids it has
     * @param list<int> $menuIds every dish id sent (SmartWaiterDishFit::sentIds()): an id written inside a
     *   sentence is stripped only when it is one of these
     *
     * @return array{0: string, 1: list<int>} the text without any DISHES line, and every id written in one (unchecked)
     */
    public static function split(string $content, array $sentIds = [], array $menuIds = []): array
    {
        $found = [];
        $collect = static function (array $m) use (&$found): string {
            $found[] = $m[0];

            return '';
        };
        $d = self::DECOR;

        // A line of its own: the keyword in any case, then only ids.
        $text = preg_replace_callback('/^' . $d . '(?i:dish(?:es)?)[ \t]*[*_`\])]*[ \t]*[:=：][ \t*_`\[\]()#\d,;.\-–—]*$\n?/mu', $collect, $content);
        // "DISHES" in capitals: from there to the end of its line — the
        // whole line when it starts it, else wherever it starts.
        $text = preg_replace_callback('/^' . $d . 'DISH(?:ES)?\b[^\n]*$\n?/mu', $collect, $text);
        $text = preg_replace_callback('/[ \t]*[\[(*_`]*\bDISH(?:ES)?\b[^\n]*/u', $collect, $text);
        // Cut off while being written, at the very end: "DI", "DIS", "DISH"…
        $text = preg_replace_callback('/(?:^|\n)' . $d . 'DI(?:S(?:H(?:E)?)?)?[ \t]*\z/u', $collect, $text);
        // The last line as one word + ":" + ids only (e.g. "PLATOS: 12, 45"),
        // when all of them were sent — never a "Total: 24".
        $sent = array_flip($sentIds);
        $text = preg_replace_callback(
            '/(?:^|\n)' . $d . '\p{L}{1,20}[ \t]*[*_`]*[ \t]*[:=：][ \t]*\[?[ \t]*#?\d+(?:[ \t]*[,;][ \t]*#?\d+)*[ \t]*\]?\.?[ \t]*\z/u',
            static function (array $m) use ($collect, $sent): string {
                preg_match_all('/\d+/', $m[0], $n);
                foreach ($n[0] as $digits) {
                    if (!isset($sent[(int) $digits])) {
                        return $m[0];
                    }
                }

                return $collect($m);
            },
            rtrim($text),
        );

        $ids = [];
        foreach ($found as $segment) {
            if (preg_match_all('/\d+/', $segment, $m) > 0) {
                foreach ($m[0] as $digits) {
                    if (strlen($digits) <= 9) {
                        $ids[] = (int) $digits;
                    }
                }
            }
        }

        $text = self::stripInlineIds($text, $menuIds);

        $text = preg_replace("/[ \t]+\n/", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return [trim($text), $ids];
    }

    /**
     * "(id 6546)", "[ID: 6546]", "(#6546)", "(6546)", "(id 6546, 6558)", "id 6546", "#6546" —
     * gone, with the space before them, when every number is in $menuIds; then the spaces
     * they leave (doubled, or before a comma or full stop) are tidied. Nothing else is touched.
     *
     * @param list<int> $menuIds
     */
    private static function stripInlineIds(string $text, array $menuIds): string
    {
        if ($menuIds === []) {
            return $text;
        }
        $menu = array_flip($menuIds);
        $removed = false;
        $strip = static function (array $m) use ($menu, &$removed): string {
            preg_match_all('/\d+/', $m[0], $n);
            foreach ($n[0] as $digits) {
                if (strlen($digits) > 9 || !isset($menu[(int) $digits])) {
                    return $m[0];
                }
            }
            $removed = true;

            return '';
        };

        // One id: "id 12", "ID: 12", "id#12", "#12", "12" (the last only in brackets).
        $one = '(?:(?i:ids?)\b[ \t]*[:#=.]?[ \t]*#?|#[ \t]*)?\d+';
        $list = $one . '(?:[ \t]*(?:[,;\/]|\b(?i:y|and|e|et|und)\b)[ \t]*' . $one . ')*';
        // In brackets: "(id 12)", "[#12]", "(12)", "(id 12, 45)".
        $text = preg_replace_callback('/[ \t]*[(\[][ \t]*' . $list . '[ \t]*[)\]]/u', $strip, $text);
        // Bare, never part of a word or a longer number, nor what's left of a
        // bracket kept above ("(id 12, 1234)"): "id 12", "ID: 12", "#12".
        $text = preg_replace_callback('/[ \t]*(?<![\p{L}\d(\[])(?:(?i:ids?)[ \t]*[:#=.]?[ \t]*#?|#[ \t]*)\d+(?![\d\p{L}])/u', $strip, $text);

        if (!$removed) {
            return $text;
        }

        $text = preg_replace('/[ \t]{2,}/', ' ', $text);
        $text = preg_replace('/([,;])(?:[ \t]*[,;])+/', '$1', $text); // "el gazpacho, id 12, es…" → one comma
        $text = preg_replace('/[ \t]+([,.;:!?)\]])/u', '$1', $text);

        return preg_replace('/^[ \t]+/m', '', $text);
    }
}
