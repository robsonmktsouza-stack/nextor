<?php

namespace App\Services\Fiscal;

use App\Models\FiscalDocumentJob;
use App\Models\NFCeInutilization;
use App\Models\Nfe55Inutilization;
use App\Models\NfeDraft;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Read-only explanations. Never a fiscal decision, correction policy or retry permission. */
final class SefazRejectionGuidance
{
    private ?array $catalog = null;

    public function __construct(private readonly SefazRejectionActions $actions) {}

    public function forDocument(NfeDraft|FiscalDocumentJob $document): ?array
    {
        if ($document instanceof FiscalDocumentJob && $document->document_type !== 'nfce') {
            return null; // Other DF-e have their own cStat namespaces.
        }
        $error = trim((string) $document->error_message);
        $cancellation = in_array($document->cancellation_status, ['rejected', 'uncertain'], true);
        if ($error === '' && !$cancellation
            && !in_array($document->status, ['rejected', 'pending', 'failed', 'error'], true)) {
            return null;
        }
        $base = 'fiscal/'.($document instanceof NfeDraft ? 'nfe55' : 'nfce').'/'.$document->getKey();
        $context = [
            'state' => $cancellation ? $document->cancellation_status : $document->status,
            'operation' => $cancellation ? 'cancelamento' : 'emissão',
        ];
        if (!$cancellation && in_array($document->status, ['authorized', 'cancelled'], true)) {
            return $this->fromStoredMessage($error, $context);
        }
        $result = null;
        if ($document->getKey() !== null) {
            if ($cancellation) {
                $result = $this->readResponse($base.'/cancel-response.ini', $document->access_key,
                    $document->environment, 'cancelamento');
            } else {
                // A later consultation must not erase an original rejection.
                // If the current visible error is specifically a consultation,
                // explain that consultation instead of an older send response.
                $consult = str_starts_with($error, 'Consulta SEFAZ:');
                $path = $consult ? $base.'/consult-response.ini' : $document->response_path;
                $allowed = [$base.'/sefaz-response.ini', $base.'/consult-response.ini'];
                if (in_array($path, $allowed, true)) {
                    $result = $this->readResponse($path, $document->access_key,
                        $document->environment, $consult ? 'consulta' : null);
                }
            }
        }
        if ($result !== null) {
            return $this->describe($result['code'], $result['reason'], $context);
        }
        return $this->fromStoredMessage($error, $context);
    }

    public function forInutilization(Nfe55Inutilization|NFCeInutilization $record): ?array
    {
        if ($record->status === 'authorized' || ($record->error_message === null
            && !in_array($record->status, ['rejected', 'uncertain'], true))) {
            return null;
        }
        $model = $record instanceof Nfe55Inutilization ? 'nfe55' : 'nfce';
        $folder = $record instanceof Nfe55Inutilization ? 'inutilizations' : 'inutilization';
        $path = 'fiscal/'.$model.'/'.$folder.'/'.$record->getKey().'/sefaz-response.ini';
        $context = ['state' => $record->status, 'operation' => 'inutilização'];
        $result = $record->getKey() !== null && $record->response_path === $path
            ? $this->readResponse($path, null, $record->environment, 'inutilizacao') : null;
        return $result !== null
            ? $this->describe($result['code'], $result['reason'], $context)
            : $this->fromStoredMessage((string) $record->error_message, $context);
    }

    /** Explains an observed response; it does not establish authorization/rejection. */
    public function describe(?string $code, string $technicalMessage, array $context = []): array
    {
        $code = $code !== null && preg_match('/^\d{3,4}$/D', $code) ? $code : null;
        $catalog = $this->catalog();
        $entry = $code !== null ? ($catalog['codes'][$code] ?? null) : null;
        $known = is_array($entry) && is_string($entry['message'] ?? null);
        $message = mb_substr(mb_convert_encoding($technicalMessage, 'UTF-8', 'UTF-8'), 0, 4000);
        if ($known) $entry = $this->matchingEntry($entry, $message);
        $guide = $known ? $this->actions->describe($code, $entry['message']) : [
            'title' => $code === null ? 'Confira o retorno fiscal' : 'Retorno SEFAZ '.$code,
            'summary' => $code === null
                ? 'Não foi possível identificar um código de retorno da SEFAZ nesta ocorrência.'
                : 'Este código ainda não possui orientação específica nesta versão do catálogo.',
            'steps' => ['Confira a mensagem original e os dados da nota. Se necessário, encaminhe o código e o motivo ao suporte fiscal.'],
            'fields' => [], 'category' => 'unknown',
        ];
        if ($code === null && $message !== '') {
            $guide['summary'] = $message;
            $guide['category'] = 'local_error';
        }
        $state = (string) ($context['state'] ?? '');
        $operation = (string) ($context['operation'] ?? 'emissão');
        $reportedSuccess = $known && preg_match('/^(?:Autorizado|Cancelamento de NF-e homologado|Inutilizaç[aã]o de n[uú]mero homologado|Evento registrado)/iu', $entry['message']);
        if (in_array($state, ['pending', 'processing', 'uncertain'], true) || $reportedSuccess) {
            $guide['summary'] .= ' A confirmação fiscal desta '.$operation.' precisa ser conferida.';
            array_unshift($guide['steps'], 'Consulte a situação na SEFAZ antes de qualquer nova tentativa. Preserve a chave e a numeração já utilizadas.');
        } elseif (in_array($state, ['authorized', 'cancelled'], true)) {
            array_unshift($guide['steps'], 'Preserve o documento já '.($state === 'authorized'
                ? 'autorizado' : 'cancelado').' e confira a ocorrência separadamente.');
        }
        $item = null;
        if (preg_match('/\b(?:nItem|item)\s*[:=]\s*(\d{1,3})\b/iu', $message, $match)) {
            $number = (int) $match[1];
            if ($number > 0 && $number <= 990) $item = $number;
        }
        $sources = [];
        foreach ($entry['source_ids'] ?? [] as $id) {
            foreach ($catalog['sources'] ?? [] as $source) {
                if (($source['id'] ?? null) === $id && $this->officialUrl((string) ($source['url'] ?? ''))) {
                    $sources[$id] = ['title' => (string) $source['title'], 'url' => $source['url']];
                }
            }
        }
        return $guide + [
            'code' => $code, 'known_code' => $known, 'technical_message' => $message,
            'item' => $item, 'catalog_version' => $catalog['version'] ?? null,
            'sources' => array_values($sources), 'official_message' => $known ? $entry['message'] : null,
        ];
    }

