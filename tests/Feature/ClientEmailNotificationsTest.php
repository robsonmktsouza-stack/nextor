<?php

namespace Tests\Feature;

use App\Mail\LicenseExpiryNotice;
use App\Models\AutomationRule;
use App\Models\ClientEmailDelivery;
use App\Models\Company;
use App\Models\User;
use App\Services\AutomationRunner;
use App\Services\ClientEmailNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ClientEmailNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::create([
            'instance_id' => (string) Str::uuid(),
            'legal_name' => 'EMPRESA EXEMPLO LTDA',
            'cnpj' => '11222333000181',
            'email' => 'assinante@example.test',
            'subdomain' => 'licenca-exemplo',
            'plan' => 'standard',
            'license_status' => 'active',
            'expires_at' => today()->addDays(5)->toDateString(),
            'license_secret' => bin2hex(random_bytes(48)),
        ]);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Administradora',
            'email' => 'admin-notificacoes@example.test',
            'password' => 'senha-segura-teste',
        ]);
    }

    private function enableSender(): ClientEmailNotificationService
    {
        config()->set('client_notifications.email_enabled', true);
        config()->set('mail.default', 'smtp');
        config()->set('mail.from.address', 'avisos@example.test');
        Mail::fake();
        return app(ClientEmailNotificationService::class);
    }

    public function test_default_rules_never_queue_or_send_client_emails(): void
    {
        $this->company();
        $rule = AutomationRule::query()->where('code', 'expiry_soon')->firstOrFail();
        $this->assertFalse($rule->notify_client_email);
        $rule->update(['enabled' => true]);
        $this->assertSame(1, app(AutomationRunner::class)->run()['new_events']);
        $this->assertDatabaseCount('client_email_deliveries', 0);
        Mail::fake();
        $this->assertSame(0, app(ClientEmailNotificationService::class)->sendPending()['sent']);
        Mail::assertNothingSent();
    }

    public function test_customer_email_is_sent_only_once_for_each_active_license_cycle(): void
    {
        $this->company();
        $rule = AutomationRule::query()->where('code', 'expiry_soon')->firstOrFail();
        $rule->update(['enabled' => true, 'days' => 7, 'notify_client_email' => true]);
        $sender = $this->enableSender();

        $this->assertSame(1, app(AutomationRunner::class)->run()['new_events']);
        $this->assertDatabaseHas('client_email_deliveries', [
            'automation_rule_id' => $rule->id,
            'status' => 'pending',
        ]);
        $this->assertSame(1, $sender->sendPending()['sent']);
        Mail::assertSent(LicenseExpiryNotice::class, function (LicenseExpiryNotice $mail): bool {
            return $mail->companyName === 'EMPRESA EXEMPLO LTDA'
                && $mail->expiresDate === today()->addDays(5)->format('d/m/Y')
                && !$mail->expired;
        });
        $this->assertSame(0, app(AutomationRunner::class)->run()['new_events']);
        $this->assertSame(0, $sender->sendPending()['sent']);
        Mail::assertSentCount(1);
        $this->assertDatabaseHas('client_email_deliveries', ['status' => 'sent']);
    }

    public function test_pending_email_is_cancelled_after_renewal(): void
    {
        $company = $this->company();
        AutomationRule::query()->where('code', 'expiry_soon')->update([
            'enabled' => true, 'notify_client_email' => true, 'days' => 7,
        ]);
        app(AutomationRunner::class)->run();
        $sender = $this->enableSender();
        $company->update(['expires_at' => today()->addDays(60)->toDateString()]);

        $this->assertSame(0, $sender->sendPending()['sent']);
        $this->assertDatabaseHas('client_email_deliveries', ['status' => 'skipped']);
        Mail::assertNothingSent();
    }

    public function test_global_gate_and_transport_must_both_allow_sending(): void
    {
        $this->company();
        AutomationRule::query()->where('code', 'expiry_soon')->update([
            'enabled' => true, 'notify_client_email' => true, 'days' => 7,
        ]);
        app(AutomationRunner::class)->run();
        $sender = $this->enableSender();
        config()->set('client_notifications.email_enabled', false);
        $this->assertSame(0, $sender->sendPending()['sent']);
        config()->set('client_notifications.email_enabled', true);
        config()->set('mail.default', 'log');
        $this->assertSame(0, $sender->sendPending()['sent']);
        Mail::assertNothingSent();
        $this->assertDatabaseHas('client_email_deliveries', ['status' => 'pending']);
    }

    public function test_offline_rules_cannot_enable_customer_emails(): void
    {
        $this->actingAs($this->admin());
        $rule = AutomationRule::query()->where('code', 'offline')->firstOrFail();
        $this->put(route('automations.update', $rule), [
            'days' => 2, 'enabled' => '1', 'notify_client_email' => '1',
        ])->assertSessionHasErrors('notify_client_email');
        $this->assertFalse($rule->fresh()->notify_client_email);
    }

    public function test_owner_may_enable_client_email_using_the_rule_form(): void
    {
        $this->actingAs($this->admin());
        $rule = AutomationRule::query()->where('code', 'expiry_soon')->firstOrFail();
        $this->put(route('automations.update', $rule), [
            'name' => $rule->name,
            'description' => $rule->description,
            'days' => 7,
            'enabled' => '1',
            'notify_client_email' => '1',
        ])->assertRedirect(route('automations.index'));
        $this->assertTrue($rule->fresh()->notify_client_email);
        $this->get(route('automations.index'))->assertSee('E-mails para clientes');
    }
}
