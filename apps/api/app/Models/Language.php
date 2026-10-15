<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Language extends Model
{
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'code', 'name_native', 'name_en', 'flag_emoji',
        'is_active', 'is_ui_supported', 'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_ui_supported' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeActive($q)
    {
        return $q->where('is_active', true)->orderBy('sort_order');
    }
}
