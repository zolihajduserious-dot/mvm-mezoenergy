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
$outputPath = __DIR__ . '/.tmp_blank_overlay_test.pdf';
$values = [
    'felhasznalasi_cim' => '5820 Mezohegyes, Tavasz u. 4.',
    'iranyitoszam2' => '5820',
    'nev' => 'Hajdu Marcell',
    'fha' => '0400123654',
    'szuletesi_ido' => '2011.03.27.',
    'adoszam2' => '06201654941',
    'szuletesi_hely' => 'Szeged',
    'anyja_neve' => 'Garamvolgyi Rita',
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
    'szekreny_tipusa' => 'Csatari 3045',
    'szekreny_brutto_egysegar' => '',
    'jelenlegi_meroszekreny' => '',
    'szekreny_felulvizsgalati_dij' => '12 573 Ft',
    'legvezetekes_tobbletkoltseg' => '',
    'muszaki_tobbletkoltseg' => '',
    'n425f' => '',
    'n435f' => '',
    'n450f' => '',
    'n216' => '',
    'n416' => '',
    'n225' => 'X',
    'n425' => '',
];

$pdf = new \setasign\Fpdi\Fpdi();
$pdf->setSourceFile($templatePath);
$templateId = $pdf->importPage(1);
$size = $pdf->getTemplateSize($templateId);
$pdf->AddPage('P', [(float) $size['width'], (float) $size['height']]);
$pdf->useTemplate($templateId);
$temporaryFiles = [];
$cover = mvm_pdf_template_requires_cover($templatePath);

foreach (mvm_pdf_overlay_fields($values) as $field) {
    if ((int) $field['page'] !== 1) {
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
        $cover
    );
}

$pdf->Output('F', $outputPath);
foreach ($temporaryFiles as $temporaryFile) {
    if (is_file($temporaryFile)) {
        unlink($temporaryFile);
    }
}
echo $outputPath . PHP_EOL;
