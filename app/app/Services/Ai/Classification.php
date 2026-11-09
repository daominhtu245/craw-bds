<?php

namespace App\Services\Ai;

use App\Enums\LeadIntent;
use App\Enums\LeadStage;
use App\Enums\PropertyType;
use Illuminate\Support\Carbon;

/**
 * Validated, clamped view of the `record_classification` tool input.
 * Invalid or out-of-range values become null / defaults instead of failing.
 */
final class Classification
{
    /**
     * @param  list<string>  $areasMentioned
     * @param  list<string>  $requirements
     * @param  array{min: ?float, max: ?float, currency: string, period: string, raw: string}|null  $budget
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly LeadIntent $intent,
        public readonly float $confidence,
        public readonly string $language,
        public readonly LeadStage $stage,
        public readonly ?string $arrivalDate,
        public readonly ?int $durationMonths,
        public readonly PropertyType $propertyType,
        public readonly ?int $bedroomsMin,
        public readonly array $areasMentioned,
        public readonly ?array $budget,
        public readonly ?string $household,
        public readonly array $requirements,
        public readonly string $summaryVi,
        public readonly array $raw,
    ) {}

    /** @param array<string, mixed> $input */
    public static function fromToolInput(array $input): self
    {
        return new self(
            intent: LeadIntent::tryFrom((string) ($input['intent'] ?? '')) ?? LeadIntent::Other,
            confidence: max(0.0, min(1.0, (float) ($input['confidence'] ?? 0))),
            language: mb_substr(mb_strtolower(trim((string) ($input['language'] ?? 'und'))), 0, 8) ?: 'und',
            stage: LeadStage::tryFrom((string) ($input['stage'] ?? '')) ?? LeadStage::Unknown,
            arrivalDate: self::date($input['arrival_date'] ?? null),
            durationMonths: self::intBetween($input['duration_months'] ?? null, 1, 120),
            propertyType: PropertyType::tryFrom((string) ($input['property_type'] ?? '')) ?? PropertyType::Unknown,
            bedroomsMin: self::intBetween($input['bedrooms_min'] ?? null, 0, 20),
            areasMentioned: self::strings($input['areas_mentioned'] ?? []),
            budget: self::budget($input['budget'] ?? null),
            household: self::text($input['household'] ?? null, 255),
            requirements: self::strings($input['requirements'] ?? []),
            summaryVi: self::text($input['summary_vi'] ?? '', 500) ?? '',
            raw: $input,
        );
    }

    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('!Y-m-d', $value)->toDateString() === $value ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function intBetween(mixed $value, int $min, int $max): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) round((float) $value);

        return $int >= $min && $int <= $max ? $int : null;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($v) => is_scalar($v) ? trim((string) $v) : '',
            $value,
        ))));
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $max);
    }

    /** @return array{min: ?float, max: ?float, currency: string, period: string, raw: string}|null */
    private static function budget(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $min = is_numeric($value['min'] ?? null) ? (float) $value['min'] : null;
        $max = is_numeric($value['max'] ?? null) ? (float) $value['max'] : null;

        if ($min === null && $max === null) {
            return null;
        }

        return [
            'min' => $min,
            'max' => $max,
            'currency' => strtoupper((string) ($value['currency'] ?? 'OTHER')),
            'period' => in_array($value['period'] ?? null, ['month', 'total', 'unknown'], true) ? $value['period'] : 'unknown',
            'raw' => mb_substr((string) ($value['raw'] ?? ''), 0, 100),
        ];
    }
}
