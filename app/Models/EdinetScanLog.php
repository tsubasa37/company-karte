<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EdinetScanLog extends Model
{
    protected $fillable = [
        'scan_date',
    ];

    protected function casts(): array
    {
        return [
            'scan_date' => 'date',
        ];
    }
}
