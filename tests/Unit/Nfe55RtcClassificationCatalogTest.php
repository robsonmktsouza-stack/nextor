<?php

namespace Tests\Unit;

use App\Services\Fiscal\Nfe55RtcClassificationCatalog;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Nfe55RtcClassificationCatalogTest extends TestCase
{
    public function test_existing_integral_and_zero_rate_profiles_are_supported_by_official_flags(): void
    {
        $catalog = new Nfe55RtcClassificationCatalog();
        $integral = $catalog->validate('000', '000001', '2026-10-25');
        $zero = $catalog->validate('200', '200003', '2026-10-25');
        self::assertTrue($integral['cst_flags']['IndExigeTrib']);
        self::assertFalse($integral['cst_flags']['IndReducaoAliq']);
        self::assertSame('0.00', $integral['ibs_reduction']);
        self::assertTrue($zero['cst_flags']['IndReducaoAliq']);
        self::assertSame('100.00', $zero['ibs_reduction']);
        self::assertSame('100.00', $zero['cbs_reduction']);
        self::assertSame('200003', $zero['classification']);
    }

    public function test_export_classification_does_not_require_ordinary_ibs_cbs_groups(): void
    {
        $record = (new Nfe55RtcClassificationCatalog())->validate('410', '410004', '2026-10-25');
        self::assertTrue($record['ind_nfe']);
        self::assertFalse($record['cst_flags']['IndExigeTrib']);
        self::assertSame(3, $record['rate_type']);
        self::assertStringEndsWith('#art8', $record['legal_reference']['url']);
    }

    public function test_deferral_and_reduced_deferral_are_distinct_official_profiles(): void
    {
        $catalog = new Nfe55RtcClassificationCatalog();
        $deferral = $catalog->validate('510', '510001', '2026-10-25');
        $reduced = $catalog->validate('515', '515001', '2026-10-25');
        self::assertTrue($deferral['cst_flags']['IndDiferimento']);
        self::assertFalse($deferral['cst_flags']['IndReducaoAliq']);
        self::assertSame(3, $deferral['rate_type']);
        self::assertTrue($reduced['cst_flags']['IndDiferimento']);
        self::assertTrue($reduced['cst_flags']['IndReducaoAliq']);
        self::assertSame('60.00', $reduced['ibs_reduction']);
        self::assertSame('60.00', $reduced['cbs_reduction']);
    }

    public function test_reduction_is_read_from_the_specific_class_and_not_inferred_from_cst_alone(): void
    {
        $record = (new Nfe55RtcClassificationCatalog())->validate('200', '200033', '2026-10-25');
        self::assertSame('60.00', $record['ibs_reduction']);
        self::assertSame('60.00', $record['cbs_reduction']);
    }

    public function test_cst_and_class_must_form_an_exact_official_pair(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('par CST/classificação');
        (new Nfe55RtcClassificationCatalog())->validate('000', '200003', '2026-10-25');
    }

    public function test_a_classification_allowed_for_another_document_cannot_be_used_in_nfe(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('não permitida para NF-e');
        (new Nfe55RtcClassificationCatalog())->validate('000', '000002', '2026-10-25');
    }

    public function test_an_expired_official_record_is_never_prolonged_to_the_query_year(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fora da vigência');
        (new Nfe55RtcClassificationCatalog())->validate('220', '220001', '2026-10-25');
    }

    public function test_issue_date_must_not_precede_the_class_start_even_if_the_parent_cst_exists(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fora da vigência');
        (new Nfe55RtcClassificationCatalog())->validate('000', '000005', '2025-12-31');
    }

    public function test_official_start_day_is_inclusive(): void
    {
        self::assertSame('000005', (new Nfe55RtcClassificationCatalog())
            ->validate('000', '000005', '2026-01-01')['classification']);
    }

    public function test_parent_expiry_is_enforced_even_for_an_open_ended_class(): void
    {
        $dataset = json_decode(file_get_contents(dirname(__DIR__, 2).'/resources/fiscal/rtc-classifications.json'), true, 512, JSON_THROW_ON_ERROR);
        $dataset['csts']['000']['valid_until'] = '2026-10-25T00:00:00';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CST fora da vigência');
        (new Nfe55RtcClassificationCatalog($dataset))->validate('000', '000001', '2026-10-25');
    }

    public function test_invalid_or_unknown_codes_and_dates_fail_closed(): void
    {
        foreach ([['00', '000001', '2026-10-25'], ['000', '00001', '2026-10-25'],
            ['999', '999999', '2026-10-25'], ['000', '000001', '2026-02-30'],
            ['000', '000001', '25/10/2026']] as [$cst, $classification, $date]) {
            try {
                (new Nfe55RtcClassificationCatalog())->validate($cst, $classification, $date);
                self::fail('Classificação ou data inválida aceita.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('RTC:', $error->getMessage());
            }
        }
    }

    public function test_missing_official_group_flags_cannot_be_interpreted_as_false(): void
    {
        $dataset = json_decode(file_get_contents(dirname(__DIR__, 2).'/resources/fiscal/rtc-classifications.json'), true, 512, JSON_THROW_ON_ERROR);
        unset($dataset['csts']['000']['flags']['IndExigeTrib']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('IndExigeTrib ausente');
        (new Nfe55RtcClassificationCatalog($dataset))->validate('000', '000001', '2026-10-25');
    }

    public function test_snapshot_metadata_does_not_claim_the_documents_page_version_as_the_table_version(): void
    {
        $metadata = (new Nfe55RtcClassificationCatalog())->metadata();
        self::assertNull($metadata['source_version']);
        self::assertSame('2026-10-25', $metadata['retrieved_on']);
        self::assertSame(18, $metadata['cst_count']);
        self::assertSame(173, $metadata['classification_count']);
        self::assertSame('1923c4aff1345b054cfcb027449c3608838a3bbdeda6f1b578da3eb36e28a68c', $metadata['source_sha256']);
    }
}
