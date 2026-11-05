<?php

namespace App\Modules\Migration;

use App\Models\Company;
use App\Models\User;
use App\Modules\Migration\Models\MigrationJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Migration en arrière-plan (file d'attente) : peut durer plusieurs minutes. */
class RunMigration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $jobId, public int $companyId) {}

    public function handle(MigrationService $svc): void
    {
        $company = Company::findOrFail($this->companyId);
        app()->instance('tenant', $company);
        app()->instance('branch_scope', null);
        app()->instance('branch_current', null);
        $job = MigrationJob::find($this->jobId);
        if (! $job || $job->status === 'done') {
            return;
        }
        if ($u = User::where('company_id', $company->id)->find($job->user_id)) {
            auth()->setUser($u);
        }
        $svc->run($job);
    }
}
