<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MailDirectoryTest extends TestCase
{
    public function test_every_colleague_and_new_intern_mailbox_appears_in_the_address_book(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Annuaire SARL');
        app()->instance('tenant', $company);
        $role = \App\Models\Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Commercial')->value('id');
        $awa = User::create(['company_id' => $company->id, 'name' => 'Awa Koné', 'email' => 'awa.login@annuaire.ci', 'password' => 'secret123', 'role_id' => $role]);
        \App\Modules\Mailbox\Models\Account::create(['user_id' => null, 'provider' => 'imap', 'email' => 'direction@annuaire.ci', 'display_name' => 'Direction', 'status' => 'active']);

        $dir = fn () => collect($this->api($token)->getJson('/api/mailbox')->assertOk()->json('data.directory'))->keyBy('email');
        $d = $dir();
        $this->assertTrue($d->has('awa.login@annuaire.ci'));
        $this->assertSame('Boîte partagée', $d['direction@annuaire.ci']['label']);

        // Nouveau stagiaire : fiche salarié, puis sa boîte connectée
        $this->api($token)->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'op' => 'upsert', 'entity' => 'rh_employees', 'record_id' => $this->uuid(),
            'data' => ['first_name' => 'Yao', 'last_name' => 'Kouassi', 'email' => 'yao@annuaire.ci', 'job_title' => 'Assistant commercial', 'staff_type' => 'intern', 'hire_date' => now()->toDateString()]]]])->assertOk();
        $this->assertStringContainsString('Stagiaire', $dir()['yao@annuaire.ci']['label']);
        \App\Modules\Mailbox\Models\Account::create(['user_id' => $awa->id, 'provider' => 'imap', 'email' => 'awa@annuaire.ci', 'status' => 'active']);
        $this->assertSame('Awa Koné', $dir()['awa@annuaire.ci']['name']);

        // Un autre collaborateur voit le même carnet
        $bobToken = User::create(['company_id' => $company->id, 'name' => 'Bob', 'email' => 'bob@annuaire.ci', 'password' => 'secret123', 'role_id' => $role])->createToken('t')->plainTextToken;
        $this->assertContains('yao@annuaire.ci', collect($this->api($bobToken)->getJson('/api/mailbox')->json('data.directory'))->pluck('email'));
        $this->assertSame(0, DB::table('mb_accounts')->where('user_id', 999)->count());
    }
}
