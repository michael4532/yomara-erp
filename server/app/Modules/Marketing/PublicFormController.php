<?php

namespace App\Modules\Marketing;

use App\Http\Controllers\Controller;
use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\Company;
use App\Modules\Marketing\Models\Contact;
use App\Modules\Marketing\Models\Form;
use App\Modules\Marketing\Models\MkList;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Formulaires d'inscription et pages de destination publics (/f/{slug}), avec double opt-in. */
class PublicFormController extends Controller
{
    protected function load(string $slug): array
    {
        $form = Form::withoutGlobalScopes()->where('slug', $slug)->where('active', true)->first();
        abort_unless($form, 404);
        $company = Company::find($form->company_id);
        abort_unless($company && $company->status !== 'suspended' && $company->hasModule('marketing'), 404);
        app()->instance('tenant', $company);

        return [$form, $company];
    }

    public function show(Request $r, string $slug)
    {
        [$form, $company] = $this->load($slug);
        $form->increment('views');

        return $this->page($form, $company, $r->boolean('ok') ? 'ok' : null);
    }

    protected function page(Form $form, Company $company, ?string $state, array $errors = [], array $old = [])
    {
        $renderer = new EmailRenderer($company);
        $style = $renderer->style($form->style);
        $vars = ['entreprise' => $company->name, 'prenom' => '', 'nom' => '', 'nom_complet' => '', 'societe' => '', 'email' => '', 'telephone' => '', 'lien_desinscription' => '#'];
        $rows = EmailRenderer::vars($renderer->rows($form->blocks, $style), array_map(fn ($v) => e((string) $v), $vars));

        return response()->view('marketing.page', compact('form', 'company', 'style', 'rows', 'state', 'errors', 'old'))
            ->header('X-Robots-Tag', $form->kind === 'form' ? 'noindex' : 'all');
    }

