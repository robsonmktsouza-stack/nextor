<?php

namespace App\Services\Fiscal;

use App\Models\CompanySetting;
use App\Models\NfeDraft;
use App\Support\InstanceIdentity;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Reconciles a queried cancellation receipt, without transmitting an event. */
final class Nfe55CancellationReconciliationService
{
    public function reconcile(NfeDraft $draft, string $responsePath): bool
    {
        $expectedPath = 'fiscal/nfe55/'.$draft->id.'/consult-response.ini';
        if ($responsePath !== $expectedPath || !Storage::disk('local')->exists($expectedPath)) return false;
        $company = CompanySetting::current();
        $cnpj = preg_replace('/\D/', '', (string) $company->document);
        if (!InstanceIdentity::matches($company->document)
            || substr((string) $draft->access_key, 6, 14) !== $cnpj
            || substr((string) $draft->access_key, 20, 2) !== '55') return false;
        $protocol = $this->proof(Storage::disk('local')->get($expectedPath), $draft, $cnpj);
        if ($protocol === null) return false;

        return DB::transaction(function () use ($draft, $protocol, $expectedPath) {
            $locked = NfeDraft::query()->lockForUpdate()->findOrFail($draft->id);
            if (!in_array($locked->status, ['authorized', 'cancelled'], true)
                || $locked->access_key !== $draft->access_key
                || $locked->environment !== $draft->environment
                || $locked->protocol !== $draft->protocol) return false;
            if ($locked->status === 'cancelled') {
                return $locked->cancellation_protocol === $protocol;
            }
            $locked->update([
                'status' => 'cancelled', 'cancellation_status' => 'authorized',
                'cancelled_at' => now(), 'cancellation_protocol' => $protocol,
                'cancellation_response_path' => $expectedPath, 'error_message' => null,
            ]);
            return true;
        }, 3);
    }

    private function proof(string $raw, NfeDraft $draft, string $cnpj): ?string
    {
        if (strlen($raw) > 8388608 || preg_match('/<!DOCTYPE|<!ENTITY/i', $raw)) return null;
        // ACBr may put the complete consultation XML in Xml or XmlEvento.
        // Retain its enclosing namespaces; never parse a detached XML fragment.
        $candidates = [];
        if (str_starts_with(ltrim($raw), '<')) $candidates[] = trim($raw);
        foreach (preg_split('/\r\n|\n|\r/', $raw) as $line) {
            if (preg_match('/^\s*(?:Xml|XmlEvento|ProcEvento)\s*=(.*)$/i', $line, $match)) {
                $candidates[] = trim($match[1]);
            }
        }
        $environment = $draft->environment === 'homologation' ? '2' : '1';
        foreach ($candidates as $xml) {
            if (strlen($xml) > 6291456) continue;
            $dom = new DOMDocument();
            $old = libxml_use_internal_errors(true);
            try {
                if (!$dom->loadXML($xml, LIBXML_NONET)) continue;
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($old);
            }
            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
            foreach ($xpath->query('//n:procEventoNFe') as $event) {
                if (!$event instanceof DOMElement) continue;
                $read = static fn(string $path): string => trim((string) $xpath->evaluate('string('.$path.')', $event));
                $request = 'n:evento/n:infEvento/';
                $response = 'n:retEvento/n:infEvento/';
                $protocol = $read($response.'n:nProt');
                if (!in_array($read($response.'n:cStat'), ['135', '155'], true)
                    || !preg_match('/^\d{15}$/', $protocol)
                    || $read($request.'n:CNPJ') !== $cnpj
                    || $read($request.'n:detEvento/n:nProt') !== $draft->protocol) continue;
                foreach ([$request, $response] as $prefix) {
                    if ($read($prefix.'n:chNFe') !== $draft->access_key
                        || $read($prefix.'n:tpEvento') !== '110111'
                        || $read($prefix.'n:tpAmb') !== $environment
                        || $read($prefix.'n:nSeqEvento') !== '1') continue 2;
                }
                return $protocol;
            }
        }
        return null;
    }
}
