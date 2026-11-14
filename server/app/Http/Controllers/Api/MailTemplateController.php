<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Support\MailTemplates;
use Illuminate\Http\Request;

/** Modèles d'e-mails : par défaut pour toutes les sociétés (super admin) ou propres à une société. */
class MailTemplateController extends Controller
{
    protected function list($company, bool $admin): array
    {
        $platform = (array) PlatformSetting::get(MailTemplates::PLATFORM_KEY, []);
        $out = [];
        foreach (MailTemplates::definitions() as $key => [$label, $group, $subject, $body, $vars]) {
            [$s, $b, $own] = MailTemplates::get($key, $admin ? null : $company);
            $out[] = ['key' => $key, 'label' => $label, 'group' => $group, 'vars' => $vars, 'subject' => $s, 'body' => $b,
                'customized' => $admin ? ! empty($platform[$key]) : $own, 'default_subject' => $subject, 'default_body' => $body];
        }

        return $out;
    }

    protected function rules(): array
    {
        return ['templates' => 'required|array', 'templates.*.subject' => 'nullable|string|max:300', 'templates.*.body' => 'nullable|string|max:10000', 'templates.*.reset' => 'sometimes|boolean'];
    }

    protected function merge(array $current, array $in): array
    {
        $keys = array_keys(MailTemplates::definitions());
        foreach ($in as $key => $t) {
            if (! in_array($key, $keys, true)) {
                continue;
            }
            if (! empty($t['reset'])) {
                unset($current[$key]);
                continue;
            }
            $current[$key] = ['subject' => trim((string) ($t['subject'] ?? '')), 'body' => trim((string) ($t['body'] ?? ''))];
        }

        return $current;
    }

    // ---------------------------------------------------------------- super admin : défaut de toutes les sociétés
    public function adminIndex()
    {
        return ['data' => $this->list(null, true)];
    }

    public function adminUpdate(Request $r)
    {
        $d = $r->validate($this->rules());
        PlatformSetting::put(MailTemplates::PLATFORM_KEY, $this->merge((array) PlatformSetting::get(MailTemplates::PLATFORM_KEY, []), $d['templates']));

        return ['data' => $this->list(null, true), 'message' => 'Modèles enregistrés pour toutes les sociétés.'];
    }

    public function adminPreview(Request $r)
    {
        $d = $r->validate(['key' => 'required|string|max:40', 'subject' => 'nullable|string|max:300', 'body' => 'nullable|string|max:10000']);

        return ['data' => MailTemplates::preview($d['key'], null, $d['subject'] ?? null, $d['body'] ?? null)];
    }

    // ---------------------------------------------------------------- société
    public function index()
    {
        return ['data' => $this->list(app('tenant'), false)];
    }

    public function update(Request $r)
    {
        $d = $r->validate($this->rules());
        $c = app('tenant');
        $settings = $c->settings ?? [];
        $settings['mail_templates'] = $this->merge((array) ($settings['mail_templates'] ?? []), $d['templates']);
        $c->forceFill(['settings' => $settings])->save();

        return ['data' => $this->list($c->fresh(), false), 'message' => 'Modèles enregistrés.'];
    }

    public function preview(Request $r)
    {
        $d = $r->validate(['key' => 'required|string|max:40', 'subject' => 'nullable|string|max:300', 'body' => 'nullable|string|max:10000']);

        return ['data' => MailTemplates::preview($d['key'], app('tenant'), $d['subject'] ?? null, $d['body'] ?? null)];
    }

    /** Textes en vigueur (pour pré-remplir l'envoi d'un document), lisibles par tous les utilisateurs. */
    public function effective()
    {
        $c = app('tenant');

        return ['data' => collect(MailTemplates::definitions())->mapWithKeys(fn ($d, $k) => [$k => array_combine(['subject', 'body'], array_slice(MailTemplates::get($k, $c), 0, 2))])];
    }
}
