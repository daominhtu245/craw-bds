<?php

namespace App\Http\Requests;

use App\Models\Inquiry;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Inquiry form on the public site / Mini App. Bot checks: a hidden "company"
 * field must stay empty and the form must have been open a few seconds.
 */
class InquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'listing_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:100'],
            'contact_channel' => ['required', Rule::in([...Inquiry::CHANNELS, 'telegram_self'])],
            'contact' => ['required_unless:contact_channel,telegram_self', 'nullable', 'string', 'max:150'],
            'arrival_date' => ['nullable', 'date', 'after_or_equal:today', 'before:+2 years'],
            'duration_months' => ['nullable', 'integer', 'min:1', 'max:60'],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'message' => ['nullable', 'string', 'max:2000'],
            'consent' => ['accepted'],
            'ref' => ['nullable', 'string', 'max:32'],
            'tg_init_data' => ['nullable', 'string', 'max:4096'],
            'company' => ['nullable', 'max:0'],      // honeypot
            'started' => ['required', 'string'],     // encrypted timestamp from the form
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                try {
                    $started = (int) Crypt::decryptString((string) $this->input('started'));
                } catch (DecryptException) {
                    $validator->errors()->add('started', __('site.form_error_retry'));

                    return;
                }

                if (time() - $started < (int) config('leadradar.site.min_fill_seconds')) {
                    $validator->errors()->add('started', __('site.form_error_retry'));
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => __('site.form_name'),
            'contact' => __('site.form_contact'),
            'contact_channel' => __('site.form_channel'),
            'arrival_date' => __('site.form_arrival'),
            'duration_months' => __('site.form_duration'),
            'budget' => __('site.form_budget'),
            'message' => __('site.form_message'),
            'consent' => __('site.form_consent_short'),
        ];
    }
}
