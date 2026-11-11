<?php

namespace App\Services\Classify;

use App\Enums\PropertyType;
use App\Services\Text\TermMatcher;
use Carbon\CarbonInterface;

/**
 * Regex extraction of lead fields from EN/RU/VI text for the keyword classifier:
 * budget, duration, property type, arrival date, requirements, household.
 * Pure functions on lowercased text; nothing is guessed when no pattern matches.
 */
class FieldExtractor
{
    private const NUMBER_WORDS = [
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7,
        'eight' => 8, 'nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'a' => 1, 'an' => 1,
        'один' => 1, 'одна' => 1, 'два' => 2, 'две' => 2, 'три' => 3, 'четыре' => 4, 'пять' => 5, 'шесть' => 6,
    ];

    /** Month stems: English (full or 3-letter) and Russian. */
    private const MONTHS = [
        1 => ['january', 'jan', 'январ'], 2 => ['february', 'feb', 'феврал'], 3 => ['march', 'mar', 'март'],
        4 => ['april', 'apr', 'апрел'], 5 => ['may', 'ма[йяе]'], 6 => ['june', 'jun', 'июн'],
        7 => ['july', 'jul', 'июл'], 8 => ['august', 'aug', 'август'], 9 => ['september', 'sept', 'sep', 'сентябр'],
        10 => ['october', 'oct', 'октябр'], 11 => ['november', 'nov', 'ноябр'], 12 => ['december', 'dec', 'декабр'],
    ];

    /** Words before a month name that make "may"/"march"/"mar" unambiguous. */
    private const MONTH_CONTEXT = '(?:in|from|on|since|by|until|early|mid|late|end of|start of|beginning of|arriving|arrive|moving|с|в|до|начала|середины|конца|конце|начале)';

    public function lower(string $text): string
    {
        return mb_strtolower($text, 'UTF-8');
    }

    /** @return array{min: ?float, max: ?float, currency: string, period: string, raw: string}|null */
    public function budget(string $text): ?array
    {
        $t = $this->lower($text);
        $num = '(\d{1,3}(?:[ .,]\d{3})+|\d+(?:[.,]\d+)?)';
        $sep = '\s*(?:-|–|to|до)\s*';

        $patterns = [
            // 15-18 млн / 15tr / 16 triệu / 16m vnd / 8 củ
            ['/'.$num.'(?:'.$sep.$num.')?\s*(?:млн|mln|million|mil|triệu|trieu|tr\b|m\b|củ(?![\p{L}]))\s*(?:vnd|vnđ|đ|dong|донг\w*)?/u', 'VND', 1_000_000],
            ['/'.$num.'(?:'.$sep.$num.')?\s*(?:vnd|vnđ|đồng|dong|донг\w*|đ\b)/u', 'VND', 1],
            // $450, $400-500, 450$, 450 usd, 1.2k$
            ['/\$\s?'.$num.'(k)?(?:'.$sep.'\$?\s?'.$num.'(k)?)?/u', 'USD', 1, true],
            ['/'.$num.'(k)?(?:'.$sep.$num.'(k)?)?\s*(?:\$|usd|us dollars?|dollars?|bucks|долл\w*|бакс\w*)/u', 'USD', 1, false, true],
            ['/'.$num.'(?:'.$sep.$num.')?\s*(?:€|eur\b|euros?)/u', 'EUR', 1],
            ['/'.$num.'(?:'.$sep.$num.')?\s*(?:руб\w*|₽|rub\b)/u', 'RUB', 1],
            // "budget 450", "up to 500", "бюджет 500"
            ['/(?:budget|бюджет|up to|max(?:imum)?|не более)\s*(?:is|of|около|до|:)?\s*~?\s*'.$num.'(?:'.$sep.$num.')?(?![\d%])/u', 'USD', 1],
        ];

        foreach ($patterns as $p) {
            [$regex, $currency, $multiplier] = $p;
            if (! preg_match($regex, $t, $m)) {
                continue;
            }

            [$a, $b] = $this->budgetNumbers($m, $p[3] ?? false, $p[4] ?? false);
            if ($a === null) {
                continue;
            }

            $a *= $multiplier;
            $b = $b !== null ? $b * $multiplier : null;

            // Plain "budget 450" only makes sense as a monthly USD figure.
            if ($regex === end($patterns)[0] && ($a < 100 || $a > 20000)) {
                return null;
            }

            [$min, $max] = $b !== null ? [min($a, $b), max($a, $b)] : $this->singleBound($t, $m[0], $a);

            return [
                'min' => $min,
                'max' => $max,
                'currency' => $currency,
                'period' => preg_match('/\b(total|in total|for the whole|all together)\b|за вс[её]|всего/u', $t) ? 'total' : 'month',
                'raw' => mb_substr(trim($m[0]), 0, 100),
            ];
        }

        return null;
    }

