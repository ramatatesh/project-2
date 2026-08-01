<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Holiday extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'company_id',
        'name',
        'holiday_type',
        'start_date',
        'end_date',
        'repeats_annually',
        'is_default',
    ];

    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'repeats_annually' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isSingleDay(): bool
    {
        return $this->holiday_type === 'single_day';
    }

    public function occursOn(Carbon|string $date): bool
    {
        $date = Carbon::parse($date);

        if ($this->repeats_annually) {
            $startMonthDay = $this->start_date->format('m-d');
            $endMonthDay = $this->end_date ? $this->end_date->format('m-d') : $startMonthDay;
            $currentMonthDay = $date->format('m-d');

            return $startMonthDay <= $currentMonthDay && $currentMonthDay <= $endMonthDay;
        }

        return $date->between($this->start_date, $this->end_date ?? $this->start_date);
    }
}
