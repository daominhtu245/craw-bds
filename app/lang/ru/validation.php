<?php

// Only the rules used by the public inquiry form; anything else falls back to English.
return [
    'accepted' => 'Пожалуйста, отметьте «:attribute».',
    'after_or_equal' => 'Поле «:attribute» должно быть не раньше сегодняшнего дня.',
    'before' => 'Поле «:attribute» заполнено неверно.',
    'date' => 'Поле «:attribute» должно быть датой.',
    'in' => 'Поле «:attribute» заполнено неверно.',
    'integer' => 'Поле «:attribute» должно быть целым числом.',
    'numeric' => 'Поле «:attribute» должно быть числом.',
    'required' => 'Заполните поле «:attribute».',
    'required_unless' => 'Заполните поле «:attribute».',
    'string' => 'Поле «:attribute» заполнено неверно.',
    'max' => [
        'numeric' => 'Поле «:attribute» не больше :max.',
        'string' => 'Поле «:attribute» не длиннее :max символов.',
    ],
    'min' => [
        'numeric' => 'Поле «:attribute» не меньше :min.',
    ],
];
