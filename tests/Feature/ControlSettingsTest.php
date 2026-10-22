<?php

namespace Tests\Feature;

use App\Mail\InvoiceNotice;
use App\Mail\SettingsTestMail;
use App\Models\Company;
use App\Models\ControlSetting;
use App\Models\InvoiceEmailDelivery;
use App\Models\Plan;
use App\Models\User;
use App\Services\InvoiceEmailNotificationService;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ControlSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::query()->create([
            'name' => 'Administradora',
            'email' => 'owner@example.test',
            'password' => 'SenhaDeTesteMuitoLonga',
        ]);
    }

    private function mailData(array $overrides = []): array
    {
        return array_replace([
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => '587',
            'smtp_security' => 'tls',
            'smtp_username' => 'financeiro@example.test',
            'password' => 'SegredoSMTP-de-Teste-2026',
            'smtp_from_address' => 'financeiro@example.test',
            'smtp_from_name' => 'Lumeron Financeiro',
            'email_enabled' => '1',
        ], $overrides);
    }

    private function company(): Company
    {
        Plan::query()->where('code', 'standard')->update(['monthly_price' => '59.90']);
        return Company::query()->create([
            'instance_id' => (string) Str::uuid(),
            'legal_name' => 'CLIENTE DE TESTE LTDA',
            'cnpj' => '11222333000181',
            'email' => 'cliente@example.test',
            'subdomain' => 'cliente-faturas',
            'plan' => 'standard',
            'license_status' => 'active',
            'expires_at' => today()->addDays(10)->toDateString(),
            'license_secret' => bin2hex(random_bytes(48)),
        ]);
    }

    public function test_only_logged_in_administrator_can_access_settings(): void
    {
        $this->get('/settings')->assertRedirect('/login');
        $this->put('/settings/mail', $this->mailData())->assertRedirect('/login');
        $this->post('/settings/mail/test')->assertRedirect('/login');
    }

    public function test_mail_password_is_encrypted_not_exposed_and_blank_does_not_replace_it(): void
    {
        $this->actingAs($this->admin());
        $this->put(route('settings.mail'), $this->mailData())->assertRedirect();
        $settings = ControlSetting::stored();
        $this->assertTrue($settings->smtpReady());
        $this->assertTrue($settings->email_enabled);
        $this->assertSame('SegredoSMTP-de-Teste-2026', $settings->smtp_password);
        $this->assertNotSame('SegredoSMTP-de-Teste-2026',
            DB::table('control_settings')->where('id', 1)->value('smtp_password'));
        $this->get(route('settings.index'))->assertOk()
            ->assertSee('Servidor de e-mail')
            ->assertSee('Faturamento')
            ->assertSee('Agendamentos')
            ->assertDontSee('SegredoSMTP-de-Teste-2026');

        $this->put(route('settings.mail'), $this->mailData([
            'password' => '', 'smtp_from_name' => 'Remetente Alterado',
        ]))->assertRedirect();
        $this->assertSame('SegredoSMTP-de-Teste-2026',
            ControlSetting::stored()->smtp_password);
        $this->assertSame('Remetente Alterado',
            ControlSetting::stored()->smtp_from_name);
    }

    public function test_email_cannot_be_enabled_without_credentials(): void
    {
        $this->actingAs($this->admin());
        $this->put(route('settings.mail'), $this->mailData([
            'smtp_host' => '', 'password' => '',
        ]))->assertSessionHasErrors('email_enabled');
        $this->assertDatabaseCount('control_settings', 0);
    }

    public function test_send_test_mail_uses_selected_account_and_saved_credentials(): void
    {
        $this->actingAs($this->admin());
        $this->put(route('settings.mail'), $this->mailData([
            'email_enabled' => '0',
        ]))->assertRedirect();
        Mail::fake();

        $this->post(route('settings.mail-test'))->assertRedirect()
            ->assertSessionHas('success');
        Mail::assertSent(SettingsTestMail::class, 1);
        Mail::assertSent(SettingsTestMail::class, function (SettingsTestMail $mailable): bool {
            return $mailable->hasTo('owner@example.test');
        });
    }

    public function test_invoice_email_is_not_scheduled_until_opted_in_and_only_once(): void
    {
        $this->actingAs($this->admin());
        Mail::fake();
        $company = $this->company();
        $invoice = app(InvoiceService::class)->create($company, [
            'period_start' => today()->addDays(11)->toDateString(),
            'period_end' => today()->addDays(40)->toDateString(),
            'issue_date' => today()->toDateString(),
            'due_date' => today()->addDays(7)->toDateString(),
        ]);
        app(InvoiceService::class)->issue($invoice);
        $this->assertDatabaseCount('invoice_email_deliveries', 0);
        $this->put(route('settings.mail'), $this->mailData())->assertRedirect();
        $this->put(route('settings.billing'), [
            'recurring_invoices_enabled' => '1',
            'invoice_email_enabled' => '1',
            'invoice_days_ahead' => 7,
        ])->assertRedirect();

        // Saving the option does not send earlier already issued invoices.
        $this->assertDatabaseCount('invoice_email_deliveries', 0);
        $second = app(InvoiceService::class)->create($company, [
            'period_start' => today()->addDays(41)->toDateString(),
            'period_end' => today()->addDays(70)->toDateString(),
            'issue_date' => today()->toDateString(),
            'due_date' => today()->addDays(7)->toDateString(),
        ]);
        app(InvoiceService::class)->issue($second);
        $this->assertDatabaseCount('invoice_email_deliveries', 1);
        $this->assertSame('pending', InvoiceEmailDelivery::query()->sole()->status);
        $service = app(InvoiceEmailNotificationService::class);
        $this->assertFalse($service->enqueue($second));
        $this->assertSame(1, $service->sendPending()['sent']);
        $this->assertSame(0, $service->sendPending()['sent']);
        Mail::assertSent(InvoiceNotice::class, 1);
        $this->assertDatabaseHas('invoice_email_deliveries', ['status' => 'sent']);
    }

    public function test_recurring_invoice_generation_respects_switch(): void
    {
        $this->actingAs($this->admin());
        $this->put(route('settings.billing'), [
            'recurring_invoices_enabled' => '0',
            'invoice_email_enabled' => '0',
            'invoice_days_ahead' => 12,
        ])->assertRedirect();
        $this->assertFalse(ControlSetting::recurringEnabled());
        $this->assertSame(12, ControlSetting::invoiceDays());
        $this->artisan('control:invoices-prepare')->assertExitCode(0);
    }

    public function test_schedule_configuration_is_validated_and_persisted(): void
    {
        $this->actingAs($this->admin());
        $this->put(route('settings.schedules'), [
            'automations_at' => '09:15',
            'invoices_at' => '09:30',
            'emails_start_at' => '08:00',
            'emails_end_at' => '20:00',
        ])->assertRedirect();
        $this->assertSame('09:15',
            ControlSetting::schedule('automations_at', '08:00'));
        $this->assertSame('20:00',
            ControlSetting::schedule('emails_end_at', '18:00'));
        $this->put(route('settings.schedules'), [
            'automations_at' => '09:15',
            'invoices_at' => '09:30',
            'emails_start_at' => '20:00',
            'emails_end_at' => '08:00',
        ])->assertSessionHasErrors('emails_end_at');
    }
    public function test_smtp_diagnostic_reports_authentication_failure_without_leaking_details(): void
    {
        $this->actingAs($this->admin());
        $this->put(route('settings.mail'), $this->mailData([
            'email_enabled' => '0',
        ]))->assertRedirect();

        \Illuminate\Support\Facades\Mail::shouldReceive('mailer')->once()
            ->andThrow(new \RuntimeException(
                'Failed to authenticate on SMTP server with username financeiro@example.test'
            ));

        $this->post(route('settings.mail-test'))
            ->assertRedirect()
            ->assertSessionHas('error', \App\Support\EmailConnectionFeedback::message(
                new \RuntimeException('Failed to authenticate on SMTP server')
            ));

        $this->get(route('settings.index'))
            ->assertOk()
            ->assertSee('O servidor rejeitou a autenticação.')
            ->assertDontSee('Failed to authenticate on SMTP server with username');
    }

    public function test_smtp_diagnostic_distinguishes_ssl_dns_and_network_failures(): void
    {
        $diagnostics = \App\Support\EmailConnectionFeedback::class;
        $this->assertSame('certificate',
            $diagnostics::category(new \RuntimeException('SSL operation failed: certificate verify failed')));
        $this->assertSame('dns',
            $diagnostics::category(new \RuntimeException('php_network_getaddresses: getaddrinfo failed')));
        $this->assertSame('connection',
            $diagnostics::category(new \RuntimeException('Connection timed out')));
        $this->assertSame('rejected',
            $diagnostics::category(new \RuntimeException('Expected code 250 but got 550')));
    }
}
