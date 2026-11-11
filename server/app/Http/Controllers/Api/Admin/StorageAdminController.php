<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\StorageBackend;
use App\Storage\StoragePool;
use Illuminate\Http\Request;

/** Super admin : stockages des documents (serveur, R2, B2, Wasabi…), priorités et bascule automatique. */
class StorageAdminController extends Controller
{
    /** Fournisseurs compatibles S3 : adresse type et région par défaut (« {compte} », « {region} » à compléter). */
    public const PROVIDERS = [
        'r2' => ['label' => 'Cloudflare R2', 'endpoint' => 'https://{compte}.r2.cloudflarestorage.com', 'region' => 'auto', 'hint' => 'Tableau de bord Cloudflare › R2 › Gérer les jetons d\'API (accès lecture et écriture au compartiment).'],
        'b2' => ['label' => 'Backblaze B2', 'endpoint' => 'https://s3.{region}.backblazeb2.com', 'region' => 'eu-central-003', 'hint' => 'Backblaze › App Keys : keyID = clé d\'accès, applicationKey = clé secrète. La région figure dans l\'adresse « Endpoint » du compartiment.'],
        'wasabi' => ['label' => 'Wasabi', 'endpoint' => 'https://s3.{region}.wasabisys.com', 'region' => 'eu-central-1', 'hint' => 'Wasabi › Access Keys.'],
        'idrive' => ['label' => 'IDrive e2', 'endpoint' => 'https://{compte}.idrivee2.com', 'region' => 'us-east-1', 'hint' => 'IDrive e2 › Access Keys ; l\'adresse est affichée pour votre région.'],
        's3' => ['label' => 'Amazon S3', 'endpoint' => 'https://s3.{region}.amazonaws.com', 'region' => 'eu-west-3', 'hint' => 'AWS IAM : utilisateur limité à ce compartiment (s3:PutObject, GetObject, DeleteObject).'],
        'scaleway' => ['label' => 'Scaleway', 'endpoint' => 'https://s3.{region}.scw.cloud', 'region' => 'fr-par', 'hint' => 'Scaleway › Identifiants › Clés d\'API.'],
        'ovh' => ['label' => 'OVHcloud Object Storage', 'endpoint' => 'https://s3.{region}.io.cloud.ovh.net', 'region' => 'gra', 'hint' => 'Espace client OVHcloud › Public Cloud › Object Storage › Utilisateurs S3.'],
        'contabo' => ['label' => 'Contabo', 'endpoint' => 'https://{region}.contabostorage.com', 'region' => 'eu2', 'hint' => 'Contabo › Object Storage › Identifiants S3.'],
        'minio' => ['label' => 'MinIO (votre serveur)', 'endpoint' => 'https://minio.exemple.com', 'region' => 'us-east-1', 'hint' => 'Adresse de l\'API S3 de MinIO.'],
        'other' => ['label' => 'Autre (compatible S3)', 'endpoint' => '', 'region' => 'auto', 'hint' => 'Tout service compatible S3 : adresse, région, compartiment et clés.'],
    ];

    public function index()
    {
        $list = StorageBackend::orderBy('priority')->orderBy('id')->get();
        $capacity = $list->where('is_active', true)->contains(fn ($b) => ! $b->capacity_mb) ? null : $list->where('is_active', true)->sum(fn ($b) => $b->capacity_mb * 1048576);

        return ['data' => $list->map(fn (StorageBackend $b) => $b->toArray() + ['has_keys' => filled($b->getRawOriginal('access_key')), 'free_bytes' => $b->freeBytes(),
            'full' => $b->capacity_mb && $b->used_bytes >= $b->capacity_mb * 1048576 * StoragePool::MARGIN]),
            'totals' => ['used' => (int) $list->sum('used_bytes'), 'capacity' => $capacity],
            'providers' => self::PROVIDERS];
    }