    public function durationMonths(string $text): ?int
    {
        $t = $this->lower($text);
        $n = '(?<![\p{L}\d])(\d+|'.implode('|', array_keys(self::NUMBER_WORDS)).')';

        if (preg_match('/'.$n.'(?:\s*(?:-|–|to)\s*\d+)?\s*(?:months?|mos?(?![\p{L}])|мес\p{L}*|tháng)/u', $t, $m)) {
            return $this->toInt($m[1]);
        }
        if (preg_match('/'.$n.'\s*(?:years?|yrs?(?![\p{L}])|год\p{L}*|лет(?![\p{L}])|năm(?![\p{L}])|nam(?![\p{L}]))/u', $t, $m)) {
            return $this->toInt($m[1]) * 12;
        }
        if (preg_match('/half a year|полгода|пол года|nửa năm|nua nam/u', $t)) {
            return 6;
        }
        if (preg_match('/(?:for|на)\s+(?:a\s+|one\s+)?(?:year|год)\b/u', $t)) {
            return 12;
        }

        return null;
    }

    /** "5 nights", "2 weeks", "10 дней": stays shorter than a month. */
    public function mentionsShortStay(string $text): bool
    {
        $t = $this->lower($text);

        return preg_match('/\b(\d+|a|one|two|three|few|couple of)\s*(nights?|days?|weeks?)\b|\d+\s*(ноч\w*|дн\w*|недел\w*)|на недел|(?<![\p{L}])(\d+|vài|vai|mấy|may)\s*(đêm|ngày|tuần|dem|ngay|tuan)(?![\p{L}])/u', $t) === 1
            && $this->durationMonths($t) === null;
    }

    /** @return array{0: PropertyType, 1: ?int} */
    public function propertyType(string $text): array
    {
        $t = $this->lower($text);
        $n = '(?<![\p{L}\d])(\d|'.implode('|', array_keys(self::NUMBER_WORDS)).')';
        $bedrooms = null;

        if (preg_match('/'.$n.'\s*-?\s*(?:br(?![\p{L}])|bed(?:room)?s?(?![\p{L}])|bdr|bhk|спальн\p{L}*|pn(?![\p{L}])|phòng ngủ)/u', $t, $m)) {
            $bedrooms = $this->toInt($m[1]);
        } elseif (preg_match('/однушк|двушк|трешк|трёшк/u', $t, $m)) {
            $bedrooms = ['однушк' => 1, 'двушк' => 2][$m[0]] ?? 3;
        }

        $type = match (true) {
            (bool) preg_match('/\bvillas?\b|вилл/u', $t) => PropertyType::Villa,
            (bool) preg_match('/\b(house|townhouse)\b|(?<![\p{L}])(дом|домик)(?![\p{L}])|nhà nguyên căn|nha nguyen can|nhà phố|nha pho/u', $t) => PropertyType::House,
            (bool) preg_match('/\bstudio\b|студи/u', $t) => PropertyType::Studio,
            $bedrooms !== null && $bedrooms >= 3 => PropertyType::ThreeBrPlus,
            $bedrooms === 2 => PropertyType::TwoBr,
            $bedrooms === 1 => PropertyType::OneBr,
            (bool) preg_match('/\broom\b|(?<![\p{L}])комнат(?:у|а|ы)?(?![\p{L}])|phòng trọ|phong tro/u', $t) => PropertyType::Room,
            default => PropertyType::Unknown,
        };

        if ($type === PropertyType::Studio) {
            $bedrooms = 0;
        }

        return [$type, $bedrooms];
    }

