<?php
declare(strict_types=1);

require_role(['admin', 'specialist']);

$customerForm = normalize_customer_data([
    'requester_name' => 'Minta Ügyfél',
    'birth_name' => 'Minta Ügyfél',
    'company_name' => '',
    'tax_number' => '',
    'phone' => '+36 30 123 4567',
    'email' => 'ugyfel@example.com',
    'postal_address' => 'Tavasz utca 4.',
    'postal_code' => '5820',
    'city' => 'Mezőhegyes',
    'mailing_address' => '',
    'mother_name' => 'Minta Édesanya',
    'birth_place' => 'Mezőhegyes',
    'birth_date' => '1980-01-01',
    'contact_data_accepted' => 1,
]);

$form = normalize_connection_request_data([
    'request_type' => 'power_increase',
    'project_name' => 'Minta Ügyfél - árambővítés előkészítése',
    'site_address' => 'Tavasz utca 4.',
    'site_postal_code' => '5820',
    'hrsz' => '51',
    'meter_serial' => '',
    'consumption_place_id' => '',
    'existing_general_power' => 'Nem tudom, fotót töltök fel',
    'requested_general_power' => '3x25 A',
    'existing_h_tariff_power' => '',
    'requested_h_tariff_power' => '',
    'existing_controlled_power' => '',
    'requested_controlled_power' => '',
    'notes' => 'Minta megjegyzés: az ügyfél a pontos csatlakozási adatokat nem tudja, helyszínrajzi segítséget kér.',
    'work_note' => '',
], $customerForm);

