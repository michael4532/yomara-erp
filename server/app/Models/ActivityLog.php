<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['company_id', 'user_id', 'action', 'subject_type', 'subject_id', 'properties', 'ip'];

    protected function casts(): array
    {
        return ['properties' => 'array'];
    }

    public static function record(string $action, $subject = null, array $properties = []): void
    {
        $user = auth()->user();
        static::create([
            'company_id' => app()->bound('tenant') ? app('tenant')->id : $user?->company_id,
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'properties' => $properties ?: null,
            'ip' => request()?->ip(),
        ]);
    }
}
