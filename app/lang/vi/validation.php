<?php

// Only the rules used by the public inquiry form; anything else falls back to English.
return [
    'accepted' => 'Vui lòng tick :attribute.',
    'after_or_equal' => ':attribute phải từ hôm nay trở đi.',
    'before' => ':attribute không hợp lệ.',
    'date' => ':attribute không phải ngày hợp lệ.',
    'in' => ':attribute không hợp lệ.',
    'integer' => ':attribute phải là số nguyên.',
    'numeric' => ':attribute phải là số.',
    'required' => 'Vui lòng nhập :attribute.',
    'required_unless' => 'Vui lòng nhập :attribute.',
    'string' => ':attribute không hợp lệ.',
    'max' => [
        'numeric' => ':attribute tối đa :max.',
        'string' => ':attribute tối đa :max ký tự.',
    ],
    'min' => [
        'numeric' => ':attribute tối thiểu :min.',
    ],
];