$requestTypeOptions = connection_request_type_options();
$downloads = download_documents(true);
?>
<section class="admin-section customer-crm-page customer-work-request-crm-page customer-request-preview-page">
    <div class="container">
        <div class="admin-header customer-crm-hero">
            <div>
                <p class="eyebrow">Admin előnézet</p>
                <h1>Ügyfélregisztráció utáni adatbekérő</h1>
                <p>Ez az ügyféloldali igényrögzítő felület mentés nélküli előnézete. Itt látható, mire érkezik az ügyfél sikeres regisztráció után.</p>
            </div>
            <div class="form-actions">
                <a class="button" href="<?= h(url_path('/mvm-ugyintezes')); ?>">MVM oldal</a>
                <a class="button button-secondary" href="<?= h(url_path('/admin/dashboard')); ?>">Admin</a>
            </div>
        </div>

        <div class="alert alert-info">
            <p>Az előnézet nem ment adatot, nem küld emailt és nem tölt fel fájlt. A mostani éles ügyfélfolyamat továbbra is a <strong>/customer/work-request</strong> oldalon fut.</p>
            <p>A következő fejlesztési lépésben erre a felületre kerülhetnek rá a MVM-specifikus magyarázatok és a „nem tudom, Mező Energy pontosítja” jellegű segítségek.</p>
        </div>

        <?php if ($downloads !== []): ?>
            <section class="download-panel">
                <div>
                    <h2>Letölthető dokumentumok</h2>
                    <p>Az ügyfél innen látja a dokumentumtárban elérhető meghatalmazásokat, nyilatkozatokat és MVM ügyintézési sablonokat.</p>
                </div>
                <div class="inline-link-list">
                    <?php foreach ($downloads as $document): ?>
                        <a href="<?= h(url_path('/documents/file') . '?id=' . (int) $document['id']); ?>" target="_blank"><?= h($document['title']); ?></a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <form class="form preview-form" action="#" method="post" enctype="multipart/form-data" onsubmit="return false;">
            <div class="form-grid two">
                <section class="auth-panel">
                    <h2>Saját adatok</h2>
                    <label class="checkbox-row"><input type="checkbox" disabled><span>Jogi személy</span></label>
                    <label>Név</label><input value="<?= h($customerForm['requester_name']); ?>" readonly>
                    <label>Cégnév</label><input value="<?= h($customerForm['company_name']); ?>" readonly>
                    <label>Adószám</label><input value="<?= h($customerForm['tax_number']); ?>" readonly>
                    <label>Telefon</label><input value="<?= h($customerForm['phone']); ?>" readonly>
                    <label>Email</label><input type="email" value="<?= h($customerForm['email']); ?>" readonly>
                    <label>ÜK szám</label><input value="<?= h($form['mvm_uk_number']); ?>" placeholder="MVM ÜK szám" readonly>
                    <label>Postai cím</label><input value="<?= h($customerForm['postal_address']); ?>" readonly>
                    <label>Irányítószám</label><input value="<?= h($customerForm['postal_code']); ?>" readonly>
                    <label>Település</label><input value="<?= h($customerForm['city']); ?>" readonly>
                    <label>Levelezési cím</label><input value="<?= h($customerForm['mailing_address']); ?>" readonly>
                    <label>Születési név</label><input value="<?= h($customerForm['birth_name']); ?>" readonly>
                    <label>Anyja neve</label><input value="<?= h($customerForm['mother_name']); ?>" readonly>
                    <label>Születési hely</label><input value="<?= h($customerForm['birth_place']); ?>" readonly>
                    <label>Születési idő</label><input type="date" value="<?= h($customerForm['birth_date']); ?>" readonly>
                </section>

                <section class="auth-panel">
                    <h2>Igény adatai</h2>
                    <label for="preview_request_type">Igénytípus</label>
                    <select id="preview_request_type" disabled>
                        <?php foreach ($requestTypeOptions as $typeKey => $typeLabel): ?>
                            <option value="<?= h($typeKey); ?>" <?= $form['request_type'] === $typeKey ? 'selected' : ''; ?>><?= h($typeLabel); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label>Igény megnevezése</label><input value="<?= h($form['project_name']); ?>" readonly>
                    <label>Kivitelezés címe</label><input value="<?= h($form['site_address']); ?>" readonly>
                    <label>Kivitelezés irányítószáma</label><input value="<?= h($form['site_postal_code']); ?>" readonly>
                    <label>Helyrajzi szám</label><input value="<?= h($form['hrsz']); ?>" readonly>
                    <label>Saját mérő gyári száma</label><input value="<?= h($form['meter_serial']); ?>" placeholder="Ha ismert" readonly>
                    <label>Fogyasztási hely azonosító</label><input value="<?= h($form['consumption_place_id']); ?>" placeholder="Számláról leolvasható, ha van" readonly>
                </section>
            </div>

            <section class="auth-panel form-block">
                <h2>Teljesítmény adatok</h2>
                <p class="muted-text">A jelenlegi éles űrlapon ezek szabad szöveges mezők. Itt fogjuk ügyfélbarátabbá tenni a bizonytalan műszaki adatokat.</p>
                <div class="form-grid two compact">
                    <div><label>Meglévő teljesítmény mindennapszaki</label><input value="<?= h($form['existing_general_power']); ?>" readonly></div>
                    <div><label>Igényelt teljesítmény mindennapszaki</label><input value="<?= h($form['requested_general_power']); ?>" readonly></div>
                    <div><label>Meglévő teljesítmény H tarifa</label><input value="<?= h($form['existing_h_tariff_power']); ?>" readonly></div>
                    <div><label>Igényelt teljesítmény H tarifa</label><input value="<?= h($form['requested_h_tariff_power']); ?>" readonly></div>
                    <div><label>Meglévő teljesítmény vezérelt</label><input value="<?= h($form['existing_controlled_power']); ?>" readonly></div>
                    <div><label>Igényelt teljesítmény vezérelt</label><input value="<?= h($form['requested_controlled_power']); ?>" readonly></div>
                </div>
                <label>Megjegyzés</label><textarea rows="4" readonly><?= h($form['notes']); ?></textarea>
                <label>Munka megjegyzés</label><textarea rows="3" readonly><?= h($form['work_note']); ?></textarea>
            </section>

            <section class="auth-panel form-block">
                <h2>Fotók és kitöltött dokumentumok</h2>
                <p class="muted-text">Az ügyfél több részletben is tölthet fel fájlokat. A fájlmezők itt csak előnézetként látszanak.</p>

                <div class="file-upload-grid">
                    <?php foreach (connection_request_upload_definitions() as $key => $definition): ?>
                        <?php
                        $isImage = ($definition['kind'] ?? '') === 'image';
                        $accept = connection_request_upload_accept($definition);
                        $isHTariffRequired = !empty($definition['h_tariff_required']);
                        ?>
                        <label class="file-upload-item">
                            <span><?= h((string) $definition['label']); ?><?= (!empty($definition['required']) || $isHTariffRequired) ? ' *' : ''; ?></span>
                            <small><?= !empty($definition['required']) ? 'Lezáráskor mindig kötelező. Több fájl is feltölthető.' : ($isHTariffRequired ? 'H tarifa esetén kötelező, PDF vagy kép formátumban.' : 'Opcionális. Több fájl is feltölthető.'); ?></small>
                            <input type="file" accept="<?= h($accept); ?>" multiple <?= $isImage ? 'capture="environment"' : ''; ?> disabled>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>

            <div class="form-actions">
                <button class="button button-secondary" type="button" disabled>Mentés piszkozatként</button>
                <button class="button" type="button" disabled>Lezárom és beküldöm</button>
            </div>
        </form>
    </div>
</section>
