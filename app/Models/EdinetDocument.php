<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EdinetDocument extends Model
{
    protected $fillable = [
        'doc_date',
        'doc_id',
        'edinet_code',
        'jcn',
        'period_end',
        'filer_name',
    ];

    protected function casts(): array
    {
        return [
            'doc_date' => 'date',
        ];
    }
}
