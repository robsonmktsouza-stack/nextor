<?php
namespace App\Http\Controllers;

use App\Mail\SettingsTestMail;
use App\Models\ControlSetting;
use App\Services\OutboundMailSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ControlSettingsController extends Controller
{
    public function index(OutboundMailSettings $outbound)
    {
        $settings = ControlSetting::saved();
        return view('settings.index', [
            'settings' => $settings,
            'emailReady' => $outbound->enabled(),
            'smtpReady' => $outbound->configured(),
        ]);
    }

    private function record(): ControlSetting
    {
        $settings = ControlSetting::query()->firstOrNew(['id' => 1]);
        $settings->id = 1;
        return $settings;
    }

    public function saveMail(Request $request)
    {
        $data = $request->validate([
            'smtp_host' => ['nullable', 'string', 'max:253',
                'regex:/^[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?$/'],
            'smtp_port' => ['required', 'integer', 'between:1,65535'],
            'smtp_security' => ['required', Rule::in(['tls', 'ssl'])],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            // Laravel does not flash fields named password on validation errors.
            'password' => ['nullable', 'string', 'max:2048'],
            'smtp_from_address' => ['nullable', 'email', 'max:255'],
            'smtp_from_name' => ['required', 'string', 'max:100'],
            'email_enabled' => ['required', 'boolean'],
        ]);

        $settings = $this->record();
        $settings->fill([
            'smtp_host' => strtolower(trim($data['smtp_host'] ?? '')),
            'smtp_port' => (int) $data['smtp_port'],
            'smtp_security' => $data['smtp_security'],
            'smtp_username' => trim($data['smtp_username'] ?? ''),
            'smtp_from_address' => strtolower(trim($data['smtp_from_address'] ?? '')),
            'smtp_from_name' => trim($data['smtp_from_name']),
            'email_enabled' => (bool) $data['email_enabled'],
        ]);
        if (!empty($data['password'])) {
            $settings->smtp_password = $data['password'];
        }

        if ($settings->email_enabled && !$settings->smtpReady()) {
            throw ValidationException::withMessages([
                'email_enabled' => 'Preencha os dados do e-mail antes de ativar o envio.',
            ]);
        }
        if (!$settings->email_enabled) {
            $settings->invoice_email_enabled = false;
        }
        $settings->save();
        return redirect()->route('settings.index', ['#' => 'email'])
            ->with('success', 'Configurações de e-mail salvas.');
    }

    public function testMail(Request $request, OutboundMailSettings $outbound)
    {
        if (!$outbound->configured()) {
            return back()->with('error', 'Configure e salve o e-mail antes de realizar o teste.');
        }
        try {
            $outbound->send(
                $request->user()->email,
                new SettingsTestMail($request->user()->name),
                allowTest: true
            );
            return back()->with('success',
                'Mensagem de teste enviada ao servidor de e-mail. Confira sua caixa de entrada.');
        } catch (\Throwable) {
            return back()->with('error',
                'Não foi possível enviar o teste. Confira as configurações de e-mail.');
        }
    }

    public function saveBilling(Request $request, OutboundMailSettings $outbound)
    {
        $data = $request->validate([
            'recurring_invoices_enabled' => ['required', 'boolean'],
            'invoice_email_enabled' => ['required', 'boolean'],
            'invoice_days_ahead' => ['required', 'integer', 'between:0,30'],
        ]);

        if ($data['invoice_email_enabled'] && !$outbound->enabled()) {
            throw ValidationException::withMessages([
                'invoice_email_enabled' => 'Ative e configure o envio de e-mails primeiro.',
            ]);
        }

        $settings = $this->record();
        $settings->fill([
            'recurring_invoices_enabled' => (bool) $data['recurring_invoices_enabled'],
            'invoice_email_enabled' => (bool) $data['invoice_email_enabled'],
            'invoice_days_ahead' => (int) $data['invoice_days_ahead'],
        ]);
        $settings->save();
        return redirect()->route('settings.index', ['#' => 'faturamento'])
            ->with('success', 'Preferências de faturamento salvas.');
    }

    public function saveSchedules(Request $request)
    {
        $data = $request->validate([
            'automations_at' => ['required', 'date_format:H:i'],
            'invoices_at' => ['required', 'date_format:H:i'],
            'emails_start_at' => ['required', 'date_format:H:i'],
            'emails_end_at' => ['required', 'date_format:H:i', 'after:emails_start_at'],
        ]);
        $settings = $this->record();
        $settings->fill($data);
        $settings->save();

        return redirect()->route('settings.index', ['#' => 'agendamentos'])
            ->with('success', 'Horários atualizados.');
    }
}
