<?php

namespace App\Service;

/**
 * Safety net for the chat bubble, which shows a reply exactly as typed
 * (textContent): strips the Markdown a model may add despite the
 * instruction's "plain text only" — the same for Gemini, Groq and OpenAI.
 *
 * Minimal on purpose, never touching the words themselves:
 * - **text** and __text__ → text; any "**" left over is dropped;
 * - at the start of a line, "#"… headings and "-", "*", "•" bullets are
 *   dropped, the line's text kept.
 * Everything else stays: snake_case, 2*3, a dash inside a sentence, a lone _.
 *
 * Invisible characters a model may leave (e.g. a zero-width space after the
 * last full stop) are dropped: U+2060 and U+FEFF always, U+200B unless it
 * sits between two Thai letters (Thai writes no spaces; it marks word
 * breaks). ZWJ/ZWNJ (Hindi, emoji) and the direction marks (Arabic) stay.
 *
 * Then capitals, since a model may write a whole reply in lower case: the
 * first letter of the reply, of each line, and after ". ", "! " or "? "
 * (opening ¿ ¡ « " ( skipped) is made upper case — never the other way
 * round. Not after an abbreviation ("p. ej.", "e.g.", "etc.", "approx.")
 * nor "...". Turkish i → İ; scripts without capitals stay as they are.
 */
final class SmartWaiterReplyCleaner
{
    /** Words that end with a dot without ending the sentence — besides one letter ("p.") and dotted ones ("e.g.", "z.B."). */
    private const ABBREVIATIONS = ['etc', 'ej', 'eg', 'ie', 'vs', 'sr', 'sra', 'dr', 'nr', 'aprox', 'approx', 'usw', 'bzw', 'evtl', 'inkl', 'zzgl', 'ggf', 'vgl', 'ecc', 'pág', 'núm'];

    /** @param ?string $language the reply's language (e.g. "tr" for its dotted İ); null = no special case */
    public function clean(string $reply, ?string $language = null): string
    {
        $text = preg_replace('/\*\*(.+?)\*\*/su', '$1', $reply);
        $text = preg_replace('/(?<![\p{L}\p{N}_])__(.+?)__(?![\p{L}\p{N}_])/su', '$1', $text);
        $text = str_replace('**', '', $text);
        $text = preg_replace('/^[ \t]*#{1,6}[ \t]+/mu', '', $text);
        $text = preg_replace('/^[ \t]*[-*•][ \t]+/mu', '', $text);
        $text = preg_replace('/[\x{2060}\x{FEFF}]|(?<!\p{Thai})\x{200B}|\x{200B}(?!\p{Thai})/u', '', $text);

        return $this->capitalize(trim($text), $language);
    }

    private function capitalize(string $text, ?string $language): string
    {
        return preg_replace_callback(
            '/(^|\n|[.!?][ \t]+)([¡¿«"“(\']*)(\p{Ll})/u',
            function (array $m) use ($text, $language): string {
                [[$all], [$before, $at], [$opening], [$letter]] = $m;
                if (str_starts_with($before, '.')) {
                    // The word the dot ends — read from the text, so a match never eats the next one's.
                    $word = preg_match('/(\S*)$/u', substr($text, 0, $at), $w) === 1 ? $w[1] . '.' : '.';
                    $bare = preg_replace('/[^\p{L}]/u', '', $word);
                    if (str_ends_with($word, '..') || mb_strlen($bare) <= 1 || substr_count($word, '.') > 1 || in_array(mb_strtolower($bare), self::ABBREVIATIONS, true)) {
                        return $all;
                    }
                }
                $upper = $language === 'tr' && $letter === 'i' ? 'İ' : mb_strtoupper($letter);

                return $before . $opening . $upper;
            },
            $text,
            flags: PREG_OFFSET_CAPTURE,
        );
    }
}
