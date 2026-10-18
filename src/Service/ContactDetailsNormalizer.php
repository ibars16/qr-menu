<?php

namespace App\Service;

/**
 * Normalizes and validates the restaurant's contact details as typed in the
 * admin's Configuración screen: social/review links, phone, address and
 * weekly opening hours. Pure (no I/O): every method returns either the value
 * to store or an error code the controller maps to a translated message
 * under its field.
 *
 * Error codes: 'invalid' (malformed / not a profile URL / bad scheme),
 * 'wrong_domain' (a URL of another site in a network's field), 'too_long',
 * 'incomplete' (a shift with only one of its two times), 'overlap' (two
 * shifts of the same day overlapping), 'same_time' (open == close).
 */
final class ContactDetailsNormalizer
{
    public const NETWORKS = ['instagram', 'facebook', 'tiktok', 'google', 'tripadvisor', 'website'];
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    public const MAX_SHIFTS = 2;

    private const MAX_URL_LENGTH = 255;
    private const MAX_ADDRESS_LENGTH = 255;
    private const MAX_PHONE_LENGTH = 32;

    /** First path segments that are not profiles (posts, reels, app pages…). */
    private const INSTAGRAM_RESERVED = ['p', 'reel', 'reels', 'explore', 'stories', 'tv', 'accounts', 'direct'];
    private const FACEBOOK_RESERVED = ['sharer', 'sharer.php', 'share', 'groups', 'events', 'watch', 'login', 'help', 'photo.php', 'story.php', 'permalink.php', 'hashtag'];

    /**
     * @return array{0: ?string, 1: ?string} [normalized URL or null when empty, error code or null]
     */
    public function normalizeSocial(string $network, string $input): array
    {
        $input = trim($input);
        if ($input === '') {
            return [null, null];
        }
        if (mb_strlen($input) > self::MAX_URL_LENGTH || preg_match('/[\s\x00-\x1F\x7F]/u', $input)) {
            return [null, 'invalid'];
        }

        $result = match ($network) {
            'instagram'   => $this->instagram($input),
            'facebook'    => $this->facebook($input),
            'tiktok'      => $this->tiktok($input),
            'google'      => $this->google($input),
            'tripadvisor' => $this->tripadvisor($input),
            'website'     => $this->website($input),
            default       => throw new \InvalidArgumentException('Unknown network: ' . $network),
        };

        if ($result[0] !== null && strlen($result[0]) > self::MAX_URL_LENGTH) {
            return [null, 'too_long'];
        }

        return $result;
    }

    /**
     * Phone as typed (trimmed): digits, spaces, hyphens, parentheses, an
     * optional leading "+"; 7–15 digits in total.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function normalizePhone(string $input): array
    {
        $input = trim($input);
        if ($input === '') {
            return [null, null];
        }
        if (mb_strlen($input) > self::MAX_PHONE_LENGTH || !preg_match('/^\+?[0-9 ()\-]+$/', $input)) {
            return [null, 'invalid'];
        }
        $digits = strlen(preg_replace('/\D/', '', $input));
        if ($digits < 7 || $digits > 15) {
            return [null, 'invalid'];
        }

        return [$input, null];
    }

    /** @return array{0: ?string, 1: ?string} */
    public function normalizeAddress(string $input): array
    {
        $input = trim($input);
        if ($input === '') {
            return [null, null];
        }
        if (mb_strlen($input) > self::MAX_ADDRESS_LENGTH) {
            return [null, 'too_long'];
        }

        return [$input, null];
    }

