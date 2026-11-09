<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Area extends Model
{
    protected $fillable = ['slug', 'name_en', 'name_vi', 'aliases'];

    protected function casts(): array
    {
        return ['aliases' => 'array'];
    }
}
