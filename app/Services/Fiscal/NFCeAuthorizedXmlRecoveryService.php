<?php

namespace App\Services\Fiscal;

use App\Models\FiscalDocumentJob;
use DOMDocument;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Recupera o XML processado sem contato com a ACBr/SEFAZ.
 * O XML com a montagem antiga permanece preservado para auditoria.
 */
final class NFCeAuthorizedXmlRecoveryService
{
    public function __construct(
        private readonly NFCeProtocolXmlService $xml,
        private readonly NFCeSefazResponseParser $responses,
    ) {}

    public function recover(FiscalDocumentJob $document): void
    {
        $lock = Cache::lock('nextor:nfce:'.$document->id, 60);
        if (!$lock->get()) {
            throw new RuntimeException('Documento fiscal ocupado por outro processamento.');
        }

        try {
            $job = FiscalDocumentJob::query()->findOrFail($document->id);

            if ($job->document_type !== 'nfce' || $job->status !== 'authorized'
                || !$job->access_key || !$job->protocol || !$job->authorized_at) {
                throw new RuntimeException('Recuperação disponível apenas para NFC-e já autorizada.');
            }

            $dir = 'fiscal/nfce/'.$job->id;
            $disk = Storage::disk('local');
            $signedPath = $dir.'/signed.xml';
            $responsePath = $dir.'/sefaz-response.ini';
            if (!$disk->exists($signedPath) || !$disk->exists($responsePath)) {
                throw new RuntimeException('Faltam o XML originalmente assinado ou o retorno original da SEFAZ.');
            }

            $signed = $disk->get($signedPath);
            $key = $this->xml->verifySigned($signed, $job);
            $response = $this->responses->parse($disk->get($responsePath));
            if (!$this->responses->authorized($response)
                || !hash_equals((string) $job->access_key, $key)
                || !hash_equals($key, (string) $response['key'])
                || !hash_equals((string) $job->protocol, (string) $response['protocol'])
                || ($response['environment'] ?? null) !== ($job->environment === 'homologation' ? '2' : '1')) {
                throw new RuntimeException('A autorização arquivada não corresponde à chave, protocolo ou ambiente deste documento.');
            }

            // O digest retornado no protocolo precisa coincidir com o da
            // assinatura original. Isso impede mesclar documentos distintos.
            $signedDom = new DOMDocument();
            if (!@$signedDom->loadXML($signed, LIBXML_NONET)) {
                throw new RuntimeException('XML originalmente assinado inválido.');
            }
            $digest = $signedDom->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#', 'DigestValue')->item(0)?->textContent;
            if (!$digest || !hash_equals(trim($digest), (string) ($response['digest'] ?? ''))) {
                throw new RuntimeException('Digest do XML original difere do protocolo da SEFAZ.');
            }

            // Preservar o XML antigo; a recuperação não apaga evidências da
            // primeira montagem. O builder também compara C14N antes/depois.
            $rebuilt = $this->xml->buildAuthorized($signed, $response);
            $path = $dir.'/authorized-recovered.xml';

            if (!$disk->put($path, $rebuilt)) {
                throw new RuntimeException('Não foi possível guardar o XML recuperado.');
            }

            $job->update(['xml_path' => $path, 'error_message' => null]);
        } finally {
            $lock->release();
        }
    }
}
