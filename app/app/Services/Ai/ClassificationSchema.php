<?php

namespace App\Services\Ai;

use App\Enums\LeadIntent;
use App\Enums\LeadStage;
use App\Enums\PropertyType;

/**
 * Tool definition for `record_classification` (SPEC 9.3), adapted for strict
 * tool use: every object has additionalProperties=false, every property is
 * required (nullable instead of optional), and numeric/length limits are
 * enforced in PHP (LeadClassifier) because strict schemas do not support them.
 */
final class ClassificationSchema
{
    public const TOOL_NAME = 'record_classification';

    /** @return array<string, mixed> */
    public static function tool(): array
    {
        $nullableString = ['type' => ['string', 'null']];
        $nullableNumber = ['type' => ['number', 'null']];
        $nullableInteger = ['type' => ['integer', 'null']];

        return [
            'name' => self::TOOL_NAME,
            'description' => 'Record the classification and extracted rental details of one message.',
            'input_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => [
                    'intent', 'confidence', 'language', 'stage', 'arrival_date', 'duration_months',
                    'property_type', 'bedrooms_min', 'areas_mentioned', 'budget', 'household',
                    'requirements', 'summary_vi',
                ],
                'properties' => [
                    'intent' => ['type' => 'string', 'enum' => LeadIntent::values()],
                    'confidence' => ['type' => 'number', 'description' => 'Between 0 and 1.'],
                    'language' => ['type' => 'string', 'description' => 'ISO 639-1 code of the message language.'],
                    'stage' => ['type' => 'string', 'enum' => LeadStage::values()],
                    'arrival_date' => $nullableString + ['description' => 'YYYY-MM-DD'],
                    'duration_months' => $nullableInteger,
                    'property_type' => ['type' => 'string', 'enum' => PropertyType::values()],
                    'bedrooms_min' => $nullableInteger,
                    'areas_mentioned' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'budget' => [
                        'anyOf' => [
                            [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => ['min', 'max', 'currency', 'period', 'raw'],
                                'properties' => [
                                    'min' => $nullableNumber,
                                    'max' => $nullableNumber,
                                    'currency' => ['type' => 'string', 'enum' => ['USD', 'VND', 'RUB', 'EUR', 'KRW', 'AUD', 'GBP', 'OTHER']],
                                    'period' => ['type' => 'string', 'enum' => ['month', 'total', 'unknown']],
                                    'raw' => ['type' => 'string'],
                                ],
                            ],
                            ['type' => 'null'],
                        ],
                    ],
                    'household' => $nullableString,
                    'requirements' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'summary_vi' => ['type' => 'string', 'description' => 'One Vietnamese sentence, max 200 characters.'],
                ],
            ],
        ];
    }
}