    public function submit(Request $r, string $slug, MessagingService $messaging, AutomationEngine $engine)
    {
        [$form, $company] = $this->load($slug);
        if (filled($r->input('website'))) { // piège à robots
            return $this->done($r, $form, $company, 'ok');
        }
        $rules = [];
        foreach ($form->fieldList() as $f) {
            $req = ! empty($f['required']) ? 'required' : 'nullable';
            $rules[$f['key']] = match ($f['key']) {
                'email' => [$req, 'email', 'max:190'],
                'phone' => [$req, 'string', 'max:40', 'regex:/^\+?[\d\s.\-()]{8,}$/'],
                'birthday' => [$req, 'date', 'before:today'],
                default => [$req, 'string', 'max:190'],
            };
        }
        if ($form->consent_text) {
            $rules['consent'] = ['accepted'];
        }
        try {
            $d = $r->validate($rules, ['consent.accepted' => 'Merci de cocher la case pour continuer.', 'phone.regex' => 'Numéro de téléphone invalide.'], array_map(fn ($f) => Form::FIELDS[$f['key']], array_column($form->fieldList(), null, 'key')));
        } catch (ValidationException $e) {
            if ($r->expectsJson()) {
                throw $e;
            }

            return $this->page($form, $company, null, $e->errors(), $r->all());
        }
        if (empty($d['email']) && empty($d['phone'])) {
            return $r->expectsJson() ? response()->json(['message' => 'Indiquez un e-mail ou un téléphone.'], 422) : $this->page($form, $company, null, ['email' => ['Indiquez un e-mail ou un téléphone.']], $r->all());
        }

        $contact = null;
        if (! empty($d['email'])) {
            $contact = Contact::where('email', mb_strtolower(trim($d['email'])))->first();
        }
        if (! $contact && ! empty($d['phone'])) {
            $digits = preg_replace('/\D/', '', $d['phone']);
            $contact = Contact::whereNotNull('phone')->get()->first(fn ($c) => preg_replace('/\D/', '', (string) $c->phone) === $digits);
        }
        $new = ! $contact;
        $contact ??= new Contact(['source' => 'form']);
        // Formulaire public (n'importe qui) : un contact existant n'est jamais modifié, seuls les champs vides sont complétés
        foreach (['first_name', 'last_name', 'company_name', 'email', 'phone', 'city', 'birthday'] as $k) {
            if (! empty($d[$k]) && ($new || blank($contact->{$k}))) {
                $contact->{$k} = $d[$k];
            }
        }
        $contact->consent_at = now();
        $contact->consent_ip = $r->ip();
        // Réabonnement d'une adresse désinscrite : seulement après confirmation par e-mail (double opt-in)
        $wasUnsubscribed = ! $new && $contact->unsubscribed_email_at !== null;
        if (! empty($d['email']) && $new) {
            $contact->unsubscribed_email_at = null;
        }
        $canConfirm = $form->double_optin && ! empty($contact->email) && ($messaging->available($company)['email'] ?? null);
        if ($canConfirm && ($new || $contact->opt_in === 'pending' || $wasUnsubscribed)) {
            $contact->opt_in = 'pending';
        } elseif ($contact->opt_in !== 'pending') {
            $contact->opt_in = 'confirmed';
        }
        $contact->save();
        $form->increment('submissions');
        if ($form->list_id && MkList::find($form->list_id)) {
            $contact->lists()->syncWithoutDetaching([$form->list_id]);
        }

        if ($contact->opt_in === 'pending') {
            $link = url('/f/confirm/'.$contact->token.'?form='.$form->id);
            $html = '<p>Bonjour '.e($contact->first_name ?? '').',</p><p>Merci pour votre inscription auprès de <b>'.e($company->name).'</b>. Confirmez votre adresse en cliquant sur le bouton ci-dessous :</p>'
                .'<p><a href="'.$link.'" style="display:inline-block;background:#1F6FEB;color:#fff;padding:12px 22px;border-radius:8px;text-decoration:none;font-weight:bold">Je confirme mon inscription</a></p>'
                .'<p style="color:#5B6B85;font-size:12px">Si vous n\'êtes pas à l\'origine de cette demande, ignorez ce message.</p>';
            try {
                $messaging->send($company, 'email', new Message(to: $contact->email, toName: $contact->full_name, subject: 'Confirmez votre inscription — '.$company->name, html: $html,
                    text: 'Confirmez votre inscription : '.$link, replyTo: $company->email), 'optin:'.$form->id);
            } catch (\Throwable) {
            }

            return $this->done($r, $form, $company, 'pending');
        }
        $contact->bump(5);
        $engine->listJoined($contact, $form->list_id ? [$form->list_id] : [], $form->id);

        return $this->done($r, $form, $company, 'ok');
    }

    protected function done(Request $r, Form $form, Company $company, string $state)
    {
        if ($r->expectsJson()) {
            return response()->json(['ok' => true, 'state' => $state, 'message' => $state === 'pending' ? 'Vérifiez votre boîte e-mail pour confirmer votre inscription.' : ($form->success_message ?: 'Merci, votre inscription est enregistrée.')]);
        }
        if ($state === 'ok' && $form->redirect_url && preg_match('#^https?://#', $form->redirect_url)) {
            return redirect()->away($form->redirect_url);
        }

        return $this->page($form, $company, $state);
    }

    public function confirm(Request $r, string $token, AutomationEngine $engine)
    {
        $contact = Contact::withoutGlobalScopes()->where('token', $token)->first();
        abort_unless($contact, 404);
        $company = Company::find($contact->company_id);
        app()->instance('tenant', $company);
        $form = Form::find((int) $r->query('form'));
        $first = $contact->opt_in === 'pending';
        // Confirmation par l'adresse elle-même : réabonnement possible
        $contact->forceFill(['opt_in' => 'confirmed', 'consent_at' => $contact->consent_at ?? now(), 'unsubscribed_email_at' => null])->saveQuietly();
        if ($first) {
            $contact->bump(5);
            $lists = $form?->list_id ? [$form->list_id] : $contact->lists()->pluck('mk_lists.id')->all();
            $engine->listJoined($contact, $lists, $form?->id);
        }
        if ($form) {
            if ($form->redirect_url && preg_match('#^https?://#', $form->redirect_url)) {
                return redirect()->away($form->redirect_url);
            }

            return $this->page($form, $company, 'confirmed');
        }

        return view('marketing.unsubscribe', ['company' => $company, 'channel' => 'email', 'done' => true, 'token' => $token, 'confirmed' => true]);
    }
}
