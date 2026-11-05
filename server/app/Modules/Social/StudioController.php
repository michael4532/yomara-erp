<?php

namespace App\Modules\Social;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/** Charte de marque (couleurs, ton, coordonnées) et visuels générés à partir du catalogue, prêts à publier. */
class StudioController extends Controller
{
    public const TONES = ['chaleureux' => 'Chaleureux et proche', 'pro' => 'Professionnel', 'fun' => 'Drôle et décontracté', 'premium' => 'Haut de gamme', 'direct' => 'Direct et commercial'];

    public static function brandOf(Company $c): array
    {
        $b = (array) $c->setting('brand', []);
        $logo = $c->logo && (str_starts_with($c->logo, 'http') || str_starts_with($c->logo, 'data:image')) ? $c->logo : null;

        return [
            'primary' => $b['primary'] ?? '#1F6FEB', 'accent' => $b['accent'] ?? '#FFC531', 'dark' => $b['dark'] ?? '#0B1B3A',
            'slogan' => $b['slogan'] ?? null, 'tone' => $b['tone'] ?? 'chaleureux', 'hashtags' => $b['hashtags'] ?? null,
            'contact' => $b['contact'] ?? ($c->phone ? 'Tél. '.$c->phone : null), 'words_avoid' => $b['words_avoid'] ?? null,
            'logo' => $logo, 'currency' => $c->currency ?: 'XOF', 'name' => $c->name,
        ];
    }

    public function brand()
    {
        return ['data' => self::brandOf(app('tenant')), 'tones' => self::TONES];
    }

    public function saveBrand(Request $r)
    {
        $v = $r->validate(['primary' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'], 'accent' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'], 'dark' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'slogan' => 'nullable|string|max:150', 'tone' => 'nullable|in:'.implode(',', array_keys(self::TONES)), 'hashtags' => 'nullable|string|max:300', 'contact' => 'nullable|string|max:120',
            'words_avoid' => 'nullable|string|max:300'], ['primary.regex' => 'Couleur attendue au format #RRGGBB.', 'accent.regex' => 'Couleur attendue au format #RRGGBB.', 'dark.regex' => 'Couleur attendue au format #RRGGBB.']);
        $c = app('tenant');
        $s = (array) $c->settings;
        $s['brand'] = array_filter($v, fn ($x) => $x !== null && $x !== '');
        $c->settings = $s;
        $c->save();

        return $this->brand();
    }

    public function templates()
    {
        return ['templates' => collect(VisualRenderer::TEMPLATES)->map(fn ($t, $k) => ['key' => $k, 'label' => $t[0], 'hint' => $t[1]])->values(),
            'formats' => collect(VisualRenderer::FORMATS)->map(fn ($f, $k) => ['key' => $k, 'label' => $f[0], 'width' => $f[1], 'height' => $f[2]])->values()];
    }

    protected function input(Request $r): array
    {
        $d = $r->validate([
            'template' => 'required|in:'.implode(',', array_keys(VisualRenderer::TEMPLATES)), 'format' => 'nullable|in:'.implode(',', array_keys(VisualRenderer::FORMATS)),
            'product_id' => 'nullable|uuid', 'title' => 'nullable|string|max:200', 'subtitle' => 'nullable|string|max:400', 'price' => 'nullable|numeric|min:0', 'old_price' => 'nullable|numeric|min:0',
            'cta' => 'nullable|string|max:120', 'badge' => 'nullable|string|max:20', 'author' => 'nullable|string|max:80', 'stars' => 'nullable|integer|min:1|max:5',
            'media_id' => 'nullable|integer', 'image_url' => ['nullable', 'string', 'max:1000', 'regex:#^https?://#i'], 'items' => 'nullable|array|max:6', 'items.*.name' => 'nullable|string|max:80', 'items.*.price' => 'nullable|numeric',
            'product_ids' => 'nullable|array|max:6', 'product_ids.*' => 'uuid',
        ]);
        $cid = app('tenant')->id;
        if (! empty($d['product_id'])) {
            $p = DB::table('products')->where('company_id', $cid)->where('id', $d['product_id'])->whereNull('deleted_at')->first();
            abort_unless($p, 422, 'Article introuvable.');
            $d['title'] = ($d['title'] ?? '') ?: $p->name;
            $d['price'] = $d['price'] ?? (float) $p->sale_price;
            $d['image'] = $p->image ?: null;
        }
        if (! empty($d['product_ids'])) {
            $ps = DB::table('products')->where('company_id', $cid)->whereIn('id', $d['product_ids'])->whereNull('deleted_at')->get()->keyBy('id');
            $d['items'] = collect($d['product_ids'])->map(fn ($id) => isset($ps[$id]) ? ['name' => $ps[$id]->name, 'price' => (float) $ps[$id]->sale_price] : null)->filter()->values()->all();
        }
        if (! empty($d['media_id'])) {
            $m = DB::table('so_media')->where('company_id', $cid)->where('id', $d['media_id'])->where('kind', 'image')->first();
            abort_unless($m, 422, 'Image introuvable.');
            $d['image'] = $m->path;
        } elseif (! empty($d['image_url'])) {
            $d['image'] = $d['image_url'];
        }
        if (in_array($d['template'], ['promo', 'new', 'announce', 'quote'], true) && trim((string) ($d['title'] ?? '')) === '') {
            abort(422, $d['template'] === 'quote' ? 'Écrivez l\'avis du client.' : 'Choisissez un article ou écrivez un titre.');
        }

        return $d;
    }

    public function preview(Request $r)
    {
        $d = $this->input($r);
        $png = app(VisualRenderer::class)->render(self::brandOf(app('tenant')), $d, .5);

        return ['data' => ['image' => 'data:image/png;base64,'.base64_encode($png)]];
    }

    /** Crée le visuel en pleine taille et l'ajoute à la médiathèque, prêt à joindre à une publication. */
    public function generate(Request $r)
    {
        $d = $this->input($r);
        $c = app('tenant');
        $png = app(VisualRenderer::class)->render(self::brandOf($c), $d);
        $dir = 'uploads/social/'.$c->id;
        File::ensureDirectoryExists(public_path($dir));
        $name = 'visuel-'.Str::random(24).'.png';
        file_put_contents(public_path($dir.'/'.$name), $png);
        $size = @getimagesizefromstring($png) ?: [null, null];
        $id = DB::table('so_media')->insertGetId(['company_id' => $c->id, 'user_id' => $r->user()->id, 'kind' => 'image', 'path' => $dir.'/'.$name, 'mime' => 'image/png', 'size' => strlen($png),
            'width' => $size[0], 'height' => $size[1], 'original_name' => Str::limit(Str::slug(($d['title'] ?? '') ?: VisualRenderer::TEMPLATES[$d['template']][0]).'.png', 190, ''), 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => app(SocialService::class)->mediaRow(DB::table('so_media')->find($id))], 201);
    }
}
