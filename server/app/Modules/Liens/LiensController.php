<?php

namespace App\Modules\Liens;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LiensController extends Controller
{
    public function __construct(protected LinkService $s) {}

    protected function guard(callable $fn)
    {
        try {
            return $fn();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function overview()
    {
        $c = app('tenant');

        return [
            'platforms' => collect(LinkService::PLATFORMS)->map(fn ($p, $k) => ['key' => $k, 'label' => $p[0], 'color' => $p[1]])->values(),
            'qr_types' => QrRenderer::TYPES, 'base_url' => LinkService::shortDomain() ? 'https://'.LinkService::shortDomain().'/' : url('/l/'), 'short_domain' => LinkService::shortDomain(),
            'totals' => ['links' => DB::table('lk_links')->where('company_id', $c->id)->count(), 'qrs' => DB::table('lk_qrs')->where('company_id', $c->id)->count(),
                'clicks' => (int) DB::table('lk_links')->where('company_id', $c->id)->sum('clicks_count'), 'uniques' => (int) DB::table('lk_links')->where('company_id', $c->id)->sum('unique_count')],
        ];
    }

    // ------------------------------------------------------------------ liens

    public function index(Request $r)
    {
        $q = DB::table('lk_links')->where('company_id', app('tenant')->id)
            ->when($r->query('kind'), fn ($w, $k) => $w->where('kind', $k))
            ->when($r->query('q'), fn ($w, $t) => $w->where(fn ($x) => $x->where('title', 'like', "%$t%")->orWhere('slug', 'like', "%$t%")->orWhere('target_url', 'like', "%$t%")))
            ->orderByDesc('id')->limit(500);

        return ['data' => $q->get()->map(fn ($l) => $this->s->row($l))];
    }

    protected function rules(): array
    {
        return ['kind' => 'nullable|in:short,smart,page', 'title' => 'nullable|string|max:190', 'target_url' => 'nullable|string|max:2000', 'slug' => 'nullable|string|max:60',
            'rules' => 'nullable|array', 'page' => 'nullable|array', 'page.buttons' => 'nullable|array|max:30', 'utm' => 'nullable|array', 'password' => 'nullable|string|max:120',
            'cloak' => 'nullable|boolean', 'expires_at' => 'nullable|date', 'max_clicks' => 'nullable|integer|min:1', 'tags' => 'nullable|array', 'active' => 'nullable|boolean'];
    }

    public function store(Request $r)
    {
        $d = $r->validate($this->rules());

        return $this->guard(fn () => response()->json($this->s->save(app('tenant'), $r->user(), $d), 201));
    }

    public function update(Request $r, int $id)
    {
        $d = $r->validate($this->rules());

        return $this->guard(fn () => $this->s->save(app('tenant'), $r->user(), $d, $id));
    }

    public function destroy(int $id)
    {
        DB::table('lk_links')->where('company_id', app('tenant')->id)->where('id', $id)->delete();

        return ['ok' => true];
    }

    public function stats(Request $r)
    {
        $id = $r->query('link') ? (int) $r->query('link') : null;
        abort_if($id && ! DB::table('lk_links')->where('company_id', app('tenant')->id)->where('id', $id)->exists(), 404);

        return $this->s->stats(app('tenant'), $id, max(7, min(365, (int) $r->query('days', 30))));
    }

    /** Titre et image d'une page (aperçu au moment de créer un lien). */
    public function preview(Request $r)
    {
        $url = (string) $r->validate(['url' => 'required|string|max:2000'])['url'];
        if (! preg_match('~^https?://~i', $url)) {
            $url = 'https://'.$url;
        }
        try {
            $pin = \App\Support\UrlGuard::pinned($url);
        } catch (\DomainException) {
            return ['title' => null, 'image' => null, 'platform' => LinkService::guessPlatform($url)];
        }
        $html = rescue(fn () => \Illuminate\Support\Facades\Http::timeout(6)->withOptions($pin)->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; YomaraLinks/1.0)'])->get($url)->body(), '', false);
        $meta = fn ($p) => preg_match('~<meta[^>]+(?:property|name)=["\']'.$p.'["\'][^>]+content=["\']([^"\']+)~i', $html, $m) ? html_entity_decode($m[1]) : null;
        $title = $meta('og:title') ?? (preg_match('~<title[^>]*>([^<]{1,200})~i', $html, $m) ? html_entity_decode(trim($m[1])) : null);

        return ['title' => $title ? mb_substr($title, 0, 190) : null, 'image' => $meta('og:image'), 'platform' => LinkService::guessPlatform($url)];
    }

    // ------------------------------------------------------------------ QR codes

    protected function qrRow(object $q): array
    {
        $link = $q->link_id ? DB::table('lk_links')->find($q->link_id) : null;

        return ['id' => $q->id, 'name' => $q->name, 'type' => $q->type, 'type_label' => QrRenderer::TYPES[$q->type] ?? $q->type, 'payload' => json_decode($q->payload, true),
            'data' => $q->data, 'style' => json_decode($q->style ?? '{}', true) ?: (object) [], 'dynamic' => (bool) $link, 'link' => $link ? $this->s->row($link) : null,
            'scans' => $link ? (int) DB::table('lk_clicks')->where('link_id', $link->id)->where('source', 'qr')->whereNull('button')->count() : null, 'created_at' => $q->created_at];
    }

    public function qrs()
    {
        return ['data' => DB::table('lk_qrs')->where('company_id', app('tenant')->id)->orderByDesc('id')->limit(500)->get()->map(fn ($q) => $this->qrRow($q))];
    }

    protected function qrInput(Request $r): array
    {
        return $r->validate(['name' => 'nullable|string|max:190', 'type' => 'required|string|in:'.implode(',', array_keys(QrRenderer::TYPES)), 'payload' => 'required|array',
            'style' => 'nullable|array', 'style.fg' => 'nullable|string|max:7', 'style.bg' => 'nullable|string|max:7', 'style.eye' => 'nullable|string|max:7',
            'style.dots' => 'nullable|in:square,dots,rounded', 'style.logo' => 'nullable|boolean', 'dynamic' => 'nullable|boolean']);
    }

    /** Logo de la société (pour le centre du QR code), s'il existe. */
    protected function logo(): ?string
    {
        $logo = app('tenant')->logo;
        if (! $logo) {
            return null;
        }
        $path = str_starts_with($logo, 'http') ? public_path(ltrim((string) parse_url($logo, PHP_URL_PATH), '/')) : public_path(ltrim($logo, '/'));

        return is_file($path) ? $path : null;
    }

    /** Aperçu immédiat (PNG en base 64) sans enregistrer. */
    public function render(Request $r)
    {
        $d = $this->qrInput($r);

        return $this->guard(function () use ($d) {
            $data = QrRenderer::encode($d['type'], $d['payload']);
            $logo = ! empty($d['style']['logo']) ? $this->logo() : null;

            return ['data' => $data, 'png' => 'data:image/png;base64,'.base64_encode(QrRenderer::png($data, $d['style'] ?? [], 520, $logo))];
        });
    }

    public function saveQr(Request $r, ?int $id = null)
    {
        $d = $this->qrInput($r);

        return $this->guard(function () use ($d, $r, $id) {
            $c = app('tenant');
            $row = $id ? (DB::table('lk_qrs')->where('company_id', $c->id)->where('id', $id)->first() ?: abort(404)) : null;
            $data = QrRenderer::encode($d['type'], $d['payload']);
            $linkId = $row?->link_id;
            // QR dynamique (site, paiement) : il pointe vers un lien court suivi, dont on peut changer la destination sans réimprimer
            if (! empty($d['dynamic']) && in_array($d['type'], ['url', 'payment'], true)) {
                $link = $this->s->save($c, $r->user(), ['kind' => 'short', 'title' => $d['name'] ?? 'QR code', 'target_url' => $data], $linkId);
                $linkId = $link['id'];
                $data = LinkService::publicUrl($link['slug']).'?q=1';
            } elseif ($linkId && empty($d['dynamic'])) {
                $linkId = null;
            }
            $set = ['name' => mb_substr(trim((string) ($d['name'] ?? '')), 0, 190) ?: (QrRenderer::TYPES[$d['type']].' '.now()->format('d/m H:i')), 'type' => $d['type'],
                'payload' => json_encode($d['payload']), 'data' => $data, 'style' => json_encode($d['style'] ?? []), 'link_id' => $linkId, 'updated_at' => now()];
            if ($row) {
                DB::table('lk_qrs')->where('id', $row->id)->update($set);
                $id = $row->id;
            } else {
                $id = DB::table('lk_qrs')->insertGetId($set + ['company_id' => $c->id, 'user_id' => $r->user()->id, 'created_at' => now()]);
            }

            return response()->json($this->qrRow(DB::table('lk_qrs')->find($id)), $row ? 200 : 201);
        });
    }

    public function updateQr(Request $r, int $id)
    {
        return $this->saveQr($r, $id);
    }

    public function deleteQr(int $id)
    {
        DB::table('lk_qrs')->where('company_id', app('tenant')->id)->where('id', $id)->delete();

        return ['ok' => true];
    }

    /** Fichier du QR code : PNG (taille au choix) ou SVG (impression, sans perte). */
    public function downloadQr(Request $r, int $id, string $format)
    {
        $q = DB::table('lk_qrs')->where('company_id', app('tenant')->id)->where('id', $id)->first() ?? abort(404);
        $style = json_decode($q->style ?? '{}', true) ?: [];
        $logo = ! empty($style['logo']) ? $this->logo() : null;
        $name = 'qr-'.\Illuminate\Support\Str::slug($q->name ?: 'code');
        if ($format === 'svg') {
            $uri = $logo ? 'data:'.(mime_content_type($logo) ?: 'image/png').';base64,'.base64_encode((string) file_get_contents($logo)) : null;

            return response(QrRenderer::svg($q->data, $style, $uri))->header('Content-Type', 'image/svg+xml')->header('Content-Disposition', 'attachment; filename="'.$name.'.svg"');
        }

        return response(QrRenderer::png($q->data, $style, min(3000, max(200, (int) $r->query('size', 1200))), $logo))->header('Content-Type', 'image/png')
            ->header('Content-Disposition', 'attachment; filename="'.$name.'.png"');
    }
}
