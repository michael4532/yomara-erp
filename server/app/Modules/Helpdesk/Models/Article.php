<?php

namespace App\Modules\Helpdesk\Models;

use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Article de la base de connaissances (centre d'aide public). */
class Article extends Model
{
    use Syncable;

    protected $table = 'hd_articles';

    public static string $syncEntity = 'hd_articles';

    public static string $viewPermission = 'helpdesk.view|helpdesk.agent|helpdesk.manage';

    public static string $managePermission = 'helpdesk.manage';

    public static array $searchable = ['title', 'section', 'tags'];

    protected $fillable = ['title', 'section', 'body', 'published', 'tags'];

    protected $hidden = ['company_id', 'deleted_at'];

    protected function casts(): array
    {
        return ['published' => 'boolean', 'views' => 'integer', 'helpful_yes' => 'integer', 'helpful_no' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return ['title' => 'required|string|max:200', 'section' => 'nullable|string|max:80', 'body' => 'required|string|max:60000', 'published' => 'boolean', 'tags' => 'nullable|string|max:255'];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        if ($creating || isset($data['title'])) {
            $base = Str::limit(Str::slug($data['title'] ?? $this->title), 140, '') ?: 'article';
            $slug = $base;
            $i = 2;
            while (static::where('slug', $slug)->where('id', '!=', $this->id ?? '')->exists()) {
                $slug = $base.'-'.$i++;
            }
            $this->slug = $slug;
        }
        if ($creating) {
            $this->author_name = auth()->user()?->name;
        }
    }

    public function excerpt(int $n = 160): string
    {
        $t = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', strip_tags($this->body));
        $t = preg_replace(['/^#{1,3}\s+.*$/m', '/^\s*[-•]\s+/m', '/\*\*/'], ['', '', ''], $t);

        return Str::limit(trim(preg_replace('/\s+/', ' ', $t)), $n);
    }

    public function toSyncArray(): array
    {
        return $this->toArray() + ['slug' => $this->slug, 'views' => $this->views, 'helpful_yes' => $this->helpful_yes, 'helpful_no' => $this->helpful_no, 'author_name' => $this->author_name];
    }
}
