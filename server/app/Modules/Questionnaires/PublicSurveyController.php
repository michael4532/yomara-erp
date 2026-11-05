<?php

namespace App\Modules\Questionnaires;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Page publique d'un questionnaire (lien ou QR code) : répondre sans compte, sur téléphone. */
class PublicSurveyController extends Controller
{
    public function __construct(private QuestionnaireService $s) {}

    protected function load(string $token): array
    {
        $f = DB::table('qs_forms')->where('token', $token)->first();
        abort_unless($f && $f->status !== 'draft', 404);
        $c = Company::find($f->company_id);
        abort_unless($c && $c->hasModule('questionnaires'), 404);

        return [$f, $c];
    }

    protected function page(Company $c, string $title, string $body): \Illuminate\Http\Response
    {
        $color = '#1F6FEB';
        $css = 'body{font-family:system-ui,Segoe UI,Roboto,sans-serif;background:#F4F6FB;margin:0;color:#1F2937}.w{max-width:640px;margin:0 auto;padding:18px 14px 40px}'
            .'.hd{display:flex;align-items:center;gap:12px;margin:6px 0 16px}.lg{width:44px;height:44px;border-radius:12px;background:'.$color.';color:#fff;font-weight:800;font-size:20px;display:flex;align-items:center;justify-content:center}'
            .'.card{background:#fff;border-radius:16px;padding:18px;margin-bottom:12px;box-shadow:0 4px 18px rgba(15,23,42,.05)}h1{font-size:22px;margin:0 0 6px}.muted{color:#64748B}'
            .'label.q{display:block;font-weight:650;margin-bottom:10px}.req{color:#DC2626}.err{color:#DC2626;font-size:14px;margin-top:6px}input[type=text],input[type=email],input[type=tel],input[type=number],input[type=date],textarea'
            .'{width:100%;box-sizing:border-box;padding:12px;border:1px solid #CBD5E1;border-radius:10px;font-size:16px;font-family:inherit}.opt{display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid #E2E8F0;border-radius:10px;margin-bottom:8px;cursor:pointer}'
            .'.scale{display:flex;flex-wrap:wrap;gap:6px}.scale label{flex:1;min-width:38px}.scale input{display:none}.scale span{display:block;text-align:center;padding:11px 0;border:1px solid #CBD5E1;border-radius:10px;font-weight:700;cursor:pointer}'
            .'.scale input:checked+span{background:'.$color.';border-color:'.$color.';color:#fff}.stars span{font-size:22px}.legend{display:flex;justify-content:space-between;font-size:12px;color:#94A3B8;margin-top:6px}'
            .'button{width:100%;padding:15px;border:0;border-radius:12px;background:'.$color.';color:#fff;font-weight:700;font-size:16px;cursor:pointer}.hp{position:absolute;left:-9999px}';

        return response('<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($title).'</title><style>'.$css.'</style></head><body><div class="w">'
            .'<div class="hd"><div class="lg">'.e(mb_strtoupper(mb_substr($c->name, 0, 1))).'</div><div><strong>'.e($c->name).'</strong></div></div>'.$body
            .'<p class="muted" style="text-align:center;font-size:12px;margin-top:20px">Propulsé par Yomara ERP</p></div></body></html>')->header('Content-Type', 'text/html; charset=utf-8');
    }

    public function show(string $token)
    {
        [$f, $c] = $this->load($token);

        return $this->form($f, $c, [], []);
    }

