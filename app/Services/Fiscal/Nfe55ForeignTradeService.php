<?php

namespace App\Services\Fiscal;

use App\Models\CompanySetting;
use App\Models\NfeDraft;
use DateTimeImmutable;
use RuntimeException;

/** Direct import/export only. Rates and legal bases are explicitly approved inputs. */
final class Nfe55ForeignTradeService
{
    private const UFS = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];

    /** @return array{is_foreign:bool,kind:?string,sections:array,ii_total_cents:int,item_ii_cents:array} */
    public function validate(NfeDraft $draft, CompanySetting $company): array
    {
        $draft->loadMissing('items');
        $foreign = $draft->destination === 'foreign';
        $header = data_get($draft->custom_fields, 'foreign_trade', []);
        if (!is_array($header)) throw new RuntimeException('Dados de comércio exterior inválidos.');
        $result = ['is_foreign'=>$foreign,'kind'=>null,'sections'=>[],'ii_total_cents'=>0,'item_ii_cents'=>[]];
        if (!$foreign) {
            if ($this->filled($header)) throw new RuntimeException('Dados de exportação não podem ser usados em uma operação nacional.');
            foreach ($draft->items as $item) {
                $special = $item->special_data ?? [];
                foreach (['import_declarations','import_taxes','export_details'] as $key) {
                    if ($this->filled($special[$key] ?? null)) throw new RuntimeException('Grupo de comércio exterior informado em operação nacional.');
                }
            }
            return $result;
        }
        if (!in_array($draft->operation_type, ['outbound','inbound'], true) || $draft->purpose !== 'normal') {
            throw new RuntimeException('Comércio exterior disponível somente para importação ou exportação direta com finalidade normal.');
        }
        $kind = $draft->operation_type === 'outbound' ? 'export' : 'import';
        $result['kind'] = $kind;
        if ($draft->items->isEmpty()) throw new RuntimeException('Adicione os itens da operação de comércio exterior.');
        if (data_get($draft->custom_fields, 'fiscal_operation') !== $kind) {
            throw new RuntimeException('Selecione a operação fiscal específica de importação ou exportação direta.');
        }
        $recipient = $draft->recipient_snapshot ?? [];
        $country = (string) ($recipient['country_code'] ?? '');
        $foreignId = trim((string) ($recipient['foreign_id'] ?? ''));
        if (($recipient['state'] ?? '') !== 'EX' || !preg_match('/^\d{4}$/D', $country)
            || in_array($country, ['0000','1058'], true) || trim((string) ($recipient['country_name'] ?? '')) === ''
            || strlen($foreignId) < 5 || strlen($foreignId) > 20 || preg_match('/[\x00-\x1F\x7F]/', $foreignId)) {
            throw new RuntimeException('Destinatário estrangeiro exige UF EX, país estrangeiro e identificação de 5 a 20 caracteres.');
        }
        if ($kind === 'export') {
            $this->assertKnownKeys($header, ['export_exit_uf','export_exit_location','export_dispatch_location'], 'Exportação');
            $exitUf = (string) ($header['export_exit_uf'] ?? '');
            if (!in_array($exitUf, self::UFS, true)) throw new RuntimeException('Informe a UF brasileira de saída do país.');
            $fields = ['UFSaidaPais'=>$exitUf,
                'xLocExporta'=>$this->text($header['export_exit_location'] ?? null, 60, 'Local de exportação')];
            if ($this->filled($header['export_dispatch_location'] ?? null)) {
                $fields['xLocDespacho'] = $this->text($header['export_dispatch_location'],60,'Local de despacho');
            }
            $result['sections'][] = ['name'=>'Exporta','fields'=>$fields];
        } elseif ($this->filled($header)) {
            throw new RuntimeException('Importação direta não utiliza o cabeçalho de exportação.');
        }
        if ($kind === 'import' && in_array((string) $company->crt, ['1','4'], true)) {
            throw new RuntimeException('Importação pelo Simples Nacional exige tratamento específico do ICMS fora do DAS, ainda não implementado neste fluxo.');
        }
        foreach ($draft->items->values() as $index => $item) {
            $number = $index + 1;
            $tax = Nfe55TaxParameters::forDirection($item->tax_data ?? [], (string)$draft->operation_type);
            $trace = $tax['fiscal_rule_trace'] ?? [];
            if (!is_array($trace) || empty($trace['legal_source']) || empty($trace['id'])
                || empty($trace['hash']) || data_get($trace, 'snapshot.fiscal_operation') !== $kind) {
                throw new RuntimeException('Item '.$number.': comércio exterior exige regra específica vigente com fundamento legal rastreável.');
            }
            $allowedCfops = $kind === 'export' ? ['7101','7102'] : ['3101','3102'];
            if (!in_array((string)$item->cfop, $allowedCfops, true)) {
                throw new RuntimeException('Item '.$number.': CFOP sem tratamento de comércio exterior direto neste emissor.');
            }
            $special = is_array($item->special_data) ? $item->special_data : [];
            if ($this->filled($special['export_details'] ?? null)) {
                throw new RuntimeException('Detalhes de exportação indireta, drawback e registro de exportação exigem tratamento próprio ainda não implementado.');
            }
            if ($kind === 'export') {
                if ($this->filled($special['import_declarations'] ?? null) || $this->filled($special['import_taxes'] ?? null)) {
                    throw new RuntimeException('Exportação direta não utiliza DI ou Imposto de Importação.');
                }
                continue;
            }
            $this->assertExplicitImportBases($tax, $number);
            $importTaxes = $this->calculateImportTaxes($special);
            $result['sections'][] = ['name'=>'II'.sprintf('%03d',$number),'fields'=>$importTaxes['fields']];
            $result['ii_total_cents'] += $importTaxes['ii_amount'];
            $result['item_ii_cents'][$number] = $importTaxes['ii_amount'];
            $declarations = $special['import_declarations'] ?? null;
            if (!is_array($declarations) || !array_is_list($declarations) || count($declarations) < 1 || count($declarations) > 100) {
                throw new RuntimeException('Item '.$number.': informe de 1 a 100 declarações de importação.');
            }
            $identifiers = [];
            foreach ($declarations as $diIndex => $declaration) {
                if (!is_array($declaration)) throw new RuntimeException('Declaração de importação inválida.');
                $fields = $this->declarationFields($declaration, $draft->issue_date?->format('Y-m-d') ?? now()->toDateString());
                if (isset($identifiers[$fields['nDi']])) throw new RuntimeException('Declaração de importação duplicada no mesmo item.');
                $identifiers[$fields['nDi']] = true;
                $diSuffix = sprintf('%03d%03d', $number, $diIndex + 1);
                $result['sections'][] = ['name'=>'DI'.$diSuffix,'fields'=>$fields];
                $additions = $declaration['additions'] ?? null;
                if (!is_array($additions) || !array_is_list($additions) || count($additions) < 1 || count($additions) > 100) {
                    throw new RuntimeException('Cada DI exige de 1 a 100 adições discriminadas por fabricante.');
                }
                $sequences = [];
                foreach ($additions as $additionIndex => $addition) {
                    if (!is_array($addition)) throw new RuntimeException('Adição de importação inválida.');
                    $fields = $this->additionFields($addition);
                    $identifier = $fields['nAdicao'].':'.$fields['nSeqAdi'];
                    if (isset($sequences[$identifier])) throw new RuntimeException('Número e sequência de adição duplicados na DI.');
                    $sequences[$identifier] = true;
                    $result['sections'][] = ['name'=>'LADI'.$diSuffix.sprintf('%03d',$additionIndex+1),'fields'=>$fields];
                }
            }
        }
        return $result;
    }

    /** Monetary output in cents; customs expenses/IOF are disclosed, never added twice to vNF. */
    public function calculateImportTaxes(array $special): array
    {
        $tax = $special['import_taxes'] ?? null;
        if (!is_array($tax) || !$this->filled($tax)) throw new RuntimeException('Informe os parâmetros explícitos do Imposto de Importação.');
        $this->assertKnownKeys($tax, ['base','rate','customs_expenses','ii_amount','iof_amount'], 'Imposto de Importação');
        $base = $this->amount($tax['base'] ?? null, 'Base do Imposto de Importação');
        // II is serialized as an amount; no universal legal rate ceiling is presumed.
        $rate = NfeTaxMath::scaled($tax['rate'] ?? null, 4, 'Alíquota do Imposto de Importação');
        $amount = NfeTaxMath::product($base, $rate, 6);
        if ($amount > 99999999999) throw new RuntimeException('Imposto de Importação superior ao limite monetário deste emissor.');
        if ($this->filled($tax['ii_amount'] ?? null) && $amount !== $this->amount($tax['ii_amount'], 'II informado')) {
            throw new RuntimeException('Imposto de Importação informado diverge do cálculo da base e alíquota.');
        }
        return ['fields'=>[
            'vBC'=>NfeTaxMath::format($base),
            'vDespAdu'=>NfeTaxMath::format($this->amount($tax['customs_expenses'] ?? null, 'Despesas aduaneiras')),
            'vII'=>NfeTaxMath::format($amount),
            'vIOF'=>NfeTaxMath::format($this->amount($tax['iof_amount'] ?? null, 'IOF da importação')),
        ], 'ii_amount'=>$amount];
    }

    private function declarationFields(array $di, string $issueDate): array
    {
        $this->assertKnownKeys($di, ['number','date','clearance_location','clearance_uf','clearance_date',
            'transport_type','afrmm_value','intermediation_type','third_party_cnpj','third_party_uf','exporter_code','additions'], 'DI');
        $number = (string) ($di['number'] ?? '');
        if (!preg_match('/^[A-Za-z0-9.\/-]{1,12}$/D', $number)) throw new RuntimeException('Número da DI inválido. DUIMP não está implementada neste fluxo.');
        $registered = $this->date($di['date'] ?? null, 'Data de registro da DI');
        $cleared = $this->date($di['clearance_date'] ?? null, 'Data de desembaraço');
        if ($registered > $cleared || $cleared->format('Y-m-d') > $issueDate) throw new RuntimeException('Confira a sequência das datas de registro, desembaraço e emissão.');
        $uf = (string) ($di['clearance_uf'] ?? '');
        if (!in_array($uf, self::UFS, true)) throw new RuntimeException('UF do desembaraço inválida.');
        $transport = (string) ($di['transport_type'] ?? '');
        if (!in_array($transport, ['1','4','7'], true)) throw new RuntimeException('Via de importação disponível: marítima, aérea ou rodoviária.');
        if ((string) ($di['intermediation_type'] ?? '') !== '1'
            || $this->filled($di['third_party_cnpj'] ?? null) || $this->filled($di['third_party_uf'] ?? null)) {
            throw new RuntimeException('Importação por conta e ordem ou encomenda exige terceiros e grupos próprios ainda não implementados.');
        }
        $fields = ['nDi'=>$number,'dDi'=>$registered->format('d/m/Y'),
            'xLocDesemb'=>$this->text($di['clearance_location'] ?? null, 60, 'Local do desembaraço'),
            'UFDesemb'=>$uf,'dDesemb'=>$cleared->format('d/m/Y'),'tpViaTransp'=>$transport,
            'tpIntermedio'=>'1','cExportador'=>$this->text($di['exporter_code'] ?? null, 60, 'Código do exportador')];
        if ($transport === '1') $fields['vAFRMM'] = NfeTaxMath::format($this->amount($di['afrmm_value'] ?? null, 'AFRMM'));
        elseif ($this->filled($di['afrmm_value'] ?? null) && $this->amount($di['afrmm_value'], 'AFRMM') !== 0) throw new RuntimeException('AFRMM só se aplica à via marítima neste fluxo.');
        return $fields;
    }

    private function additionFields(array $addition): array
    {
        $this->assertKnownKeys($addition, ['number','sequence','manufacturer_code','discount','drawback'], 'Adição da DI');
        foreach (['number','sequence'] as $key) {
            if (!preg_match('/^\d{1,3}$/D',(string)($addition[$key]??'')) || (int)$addition[$key] < 1) throw new RuntimeException('Informe número e sequência de adição entre 1 e 999.');
        }
        $fields = ['nAdicao'=>(int)$addition['number'],'nSeqAdi'=>(int)$addition['sequence'],
            'cFabricante'=>$this->text($addition['manufacturer_code'] ?? null,60,'Código do fabricante')];
        if ($this->filled($addition['discount'] ?? null)) $fields['vDescDI'] = NfeTaxMath::format($this->amount($addition['discount'], 'Desconto da DI'));
        if ($this->filled($addition['drawback'] ?? null)) {
            if (!preg_match('/^\d{1,20}$/D',(string)$addition['drawback'])) throw new RuntimeException('Número de drawback inválido.');
            $fields['nDraw'] = (string)$addition['drawback'];
        }
        return $fields;
    }

    private function assertExplicitImportBases(array $tax, int $number): void
    {
        foreach (['icms','ipi','pis','cofins'] as $name) {
            $code = (string) ($tax[$name.'_cst'] ?? '');
            $taxable = match ($name) {
                'icms'=>in_array($code,['00','10','20','51','70','90'],true),
                'ipi'=>in_array($code,['00','49','50','99'],true),
                default=>in_array($code,['01','02','49','99'],true),
            };
            if ($taxable && (!array_key_exists($name.'_base', $tax) || $tax[$name.'_base'] === null || $tax[$name.'_base'] === '')) {
                throw new RuntimeException('Item '.$number.': importação exige base expressamente aprovada de '.strtoupper($name).'; II, despesas e ICMS por dentro não podem ser presumidos.');
            }
        }
    }

    private function date(mixed $value, string $label): DateTimeImmutable
    {
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if (!$date || $date->format('Y-m-d') !== $value) throw new RuntimeException($label.' inválida.');
        return $date;
    }

    private function text(mixed $value, int $limit, string $label): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > $limit || preg_match('/[\x00-\x1F\x7F]/', $value)) throw new RuntimeException($label.' inválido.');
        return trim($value);
    }

    private function amount(mixed $value, string $label): int
    {
        $amount = NfeTaxMath::scaled($value,2,$label);
        if ($amount > 99999999999) throw new RuntimeException($label.': valor superior ao limite monetário deste emissor.');
        return $amount;
    }

    private function assertKnownKeys(array $data, array $allowed, string $label): void
    {
        foreach ($data as $key=>$value) {
            if (!in_array($key,$allowed,true) && $this->filled($value)) throw new RuntimeException($label.': campo sem tratamento implementado: '.$key.'.');
        }
    }

    private function filled(mixed $value): bool
    {
        if (is_array($value)) return count(array_filter($value, fn($child)=>$this->filled($child))) > 0;
        return $value !== null && $value !== '';
    }
}
