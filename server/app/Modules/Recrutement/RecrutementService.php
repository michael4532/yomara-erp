<?php

namespace App\Modules\Recrutement;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use App\Modules\Recrutement\Models\Application;
use App\Modules\Recrutement\Models\Interview;
use App\Modules\Recrutement\Models\Job;
use App\Modules\Rh\Models\Employee;
use App\Services\EntityWriter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RecrutementService
{
    public const DEFAULTS = ['careers_enabled' => true, 'careers_intro' => null, 'retention_months' => 24];

    public const CV_EXT = ['pdf', 'doc', 'docx', 'odt', 'jpg', 'jpeg', 'png'];

    public function __construct(protected MessagingService $messaging) {}

    public function settings(Company $company): array
    {
        return array_merge(self::DEFAULTS, (array) data_get($company->settings, 'recrutement', []));
    }

    public function storeCv(Application $a, UploadedFile $file): void
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, self::CV_EXT, true)) {
            throw new \DomainException('CV attendu en PDF, Word ou photo (JPG, PNG).');
        }
        $old = $a->cv_path;
        $path = $file->storeAs('cvs/'.$a->company_id, $a->id.'-'.Str::random(6).'.'.$ext, 'local');
        $a->forceFill(['cv_path' => $path, 'cv_name' => mb_substr($file->getClientOriginalName(), 0, 120)])->save();
        if ($old && $old !== $path) {
            Storage::disk('local')->delete($old);
        }
    }

    public function deleteCv(Application $a): void
    {
        if ($a->cv_path) {
            Storage::disk('local')->delete($a->cv_path);
        }
    }

    /** Candidature reçue par la page Emplois. */
    public function apply(Company $company, ?Job $job, array $data, ?UploadedFile $cv): Application
    {
        if ($cv && ! in_array(strtolower($cv->getClientOriginalExtension()), self::CV_EXT, true)) {
            throw new \DomainException('CV attendu en PDF, Word ou photo (JPG, PNG).');
        }
        $a = new Application([
            'job_id' => $job?->id, 'first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null, 'city' => $data['city'] ?? null, 'source' => 'website', 'cover_letter' => $data['cover_letter'] ?? null,
            'expected_salary' => $data['expected_salary'] ?? null, 'available_from' => $data['available_from'] ?? null, 'stage' => 'new',
        ]);
        $a->id = (string) Str::uuid();
        $a->company_id = $company->id;
        $a->consent_at = now();
        $a->stage_at = now();
        $a->save();
        if ($cv) {
            $this->storeCv($a, $cv);
        }
        $managers = User::where('company_id', $company->id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('recrutement.manage'))->pluck('id')->all();
        AppNotification::send($company->id, $managers, 'recrutement', 'Nouvelle candidature', $a->first_name.' '.$a->last_name.' — '.($job?->title ?? 'candidature spontanée'), 'recrutement', $a->id);
        $this->message($company, $a, 'Candidature bien reçue — '.$company->name,
            "Bonjour {$a->first_name},\n\nNous avons bien reçu votre candidature".($job ? ' au poste de « '.$job->title.' »' : '').". Elle sera étudiée avec attention et nous reviendrons vers vous.\n\nCordialement,\n{$company->name}", 'auto', quiet: true);

        return $a;
    }

    /** Message au candidat : WhatsApp, SMS ou e-mail selon ce qui est configuré et ses coordonnées. */
    public function message(Company $company, Application $a, string $subject, string $text, string $channel = 'auto', bool $quiet = false): ?string
    {
        $available = $this->messaging->available($company);
        $order = $channel === 'auto' ? ['email', 'whatsapp', 'sms'] : [$channel];
        foreach ($order as $ch) {
            $to = $ch === 'email' ? $a->email : $a->phone;
            if (! $to || ! ($available[$ch] ?? null)) {
                continue;
            }
            try {
                $m = $ch === 'email'
                    ? new Message(to: $to, toName: $a->first_name.' '.$a->last_name, subject: $subject, html: nl2br(e($text)), text: $text, replyTo: $company->email)
                    : new Message(to: $to, toName: $a->first_name.' '.$a->last_name, text: $text);
                $r = $this->messaging->send($company, $ch, $m, 'application:'.$a->id);
                if ($r->ok) {
                    return $ch;
                }
            } catch (\Throwable $e) {
                if (! $quiet && $channel !== 'auto') {
                    throw new \DomainException('Envoi impossible : '.$e->getMessage());
                }
            }
        }
        if (! $quiet) {
            throw new \DomainException('Aucun canal disponible pour joindre ce candidat (configurez l\'e-mail, WhatsApp ou les SMS dans les paramètres de messagerie, et vérifiez ses coordonnées).');
        }

        return null;
    }

    /** Embauche : fiche salarié créée (pré-remplie), candidature close, offre close quand tous les postes sont pourvus. */
    public function hire(Company $company, Application $a, array $o): Employee
    {
        if ($a->stage === 'hired') {
            throw new \DomainException('Ce candidat est déjà embauché.');
        }
        $job = $a->job_id ? Job::find($a->job_id) : null;
        $id = (string) Str::uuid();
        $r = app(EntityWriter::class)->upsert(Employee::class, $id, array_filter([
            'first_name' => $a->first_name, 'last_name' => $a->last_name, 'email' => $a->email, 'phone' => $a->phone,
            'job_title' => $o['job_title'] ?? $job?->title, 'department' => $o['department'] ?? $job?->department,
            'contract_type' => $o['contract_type'] ?? $job?->contract_type ?? 'CDI', 'hire_date' => $o['hire_date'] ?? now()->toDateString(),
            'base_salary' => $o['base_salary'] ?? null, 'address' => $a->city,
        ], fn ($v) => $v !== null && $v !== ''));
        if ($r['status'] !== EntityWriter::APPLIED) {
            throw new \DomainException('Fiche salarié refusée : '.$r['message'].' '.json_encode($r['errors']));
        }
        $a->forceFill(['stage' => 'hired', 'stage_at' => now(), 'employee_id' => $id])->save();
        if ($job && Application::where('job_id', $job->id)->where('stage', 'hired')->count() >= $job->positions && $job->status === 'open') {
            $job->forceFill(['status' => 'closed'])->save();
        }

        return $r['record'];
    }

    /** Statistiques : candidatures par offre et par étape, sources, délai moyen d'embauche, motifs de refus. */
    public function stats(): array
    {
        $apps = Application::all();
        $jobs = Job::orderByDesc('created_at')->get();
        $hired = $apps->where('stage', 'hired');

        return [
            'total' => $apps->count(),
            'by_stage' => collect(Application::STAGES)->map(fn ($l, $k) => $apps->where('stage', $k)->count()),
            'by_source' => $apps->groupBy('source')->map->count(),
            'reasons' => $apps->where('stage', 'rejected')->groupBy(fn ($a) => $a->reject_reason ?: 'Non précisé')->map->count()->sortDesc()->take(8),
            'days_to_hire' => $hired->count() ? round($hired->avg(fn ($a) => $a->created_at->diffInDays($a->stage_at ?? now())), 1) : null,
            'jobs' => $jobs->map(fn ($j) => ['id' => $j->id, 'title' => $j->title, 'status' => $j->status, 'positions' => $j->positions,
                'applications' => $apps->where('job_id', $j->id)->count(), 'hired' => $apps->where('job_id', $j->id)->where('stage', 'hired')->count(),
                'in_progress' => $apps->where('job_id', $j->id)->whereIn('stage', ['new', 'screening', 'interview', 'offer'])->count()])->values(),
            'interviews' => Interview::where('status', 'planned')->where('starts_at', '>=', now()->startOfDay())->count(),
        ];
    }

    /** Données personnelles : candidatures non retenues effacées (CV compris) après la durée de conservation. */
    public function purge(Company $company): int
    {
        $months = (int) $this->settings($company)['retention_months'];
        if ($months <= 0) {
            return 0;
        }
        $n = 0;
        foreach (Application::where('stage', 'rejected')->where('updated_at', '<', now()->subMonths($months))->get() as $a) {
            // Effacement réel des données personnelles (la ligne supprimée ne garde que l'étape, pour les statistiques passées)
            $this->deleteCv($a);
            $a->forceFill(['first_name' => 'Candidat', 'last_name' => 'effacé', 'email' => null, 'phone' => null, 'city' => null, 'cover_letter' => null,
                'notes' => null, 'expected_salary' => null, 'cv_path' => null, 'cv_name' => null])->saveQuietly();
            Interview::where('application_id', $a->id)->get()->each(fn ($i) => $i->forceFill(['feedback' => null])->saveQuietly());
            app(EntityWriter::class)->delete(Application::class, $a->id);
            $n++;
        }

        return $n;
    }
}
