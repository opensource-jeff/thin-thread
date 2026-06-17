<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Leak extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'display_name',
        'file_path',
        'leak_date',
        'data_format',
        'retention_policy',
        'retention_label',
        'retention_expires_at',
        'ingested_at',
        'total_lines',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'leak_date' => 'date',
            'retention_expires_at' => 'datetime',
            'ingested_at' => 'datetime',
            'total_lines' => 'integer',
        ];
    }
}
