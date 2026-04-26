<?php
declare(strict_types=1);

require __DIR__ . '/public_html/includes/config.php';
$vendorAutoload = APP_ROOT . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require $vendorAutoload;
}
require __DIR__ . '/public_html/includes/functions.php';
require __DIR__ . '/public_html/includes/crm.php';

$templatePath = mvm_pdf_template_path();
$outputPath = __DIR__ . '/.tmp_blank_overlay_all_test.pdf';
$values = [
    'felhasznalasi_cim' => '5820 Mezohegyes, Tavasz u. 4.',
    'iranyitoszam2' => '5820',
    'nev' => 'Hajdu Marcell',
    'fha' => '0400123654',
    'szuletesi_ido' => '2011.03.27.',
    'szuletesi_ideje' => '2011.03.27.',
    'adoszam2' => '06201654941',
    'szuletesi_hely' => 'Szeged',
    'szuletesi_helye' => 'Szeged',
    'anyja_neve' => 'Garamvolgyi Rita',
    'anyja_neve_cegjegyzekszam' => 'Garamvolgyi Rita',
    'cegjegyzekszam' => '',
    'ugyfel_lakcime' => '5820 Mezohegyes, Tavasz u. 4.',
    'iranyito_szam' => '5820',
    'telefon' => '06301654941',
    'mt' => '3 fazisra atallas',
    'uj_fogyaszto' => '',
    'fizetendo_teljesitmeny2' => '',
    'fo' => '',
    'phase_upgrade' => 'X',
    'egyedi_merohely_felulvizsgalat' => '',
    'hmke_bekapcsolas' => '',
    'h_tarifa_vagy_melleszereles' => '',
    'csak_kismegszakitocsere' => '',
    'kozvilagitas_merohely_letesites' => '',
    'csatlakozo_berendezes_helyreallitasa' => '',
    'foldkabeles' => '',
    'legvezetekes' => 'X',
    'lakossagi_fogyaszto' => 'X',
    'nem_lakossagi_fogyaszto' => '',
    'n13' => '',
    'n216' => '',
    'n416' => '',
    'n225' => 'X',
    'n425' => '',
    'n425f' => '',
    'n435f' => '',
    'n450f' => '',
    'szekreny_tipusa' => 'Csatari 3045',
    'szekreny_brutto_egysegar' => '',
    'jelenlegi_meroszekreny' => 'Meglevo meroszekreny',
    'szekreny_felulvizsgalati_dij' => '12 573 Ft',
    'jml1' => '16',
    'jml2' => '',
    'jml3' => '',
    'iml1' => '16',
    'iml2' => '16',
    'iml3' => '16',
    'jelenlegi_hl1' => '',
    'jelenlegi_hl2' => '',
    'jelenlegi_hl3' => '',
    'ihl1' => '',
    'ihl2' => '',
    'ihl3' => '',
    'jvl1' => '',
    'jvl2' => '',
    'jvl3' => '',
    'ivl1' => '',
    'ivl2' => '',
    'ivl3' => '',
    'igenyelt_osszes_teljesitmeny' => '3x16',
    'osszes_igenyelt_h_teljesitmeny' => '',
    'mindennapszaki_hafhoz_32_vagy_tobb' => '',
    'sc' => '',
    'tn' => '',
    'ot' => '',
    'ofo' => '',
    'ohfk' => '',
    'ofosz' => '',
    'otvez' => '',
    'oszlop_tipusa' => 'Betonoszlop',
    'tetotarto_hossz' => '3 m',
    'ossz_kabelhossz' => '18 m',
    'vizszintes_kabelhossz_m' => '12 m',
    'foldkabel_tobletkoltseg' => '',
    'legvezetekes_tobbletkoltseg' => '',
    'muszaki_tobbletkoltseg' => '',
    'meroora_helye_jelenleg' => 'Nullazas',
    'laed' => '',
    'mgyszv' => '',
    'mogysz' => '',
    'szfd' => '',
    'n7es_pont_nev' => 'Hajdu Marcell',
    'n7es_pont_cim' => '5820 Mezohegyes, Tavasz u. 4.',
    'kockas_papir_vezetek' => 'Legvezetek',
    'kockas_papir_szekreny' => 'Meroszekreny',
    'datum' => '2026.04.24.',
    'rnev' => 'Hajdu Marcell',
    'varos' => 'Mezohegyes',
    'varos2' => 'Mezohegyes',
    'utca' => 'Tavasz u.',
    'hazszam' => '4.',
    'helyrajzi_szam' => '51',
];

$pdf = new \setasign\Fpdi\Fpdi();
$pageCount = $pdf->setSourceFile($templatePath);
$fields = mvm_pdf_overlay_fields($values);
$temporaryFiles = [];
$cover = mvm_pdf_template_requires_cover($templatePath);

for ($page = 1; $page <= $pageCount; $page++) {
    $templateId = $pdf->importPage($page);
    $size = $pdf->getTemplateSize($templateId);
    $pdf->AddPage(((float) $size['width'] > (float) $size['height']) ? 'L' : 'P', [(float) $size['width'], (float) $size['height']]);
    $pdf->useTemplate($templateId);

    foreach ($fields as $field) {
        if ((int) $field['page'] !== $page) {
            continue;
        }

        add_mvm_pdf_text(
            $pdf,
            (string) $field['text'],
            (float) $field['x'],
            (float) $field['y'],
            (float) $field['w'],
            (float) $field['h'],
            (int) $field['size'],
            $temporaryFiles,
            $cover || !empty($field['cover'])
        );
    }
}

$pdf->Output('F', $outputPath);

foreach ($temporaryFiles as $temporaryFile) {
    if (is_file($temporaryFile)) {
        unlink($temporaryFile);
    }
}

echo $outputPath . PHP_EOL;
