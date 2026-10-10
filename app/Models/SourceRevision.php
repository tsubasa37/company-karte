<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourceRevision extends Model
{
    protected $fillable = [
        'source_document_id',
        'raw_text',
        'replaced_at',
    ];

    protected function casts(): array
    {
        return [
            'replaced_at' => 'datetime',
        ];
    }
}
