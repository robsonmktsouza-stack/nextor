<?php

namespace App\Services\Fiscal;

use App\Models\FiscalDocumentJob;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class NFCeFiscalDataRefreshService
{
    /**
     * Recarrega somente os dados fiscais dos produtos cadastrados.
     * Não altera venda, financeiro, estoque, itens, preços, pagamentos,
     * série, número ou ambiente da NFC-e já reservada.
     *
     * Proibido depois que a nota começou a ser assinada/transmitida.
     */
    public function refresh(FiscalDocumentJob $document): void
    {
        DB::transaction(function () use ($document): void {
            $job = FiscalDocumentJob::query()
                ->lockForUpdate()
                ->findOrFail($document->getKey());

            if ($job->document_type !== 'nfce'
                || $job->status !== 'prepared'
                || $job->access_key
                || $job->protocol
                || $job->xml_path
                || $job->response_path
                || $job->authorized_at
            ) {
                throw ValidationException::withMessages([
                    'nfce' => 'Só é permitido atualizar os dados fiscais de uma NFC-e preparada e nunca transmitida.',
                ]);
            }

            if (!$job->sale || $job->sale->status !== 'completed') {
                throw ValidationException::withMessages([
                    'nfce' => 'A venda vinculada precisa estar concluída.',
                ]);
            }

            $snapshot = $job->source_snapshot ?? [];
            $items = $snapshot['items'] ?? null;
            if (!is_array($items) || $items === []) {
                throw ValidationException::withMessages([
                    'nfce' => 'Documento fiscal sem itens de produtos.',
                ]);
            }

            $ids = collect($items)->pluck('product_id')->filter()->unique()->all();
            $products = Product::query()->whereIn('id', $ids)->get()->keyBy('id');

            foreach ($items as $index => &$item) {
                $id = $item['product_id'] ?? null;
                $product = $id ? $products->get($id) : null;
                if (($item['item_type'] ?? '') !== 'product' || !$product) {
                    throw ValidationException::withMessages([
                        'nfce' => 'Item '.($index + 1).': produto cadastrado não localizado. Não foi possível atualizar a nota.',
                    ]);
                }

                // Nunca copie novamente os dados comerciais da venda: somente
                // classificação e tributação para gerar o XML fiscal.
                $item['ncm'] = $product->ncm;
                $item['cest'] = $product->cest;
                $item['origin'] = $product->origin;
                $item['gtin'] = $product->ean_gtin;
                $item['tax_defaults'] = $product->tax_defaults ?? [];
            }
            unset($item);

            $snapshot['items'] = $items;
            $job->update([
                'source_snapshot' => $snapshot,
                'error_message' => null,
            ]);
        }, 3);
    }
}