    protected function rules(bool $local): array
    {
        return $local ? ['name' => 'sometimes|string|max:100', 'capacity_mb' => 'nullable|integer|min:1', 'is_active' => 'sometimes|boolean'] : [
            'name' => 'sometimes|required|string|max:100', 'provider' => 'sometimes|in:'.implode(',', array_keys(self::PROVIDERS)),
            'endpoint' => 'sometimes|required|url:https,http|max:255', 'region' => 'nullable|string|max:60', 'bucket' => 'sometimes|required|string|max:120',
            'access_key' => 'nullable|string|max:255', 'secret_key' => 'nullable|string|max:500', 'capacity_mb' => 'nullable|integer|min:1', 'is_active' => 'sometimes|boolean',
        ];
    }

    /** Refuse les adresses internes (métadonnées du serveur, réseau local sauf MinIO). */
    protected function guardEndpoint(string $endpoint, string $provider): void
    {
        if ($why = \App\Storage\S3Lite::blockedReason($endpoint, $provider === 'minio')) {
            throw \Illuminate\Validation\ValidationException::withMessages(['endpoint' => $why]);
        }
    }

    public function store(Request $r)
    {
        $v = $r->validate(['name' => 'required|string|max:100', 'endpoint' => 'required', 'bucket' => 'required', 'access_key' => 'required', 'secret_key' => 'required'] + $this->rules(false));
        $this->guardEndpoint($v['endpoint'], $v['provider'] ?? 'other');
        $v['priority'] = (int) StorageBackend::max('priority') + 1;
        $b = StorageBackend::create($v);
        ActivityLog::record('admin.storage.created', null, ['id' => $b->id, 'provider' => $b->provider]);

        return ['data' => $b, 'test' => app(StoragePool::class)->test($b)] + $this->index();
    }

    public function update(Request $r, int $id)
    {
        $b = StorageBackend::findOrFail($id);
        $v = $r->validate($this->rules($b->isLocal()));
        // Clés laissées vides : on garde celles enregistrées
        foreach (['access_key', 'secret_key'] as $k) {
            if (array_key_exists($k, $v) && blank($v[$k])) {
                unset($v[$k]);
            }
        }
        if ($b->isLocal() && array_key_exists('is_active', $v) && ! $v['is_active'] && ! StorageBackend::where('id', '!=', $b->id)->where('is_active', true)->exists()) {
            return response()->json(['message' => 'Gardez au moins un stockage actif.'], 422);
        }
        if (! $b->isLocal() && (isset($v['endpoint']) || isset($v['provider']))) {
            $this->guardEndpoint($v['endpoint'] ?? (string) $b->endpoint, $v['provider'] ?? (string) $b->provider);
        }
        $b->update($v);
        ActivityLog::record('admin.storage.updated', null, ['id' => $b->id]);

        return $this->index();
    }

    public function test(int $id)
    {
        return app(StoragePool::class)->test(StorageBackend::findOrFail($id)) + $this->index();
    }

    /** Nouvel ordre de priorité (identifiants du premier au dernier). */
    public function reorder(Request $r)
    {
        $ids = $r->validate(['ids' => 'required|array', 'ids.*' => 'integer'])['ids'];
        foreach (array_values($ids) as $i => $id) {
            StorageBackend::whereKey($id)->update(['priority' => $i + 1]);
        }

        return $this->index();
    }

    public function destroy(int $id)
    {
        $b = StorageBackend::findOrFail($id);
        $count = \Illuminate\Support\Facades\Schema::hasTable('dc_versions') ? \Illuminate\Support\Facades\DB::table('dc_versions')->where('backend_id', $b->id)->count() : 0;
        if ($b->isLocal() || $count > 0) {
            return response()->json(['message' => $b->isLocal() ? 'Le stockage du serveur ne peut pas être supprimé (désactivez-le si besoin).'
                : "Ce stockage contient encore $count fichier(s) : désactivez-le plutôt (les fichiers restent lisibles, les nouveaux vont ailleurs)."], 422);
        }
        $b->delete();
        ActivityLog::record('admin.storage.deleted', null, ['id' => $id]);

        return $this->index();
    }
}