    /**
     * $input is the posted hours[...] array: per day an optional 'closed'
     * flag and up to MAX_SHIFTS ['open' => 'HH:MM', 'close' => 'HH:MM'].
     *
     * @param array<string, mixed> $input
     *
     * @return array{0: ?array<string, list<array{0: string, 1: string}>>, 1: array<string, string>}
     *               [hours to store (null when nothing is configured), day key => error code]
     */
    public function normalizeOpeningHours(array $input): array
    {
        $hours = [];
        $errors = [];

        foreach (self::DAYS as $day) {
            $dayInput = is_array($input[$day] ?? null) ? $input[$day] : [];

            if (!empty($dayInput['closed'])) {
                $hours[$day] = [];
                continue;
            }

            $shifts = [];
            for ($i = 0; $i < self::MAX_SHIFTS; $i++) {
                $shift = is_array($dayInput[$i] ?? null) ? $dayInput[$i] : [];
                $open = trim((string) ($shift['open'] ?? ''));
                $close = trim((string) ($shift['close'] ?? ''));

                if ($open === '' && $close === '') {
                    continue;
                }
                if ($open === '' || $close === '') {
                    $errors[$day] = 'incomplete';
                    continue 2;
                }
                if (!$this->isTime($open) || !$this->isTime($close)) {
                    $errors[$day] = 'invalid';
                    continue 2;
                }
                if ($open === $close) {
                    $errors[$day] = 'same_time';
                    continue 2;
                }
                $shifts[] = [$open, $close];
            }

            if ($shifts === []) {
                continue;
            }
            if (count($shifts) === 2 && $this->overlaps($shifts[0], $shifts[1])) {
                $errors[$day] = 'overlap';
                continue;
            }

            usort($shifts, fn (array $a, array $b) => $this->minutes($a[0]) <=> $this->minutes($b[0]));
            $hours[$day] = $shifts;
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        return [$hours === [] ? null : $hours, []];
    }

    // ── Networks ────────────────────────────────────────────────────────────

    /** @return array{0: ?string, 1: ?string} */
    private function instagram(string $input): array
    {
        if (!$this->looksLikeUrl($input, 'instagram.com')) {
            $user = ltrim($input, '@');

            return preg_match('/^[A-Za-z0-9._]{1,30}$/', $user)
                ? ['https://www.instagram.com/' . $user . '/', null]
                : [null, 'invalid'];
        }

        [$url, $error] = $this->parseUrl($input, ['instagram.com']);
        if ($error !== null) {
            return [null, $error];
        }
        $user = $this->pathSegments($url)[0] ?? '';
        if (in_array(strtolower($user), self::INSTAGRAM_RESERVED, true) || !preg_match('/^[A-Za-z0-9._]{1,30}$/', $user)) {
            return [null, 'invalid'];
        }

        return ['https://www.instagram.com/' . $user . '/', null];
    }

    /** @return array{0: ?string, 1: ?string} */
    private function tiktok(string $input): array
    {
        if (!$this->looksLikeUrl($input, 'tiktok.com')) {
            $user = ltrim($input, '@');

            return preg_match('/^[A-Za-z0-9._]{2,24}$/', $user)
                ? ['https://www.tiktok.com/@' . $user, null]
                : [null, 'invalid'];
        }

        [$url, $error] = $this->parseUrl($input, ['tiktok.com']);
        if ($error !== null) {
            return [null, $error];
        }
        $segment = $this->pathSegments($url)[0] ?? '';
        if (!str_starts_with($segment, '@') || !preg_match('/^[A-Za-z0-9._]{2,24}$/', substr($segment, 1))) {
            return [null, 'invalid'];
        }

        return ['https://www.tiktok.com/' . $segment, null];
    }

    /** @return array{0: ?string, 1: ?string} */
    private function facebook(string $input): array
    {
        if (!$this->looksLikeUrl($input, 'facebook.com') && !$this->looksLikeUrl($input, 'fb.com')) {
            $slug = ltrim($input, '@');

            return $this->isFacebookSlug($slug)
                ? ['https://www.facebook.com/' . $slug, null]
                : [null, 'invalid'];
        }

        [$url, $error] = $this->parseUrl($input, ['facebook.com', 'fb.com']);
        if ($error !== null) {
            return [null, $error];
        }
        $segments = $this->pathSegments($url);
        $first = $segments[0] ?? '';

        if (strtolower($first) === 'profile.php') {
            parse_str($url['query'] ?? '', $query);
            $id = is_string($query['id'] ?? null) ? $query['id'] : '';

            return preg_match('/^\d{5,20}$/', $id)
                ? ['https://www.facebook.com/profile.php?id=' . $id, null]
                : [null, 'invalid'];
        }
        if (in_array(strtolower($first), self::FACEBOOK_RESERVED, true) || !$this->isFacebookSlug($first)) {
            return [null, 'invalid'];
        }

        return ['https://www.facebook.com/' . $first, null];
    }

    /** Google reviews / map: a URL only, from Google's own map/review hosts. */
    private function google(string $input): array
    {
        [$url, $error] = $this->parseUrl($input, null);
        if ($error !== null) {
            return [null, $error];
        }
        $host = $url['host'];
        $path = $url['path'] ?? '';

        $ok = $host === 'maps.app.goo.gl'
            || $host === 'g.page'
            || ($host === 'goo.gl' && str_starts_with($path, '/maps'))
            || (preg_match('/^(www\.)?google\.[a-z]{2,3}(\.[a-z]{2})?$/', $host) && str_starts_with($path, '/maps'))
            || preg_match('/^maps\.google\.[a-z]{2,3}(\.[a-z]{2})?$/', $host)
            || ($host === 'search.google.com' && str_starts_with($path, '/local/writereview'));

        if (!$ok) {
            return [null, $this->isGoogleHost($host) ? 'invalid' : 'wrong_domain'];
        }

        return [$this->buildUrl($url, 'https'), null];
    }

    /** TripAdvisor: a restaurant review page URL only, any country domain. */
    private function tripadvisor(string $input): array
    {
        [$url, $error] = $this->parseUrl($input, null);
        if ($error !== null) {
            return [null, $error];
        }
        if (!preg_match('/^(www\.|[a-z]{2}\.)?tripadvisor\.[a-z]{2,3}(\.[a-z]{2})?$/', $url['host'])) {
            return [null, 'wrong_domain'];
        }
        if (!str_starts_with($url['path'] ?? '', '/Restaurant_Review-')) {
            return [null, 'invalid'];
        }

        return [$this->buildUrl($url, 'https'), null];
    }

    /** Own website: any public http(s) URL; https added when no scheme is given, an explicit http:// is kept. */
    private function website(string $input): array
    {
        [$url, $error] = $this->parseUrl($input, null);
        if ($error !== null) {
            return [null, $error];
        }

        return [$this->buildUrl($url, $url['scheme']), null];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** Typed as a URL rather than a bare username: has a scheme, a slash, "www." or the network's domain. */
    private function looksLikeUrl(string $input, string $domain): bool
    {
        return str_contains($input, '://')
            || str_contains($input, '/')
            || str_starts_with(strtolower($input), 'www.')
            || str_contains(strtolower($input), $domain);
    }

    /**
     * Parses an http(s) URL (https:// added when there's no scheme). With
     * $domains, the host must be one of them or a subdomain of one
     * (dot-boundary match, so "instagram.com.evil.com" fails).
     *
     * @param string[]|null $domains
     *
     * @return array{0: ?array<string, string>, 1: ?string}
     */
    private function parseUrl(string $input, ?array $domains): array
    {
        if (preg_match('/^[a-z][a-z0-9+.\-]*:/i', $input) && !preg_match('#^https?://#i', $input)) {
            return [null, 'invalid'];
        }
        if (!preg_match('#^https?://#i', $input)) {
            $input = 'https://' . $input;
        }

        $url = parse_url($input);
        if ($url === false || empty($url['host']) || isset($url['user']) || isset($url['pass'])) {
            return [null, 'invalid'];
        }
        $url['scheme'] = strtolower($url['scheme']);
        $url['host'] = strtolower($url['host']);
        $host = $url['host'];

        if ($host === 'localhost' || !str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) || str_starts_with($host, '[')
            || !preg_match('/^[a-z0-9.\-]+$/', $host)) {
            return [null, 'invalid'];
        }

        if ($domains !== null) {
            $matches = false;
            foreach ($domains as $domain) {
                if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                    $matches = true;
                    break;
                }
            }
            if (!$matches) {
                return [null, 'wrong_domain'];
            }
        }

        return [$url, null];
    }

