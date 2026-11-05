<?php

namespace App\Modules\Marketing\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Formulaire d'inscription ou page de destination (avec ou sans formulaire). */
class Form extends Model
{
    use BelongsToCompany;

    protected $table = 'mk_forms';

    protected $fillable = ['kind', 'name', 'slug', 'list_id', 'title', 'description', 'fields', 'button_label', 'double_optin', 'consent_text', 'success_message',
        'redirect_url', 'blocks', 'style', 'with_form', 'active'];

    protected $appends = ['url'];

    public const FIELDS = ['first_name' => 'Prénom', 'last_name' => 'Nom', 'email' => 'E-mail', 'phone' => 'Téléphone / WhatsApp', 'company_name' => 'Société', 'city' => 'Ville', 'birthday' => 'Date d\'anniversaire'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'blocks' => 'array', 'style' => 'array', 'double_optin' => 'boolean', 'with_form' => 'boolean', 'active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $f) {
            $base = Str::slug($f->slug ?: $f->name) ?: 'page';
            $slug = $base;
            while (self::withoutGlobalScopes()->where('slug', $slug)->exists()) {
                $slug = $base.'-'.Str::lower(Str::random(4));
            }
            $f->slug = $slug;
            $f->fields ??= [['key' => 'first_name', 'required' => false], ['key' => 'email', 'required' => true]];
        });
    }

    public function getUrlAttribute(): string
    {
        return url('/f/'.$this->slug);
    }

    /** Champs demandés : [{key, required}] */
    public function fieldList(): array
    {
        return array_values(array_filter((array) $this->fields, fn ($f) => isset(self::FIELDS[$f['key'] ?? ''])));
    }
}