    protected function form(object $f, Company $c, array $old, array $errors, array $contact = [])
    {
        if (! QuestionnaireService::isOpen($f)) {
            return $this->page($c, $f->title, '<div class="card"><h1>'.e($f->title).'</h1><p class="muted">Ce questionnaire est fermé. Merci de votre intérêt.</p></div>');
        }
        $h = '<div class="card"><h1>'.e($f->title).'</h1>'.($f->description ? '<p class="muted" style="white-space:pre-line">'.e($f->description).'</p>' : '').'</div>';
        $h .= '<form method="post" action="'.e(url('/q/'.$f->token)).'"><input class="hp" type="text" name="website" tabindex="-1" autocomplete="off">';
        if ($errors) {
            $h .= '<div class="card" style="border:1px solid #FCA5A5"><strong style="color:#DC2626">Certaines réponses sont manquantes ou invalides.</strong></div>';
        }
        foreach (QuestionnaireService::questions($f) as $q) {
            $name = 'a['.$q['id'].']';
            $v = $old[$q['id']] ?? null;
            $h .= '<div class="card"><label class="q">'.e($q['label']).($q['required'] ? ' <span class="req">*</span>' : '').'</label>'.(! empty($q['help']) ? '<p class="muted" style="margin-top:-6px">'.e($q['help']).'</p>' : '');
            $h .= match ($q['type']) {
                'long' => '<textarea name="'.$name.'" rows="4">'.e((string) $v).'</textarea>',
                'single' => implode('', array_map(fn ($o) => '<label class="opt"><input type="radio" name="'.$name.'" value="'.e($o).'"'.($v === $o ? ' checked' : '').'> '.e($o).'</label>', $q['options'])),
                'multi' => implode('', array_map(fn ($o) => '<label class="opt"><input type="checkbox" name="'.$name.'[]" value="'.e($o).'"'.(in_array($o, (array) $v, true) ? ' checked' : '').'> '.e($o).'</label>', $q['options'])),
                'yesno' => implode('', array_map(fn ($o) => '<label class="opt"><input type="radio" name="'.$name.'" value="'.$o.'"'.($v === $o ? ' checked' : '').'> '.ucfirst($o).'</label>', ['oui', 'non'])),
                'rating' => '<div class="scale stars">'.implode('', array_map(fn ($i) => '<label><input type="radio" name="'.$name.'" value="'.$i.'"'.((string) $v === (string) $i ? ' checked' : '').'><span>'.$i.' ★</span></label>', range(1, 5))).'</div><div class="legend"><span>Pas du tout satisfait</span><span>Très satisfait</span></div>',
                'nps' => '<div class="scale">'.implode('', array_map(fn ($i) => '<label><input type="radio" name="'.$name.'" value="'.$i.'"'.((string) $v === (string) $i ? ' checked' : '').'><span>'.$i.'</span></label>', range(0, 10))).'</div><div class="legend"><span>Pas du tout probable</span><span>Très probable</span></div>',
                'number' => '<input type="number" step="any" name="'.$name.'" value="'.e((string) $v).'">',
                'date' => '<input type="date" name="'.$name.'" value="'.e((string) $v).'">',
                'email' => '<input type="email" name="'.$name.'" value="'.e((string) $v).'">',
                'phone' => '<input type="tel" name="'.$name.'" value="'.e((string) $v).'">',
                default => '<input type="text" name="'.$name.'" value="'.e((string) $v).'">',
            };
            if (isset($errors[$q['id']])) {
                $h .= '<div class="err">'.e($errors[$q['id']]).'</div>';
            }
            $h .= '</div>';
        }
        if ($f->ask_contact) {
            $h .= '<div class="card"><label class="q">Vos coordonnées <span class="muted" style="font-weight:400">(facultatif, pour vous recontacter)</span></label>'
                .'<input type="text" name="name" placeholder="Nom" value="'.e($contact['name'] ?? '').'" style="margin-bottom:8px"><input type="tel" name="phone" placeholder="Téléphone" value="'.e($contact['phone'] ?? '').'"></div>';
        }

        return $this->page($c, $f->title, $h.'<button type="submit">Envoyer mes réponses</button></form>');
    }

    public function submit(Request $r, string $token)
    {
        [$f, $c] = $this->load($token);
        if ($r->filled('website')) {
            return $this->page($c, $f->title, '<div class="card"><h1>Merci !</h1></div>'); // robot : rien n'est enregistré
        }
        if (! QuestionnaireService::isOpen($f)) {
            return $this->form($f, $c, [], []);
        }
        $input = (array) $r->input('a', []);
        $contact = ['name' => (string) $r->input('name', ''), 'phone' => (string) $r->input('phone', '')];
        try {
            DB::transaction(fn () => $this->s->store($f, $input, $contact, 'link', null, $r->ip()));
        } catch (QuestionnaireErrors $e) {
            return $this->form($f, $c, $input, $e->errors, $contact);
        } catch (\DomainException $e) {
            return $this->form($f, $c, $input, ['_' => $e->getMessage()], $contact);
        }

        return $this->page($c, $f->title, '<div class="card" style="text-align:center"><div style="font-size:46px">🙏</div><h1>Merci !</h1><p class="muted" style="white-space:pre-line">'
            .e($f->thank_you ?: 'Vos réponses ont bien été enregistrées.').'</p></div>');
    }
}