    /** @param array<string, string> $url */
    private function buildUrl(array $url, string $scheme): string
    {
        return $scheme . '://' . $url['host']
            . (isset($url['port']) ? ':' . $url['port'] : '')
            . ($url['path'] ?? '')
            . (isset($url['query']) ? '?' . $url['query'] : '')
            . (isset($url['fragment']) ? '#' . $url['fragment'] : '');
    }

    /**
     * @param array<string, string> $url
     *
     * @return string[]
     */
    private function pathSegments(array $url): array
    {
        return array_values(array_filter(explode('/', $url['path'] ?? ''), fn (string $s) => $s !== ''));
    }

    private function isFacebookSlug(string $slug): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9.\-]{5,50}$/', $slug);
    }

    private function isGoogleHost(string $host): bool
    {
        return (bool) preg_match('/(^|\.)(google\.[a-z.]+|goo\.gl|g\.page)$/', $host);
    }

    private function isTime(string $value): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value);
    }

    private function minutes(string $time): int
    {
        [$h, $m] = explode(':', $time);

        return (int) $h * 60 + (int) $m;
    }

    /**
     * Two shifts of the same day overlap. A close earlier than its open runs
     * past midnight, i.e. ends at close + 24h.
     *
     * @param array{0: string, 1: string} $a
     * @param array{0: string, 1: string} $b
     */
    private function overlaps(array $a, array $b): bool
    {
        [$aStart, $aEnd] = $this->interval($a);
        [$bStart, $bEnd] = $this->interval($b);

        return $aStart < $bEnd && $bStart < $aEnd;
    }

    /**
     * @param array{0: string, 1: string} $shift
     *
     * @return array{0: int, 1: int}
     */
    private function interval(array $shift): array
    {
        $start = $this->minutes($shift[0]);
        $end = $this->minutes($shift[1]);

        return [$start, $end <= $start ? $end + 1440 : $end];
    }
}