    private function matchingEntry(array $entry, string $message): array
    {
        // A diagnostic can refer to an older NT. Match only normalized exact
        // wording; never invent a meaning from a loose/fuzzy numeric match.
        $normalize = static function (string $value): string {
            $value = preg_replace('/\[[^\]]*\]/u', '', $value);
            $value = preg_replace('/^(?:(?:Retorno (?:da )?SEFAZ|Consulta SEFAZ)\s*:\s*\d{3,4}\s*[-—:]\s*)/iu', '', $value);
            return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value), " \t\n\r\0\x0B."), 'UTF-8');
        };
        $wanted = $normalize($message);
        if ($wanted === '' || $wanted === $normalize($entry['message'])) return $entry;
        foreach ($entry['variants'] ?? [] as $variant) {
            if ($normalize($variant['message']) === $wanted) {
                return array_replace($entry, ['message' => $variant['message'], 'source_ids' => $variant['source_ids']]);
            }
        }
        return $entry;
    }

    private function fromStoredMessage(string $error, array $context): array
    {
        $code = null;
        // Deliberately anchored: a product number, time, schema line or money
        // value in an arbitrary exception must never become a SEFAZ code.
        if (preg_match('/^(?:Retorno (?:da )?SEFAZ|Consulta SEFAZ|Rejeiç[aã]o(?: SEFAZ)?|Cancelamento rejeitado|Cancelamento pendente de reconciliaç[aã]o)\s*:\s*(\d{3,4})\s*(?:[-—:]|$)/iu', $error, $match)) {
            $code = $match[1];
        }
        return $this->describe($code, $error, $context);
    }

    private function readResponse(string $path, ?string $key, ?string $environment, ?string $section): ?array
    {
        try {
            $disk = Storage::disk('local');
            if (!$disk->exists($path) || $disk->size($path) > 2097152) return null;
            $raw = $disk->get($path);
            if (preg_match('/<!DOCTYPE|<!ENTITY/i', $raw)) return null;
            // Reject ambiguous duplicate sections/keys rather than silently
            // displaying whichever value PHP's INI parser happens to retain.
            $sections = [];
            $current = null;
            foreach (preg_split('/\r\n|\n|\r/', $raw) as $line) {
                if (preg_match('/^\s*\[([^\]\r\n]+)\]\s*$/', $line, $match)) {
                    $current = strtolower($match[1]);
                    if (isset($sections[$current])) return null;
                    $sections[$current] = [];
                } elseif ($current !== null && preg_match('/^\s*([a-zA-Z][a-zA-Z0-9_]*)\s*=/', $line, $match)) {
                    $name = strtolower($match[1]);
                    if (isset($sections[$current][$name])) return null;
                    $sections[$current][$name] = true;
                }
            }
            $parsed = @parse_ini_string($raw, true, INI_SCANNER_RAW);
            if (!is_array($parsed)) return null;
            $parsed = array_change_key_case($parsed, CASE_LOWER);
            if ($section === 'cancelamento' || $section === 'inutilizacao') {
                $data = $parsed[$section] ?? null;
            } else {
                $individuals = array_filter(array_keys($parsed), static fn($name) => preg_match('/^nfe\d+$/D', $name));
                if (count($individuals) > 1) return null;
                $name = $section === 'consulta' ? 'consulta'
                    : (array_values($individuals)[0] ?? (isset($parsed['retorno']) ? 'retorno' : 'envio'));
                $data = $parsed[$name] ?? null;
            }
            if (!is_array($data)) return null;
            $data = array_change_key_case($data, CASE_LOWER);
            $code = (string) ($data['cstat'] ?? '');
            if (!preg_match('/^\d{3,4}$/D', $code)) return null;
            foreach (['chdfe', 'chnfe'] as $field) {
                if ($key !== null && isset($data[$field]) && !hash_equals($key, (string) $data[$field])) return null;
            }
            if (isset($data['tpamb']) && (string) $data['tpamb'] !== ($environment === 'production' ? '1' : '2')) return null;
            return ['code' => $code, 'reason' => (string) ($data['xmotivo'] ?? $data['msg'] ?? '')];
        } catch (Throwable) {
            return null; // A help panel cannot break a document's read-only view.
        }
    }

    private function catalog(): array
    {
        if ($this->catalog !== null) return $this->catalog;
        $path = resource_path('fiscal/rejections/2026.10.26.json');
        $data = is_file($path) ? json_decode(file_get_contents($path), true) : null;
        return $this->catalog = is_array($data) ? $data : [];
    }

    private function officialUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        return parse_url($url, PHP_URL_SCHEME) === 'https'
            && (str_ends_with($host, '.gov.br') || $host === 'gov.br');
    }
}
