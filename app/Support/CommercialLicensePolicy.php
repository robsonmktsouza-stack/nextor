<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Regras do modo de consulta comercial. Nunca tomar decisões de autorização
 * exclusivamente na interface: todo comando de escrita passa pelo middleware.
 */
final class CommercialLicensePolicy
{
    public const CONTINUITY_ROUTES = [
        // Download de XML já autorizado (POST gera arquivo, sem mutar dados).
        'fiscal.utilities.xml',
        // Regularização de documentos e eventos fiscais existentes.
        'fiscal.nfce.consult',
        'fiscal.nfce.cancel',
        'fiscal.nfce.offline.transmit',
        'fiscal.nfce.recover-xml',
        'fiscal.nfce.inutilizations.store',
        'pdv.nfce.cancel',
    ];

    public static function isReadOnly(array $state): bool
    {
        return in_array($state['status'] ?? 'disabled', ['suspended', 'expired', 'unverified'], true);
    }

    public static function canWrite(Request $request): bool
    {
        if ($request->isMethodSafe()) {
            return true;
        }

        // Encerrar sessão nunca pode depender da validade comercial.
        if ($request->routeIs('logout')) {
            return true;
        }

        // Apenas exceções legais explícitas, jamais fiscal.* ou pdv/nfce/*.
        return $request->routeIs(...self::CONTINUITY_ROUTES);
    }

    public static function title(string $status): string
    {
        return match ($status) {
            'suspended' => 'Assinatura suspensa',
            'expired' => 'Assinatura vencida',
            default => 'Verificação de licença indisponível',
        };
    }

    public static function explanation(string $status): string
    {
        return match ($status) {
            'suspended' => 'Esta assinatura está suspensa. Você pode consultar os dados existentes, mas não pode realizar operações comerciais até a reativação.',
            'expired' => 'O prazo desta assinatura terminou. As consultas continuam disponíveis, mas novas operações precisam da renovação.',
            default => 'Não foi possível confirmar a licença após o prazo de tolerância sem comunicação. As consultas estão disponíveis até a regularização da conexão.',
        };
    }

    public static function deniedOperationMessage(string $status): string
    {
        return match ($status) {
            'suspended' => 'Não é possível salvar ou realizar esta operação enquanto a assinatura está suspensa. Seus registros continuam disponíveis para consulta.',
            'expired' => 'Não é possível salvar ou realizar esta operação com a assinatura vencida. Seus registros continuam disponíveis para consulta.',
            default => 'Não é possível alterar dados enquanto a licença não puder ser confirmada. Seus registros continuam disponíveis para consulta.',
        };
    }
}