    /** Next occurrence of the first month mentioned, relative to posted_at (day 1 when no day given). */
    public function arrivalDate(string $text, CarbonInterface $postedAt): ?string
    {
        $t = $this->lower($text);

        if (preg_match('/next month|в следующем месяце|tháng sau|thang sau|tháng tới|thang toi/u', $t)) {
            return $postedAt->copy()->addMonthNoOverflow()->startOfMonth()->toDateString();
        }

        if (($vi = $this->vietnameseArrival($t, $postedAt)) !== null) {
            return $vi;
        }

        $best = null;
        foreach (self::MONTHS as $month => $stems) {
            foreach ($stems as $stem) {
                $ambiguous = in_array($stem, ['may', 'march', 'mar'], true);
                $cyrillic = preg_match('/\p{Cyrillic}/u', $stem) === 1;
                // Russian stems take case endings ("декабря"); English words must match whole ("dec" ≠ "decide").
                $word = '(?<![\p{L}])'.$stem.($cyrillic && $stem !== 'ма[йяе]' ? '\p{L}*' : '\.?(?![\p{L}])');
                $regex = $ambiguous
                    ? '/(?:'.self::MONTH_CONTEXT.'\s+(?:the\s+)?(?:\d{1,2}(?:st|nd|rd|th)?\s+(?:of\s+)?)?'.$word.'|'.$word.'\s+\d{1,2}(?!\d))/u'
                    : '/'.$word.'/u';

                if (preg_match($regex, $t, $m, PREG_OFFSET_CAPTURE) && ($best === null || $m[0][1] < $best[1])) {
                    $best = [$month, $m[0][1], $m[0][0]];
                }
            }
        }

        if ($best === null) {
            return null;
        }

        [$month, $offset, $matched] = $best;
        $before = substr($t, max(0, $offset - 14), min($offset, 14));
        $after = substr($t, $offset + strlen($matched), 16);
        $notUnit = '(?!\s*(?:months?|weeks?|days?|nights?|years?|мес|нед|дн|ноч|год|\$|usd|k\b))';
        $day = match (true) {
            // Ambiguous months carry the day inside the match: "from 15 may", "may 15".
            (bool) preg_match('/(?<!\d)([0-3]?\d)(?:st|nd|rd|th)?\s+(?:of\s+)?\p{L}+\.?$/u', $matched, $d) => (int) $d[1],
            (bool) preg_match('/\p{L}\.?\s+([0-3]?\d)$/u', $matched, $d) => (int) $d[1],
            (bool) preg_match('/(?<!\d)([0-3]?\d)(?:st|nd|rd|th|-?го)?\s+(?:of\s+)?$/u', $before, $d) => (int) $d[1],
            (bool) preg_match('/^\.?,?\s*([0-3]?\d)(?:st|nd|rd|th)?(?!\d)'.$notUnit.'/u', $after, $d) => (int) $d[1],
            default => 1,
        };
        if ($day < 1 || $day > 31) {
            $day = 1;
        }

        $local = $postedAt->copy()->setTimezone(config('leadradar.display_timezone'));
        $year = $local->year + ($month < $local->month ? 1 : 0);
        $day = min($day, cal_days_in_month(CAL_GREGORIAN, $month, $year));

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * "tháng 12", "đầu tháng 1", "ngày 15 tháng 12", "ngày 15/12". "6 tháng" is a
     * duration (number before the word), so only "tháng N" counts.
     */
    private function vietnameseArrival(string $t, CarbonInterface $postedAt): ?string
    {
        $month = null;
        $day = 1;

        if (preg_match('/(?<![\p{L}])(?:ngày|ngay)\s*([0-3]?\d)\s*\/\s*(1[0-2]|0?[1-9])(?!\d)/u', $t, $m)) {
            [$day, $month] = [(int) $m[1], (int) $m[2]];
        } elseif (preg_match('/(?:(?<![\p{L}])(?:ngày|ngay)\s*([0-3]?\d)\s+)?(?<![\p{L}\d])(?:tháng|thang)\s*(1[0-2]|0?[1-9])(?!\d)(?!\s*(?:năm|nam)\b)/u', $t, $m)) {
            [$day, $month] = [($m[1] ?? '') !== '' ? (int) $m[1] : 1, (int) $m[2]];
        }

        if ($month === null) {
            return null;
        }

        $local = $postedAt->copy()->setTimezone(config('leadradar.display_timezone'));
        $year = $local->year + ($month < $local->month ? 1 : 0);
        $day = max(1, min($day, cal_days_in_month(CAL_GREGORIAN, $month, $year)));

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /** @return list<string> */
    public function requirements(string $text): array
    {
        $t = $this->lower($text);
        $tags = [];

        foreach ((array) config('leadradar.classifier.requirement_terms') as $tag => $terms) {
            foreach ((array) $terms as $term) {
                if (TermMatcher::contains($t, mb_strtolower($term, 'UTF-8'))) {
                    $tags[] = $tag;
                    break;
                }
            }
        }

        return $tags;
    }

    public function household(string $text): ?string
    {
        $t = $this->lower($text);
        $parts = [];

        if (preg_match('/\b(kids?|children|child|son|daughter)\b|дет|ребен|ребён|сын|доч/u', $t)) {
            $parts[] = 'family with kids';
        } elseif (preg_match('/\b(family)\b|семь/u', $t)) {
            $parts[] = 'family';
        } elseif (preg_match('/\b(couple|partner|wife|husband|girlfriend|boyfriend|my fiance)\b|(?<![\p{L}])пара(?![\p{L}])|жен|муж/u', $t)) {
            $parts[] = 'couple';
        }

        if (preg_match('/\b(cat|cats|dog|dogs|pet|pets|kitten|puppy)\b|кошк|(?<![\p{L}])кот(?![\p{L}])|собак|питом/u', $t)) {
            $parts[] = 'with pets';
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    private function toInt(string $value): int
    {
        return is_numeric($value) ? (int) $value : (self::NUMBER_WORDS[$value] ?? 1);
    }

    /**
     * @param  array<int, string>  $m
     * @return array{0: ?float, 1: ?float}
     */
    private function budgetNumbers(array $m, bool $dollarFirst, bool $kGroups): array
    {
        $numbers = [];
        $k = [];
        if ($dollarFirst || $kGroups) {
            // groups: 1 = num, 2 = k, 3 = num, 4 = k
            $numbers = [$m[1] ?? null, $m[3] ?? null];
            $k = [! empty($m[2] ?? ''), ! empty($m[4] ?? '')];
        } else {
            $numbers = [$m[1] ?? null, $m[2] ?? null];
            $k = [false, false];
        }

        $out = [];
        foreach ($numbers as $i => $raw) {
            $out[] = ($raw === null || $raw === '') ? null : $this->parseNumber($raw) * ($k[$i] ? 1000 : 1);
        }

        return [$out[0], $out[1]];
    }

    private function parseNumber(string $raw): float
    {
        $raw = trim($raw);
        // "16.000.000" / "1,200" / "16 000 000": thousands separators.
        if (preg_match('/^\d{1,3}([ .,]\d{3})+$/', $raw)) {
            return (float) preg_replace('/[ .,]/', '', $raw);
        }

        return (float) str_replace(',', '.', $raw);
    }

    /** @return array{0: ?float, 1: ?float} */
    private function singleBound(string $text, string $match, float $value): array
    {
        $pos = mb_strpos($text, $match);
        $before = $pos === false ? '' : mb_substr($text, max(0, $pos - 15), min($pos, 15));

        if (preg_match('/(from|at least|minimum|min|от)\s*$/u', $before)) {
            return [$value, null];
        }

        return [null, $value];
    }
}
