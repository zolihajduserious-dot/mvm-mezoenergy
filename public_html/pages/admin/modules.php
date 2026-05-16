<?php
declare(strict_types=1);

require_role(['admin']);

if (!can_manage_ui_modules()) {
    http_response_code(403);
    exit('Nincs jogosultságod a CRM testreszabás megnyitásához.');
}

function module_admin_input(string $key, int $limit = 190): ?string
{
    $value = trim((string) ($_POST[$key] ?? ''));

    if ($value === '') {
        return null;
    }

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $limit);
    }

    return substr($value, 0, $limit);
}

function module_admin_body_input(string $key): ?string
{
    return module_admin_input($key, 2000);
}

function module_admin_base_path(): string
{
    return '/admin/crm-customization';
}

function module_admin_redirect(string $areaKey, string $anchorKey = '', string $anchorPrefix = 'module'): never
{
    $target = module_admin_base_path() . '?area=' . rawurlencode($areaKey);

    if ($anchorKey !== '') {
        $target .= '#' . $anchorPrefix . '-' . rawurlencode($anchorKey);
    }

    redirect($target);
}

$areas = ui_module_areas();
$selectedArea = (string) ($_GET['area'] ?? array_key_first($areas));

if (!ui_module_valid_area($selectedArea)) {
    $selectedArea = (string) array_key_first($areas);
}

$schemaErrors = crm_customization_schema_errors();
$flash = get_flash();
$baseUrl = url_path(module_admin_base_path());
$fieldTypeOptions = [
    'text' => 'Szöveg',
    'textarea' => 'Hosszú szöveg',
    'select' => 'Választó',
    'checkbox' => 'Jelölőnégyzet',
    'date' => 'Dátum',
    'number' => 'Szám',
    'email' => 'Email',
    'tel' => 'Telefon',
    'file' => 'Fájl',
    'image' => 'Kép',
    'section' => 'Szekciócím',
];

if (is_post() && $schemaErrors === []) {
    require_valid_csrf_token();

    $action = (string) ($_POST['action'] ?? '');
    $areaKey = (string) ($_POST['area_key'] ?? $selectedArea);
    $moduleKey = (string) ($_POST['module_key'] ?? '');
    $fieldKey = (string) ($_POST['field_key'] ?? '');
    $postErrors = [];

    if (!ui_module_valid_area($areaKey)) {
        $postErrors[] = 'Érvénytelen CRM terület.';
    }

    try {
        if ($postErrors === [] && $action === 'move_module') {
            ui_module_move($areaKey, $moduleKey, (string) ($_POST['direction'] ?? ''));
            set_flash('success', 'A modul sorrendje frissült.');
            module_admin_redirect($areaKey, $moduleKey);
        }

        if ($postErrors === [] && $action === 'delete_custom_module') {
            ui_module_delete_custom($areaKey, $moduleKey);
            set_flash('success', 'Az egyedi modul törölve lett.');
            module_admin_redirect($areaKey);
        }

        if ($postErrors === [] && in_array($action, ['save_module', 'create_custom_module'], true)) {
            $existingItem = $action === 'save_module' ? ui_module_find_item($areaKey, $moduleKey, true) : null;
            $isCustom = $action === 'create_custom_module' || (is_array($existingItem) && !empty($existingItem['is_custom']));
            $supportsHref = $isCustom || (is_array($existingItem) && !empty($existingItem['supports_href']));
            $title = module_admin_input('title');
            $subtitle = module_admin_input('subtitle');
            $body = module_admin_body_input('body');
            $hrefRaw = trim((string) ($_POST['href'] ?? ''));
            $href = $supportsHref ? ui_module_normalize_href($hrefRaw) : null;

            if ($action === 'save_module' && !is_array($existingItem)) {
                $postErrors[] = 'A modul nem található.';
            }

            if ($isCustom && $title === null) {
                $postErrors[] = 'Az egyedi modul címe kötelező.';
            }

            if ($supportsHref && $hrefRaw !== '' && $href === '') {
                $postErrors[] = 'A link csak belső / útvonal, # horgony, http(s), mailto vagy tel lehet.';
            }

            if ($isCustom && $areaKey === 'electrician_app_home' && ($href ?? '') === '') {
                $postErrors[] = 'A szerelő app kezdőlapján az egyedi modulhoz link szükséges.';
            }

            if ($postErrors === []) {
                $sortOrder = filter_input(INPUT_POST, 'sort_order', FILTER_VALIDATE_INT);
                $fields = [
                    'title' => $title,
                    'subtitle' => $subtitle,
                    'body' => $body,
                    'href' => $href,
                    'sort_order' => $sortOrder !== false && $sortOrder !== null
                        ? (int) $sortOrder
                        : ($action === 'create_custom_module' ? ui_module_next_sort_order($areaKey) : ui_module_sort_order($areaKey, $moduleKey)),
                    'is_enabled' => !$isCustom || !empty($_POST['is_enabled']),
                ];

                if ($action === 'create_custom_module') {
                    $moduleKey = ui_module_create_custom($areaKey, $fields);
                    set_flash('success', 'Az új modul elkészült.');
                } else {
                    ui_module_save_fields($areaKey, $moduleKey, $isCustom ? 'custom' : 'system', $fields);
                    set_flash('success', 'A modul beállításai frissültek.');
                }

                module_admin_redirect($areaKey, $moduleKey);
            }
        }

        if ($postErrors === [] && $action === 'move_field') {
            crm_layout_field_move($areaKey, $fieldKey, (string) ($_POST['direction'] ?? ''));
            set_flash('success', 'A mező sorrendje frissült.');
            module_admin_redirect($areaKey, $fieldKey, 'field');
        }

        if ($postErrors === [] && $action === 'delete_custom_field') {
            crm_layout_field_delete_custom($areaKey, $fieldKey);
            set_flash('success', 'Az egyedi mező törölve lett.');
            module_admin_redirect($areaKey);
        }

        if ($postErrors === [] && in_array($action, ['save_field', 'create_custom_field'], true)) {
            $existingField = $action === 'save_field' ? crm_layout_field_find_item($areaKey, $fieldKey, true) : null;
            $isCustomField = $action === 'create_custom_field' || (is_array($existingField) && !empty($existingField['is_custom']));
            $label = module_admin_input('label');
            $helpText = module_admin_body_input('help_text');
            $placeholder = module_admin_input('placeholder');
            $groupKey = module_admin_input('group_key', 100)
                ?? (is_array($existingField) ? (string) ($existingField['group_key'] ?? 'custom') : 'custom');
            $inputType = module_admin_input('input_type', 40)
                ?? (is_array($existingField) ? (string) ($existingField['input_type'] ?? 'text') : 'text');

            if ($action === 'save_field' && !is_array($existingField)) {
                $postErrors[] = 'A mező nem található.';
            }

            if ($isCustomField && $label === null) {
                $postErrors[] = 'Az egyedi mező neve kötelező.';
            }

            if (!array_key_exists($inputType, $fieldTypeOptions)) {
                $postErrors[] = 'Érvénytelen mezőtípus.';
            }

            if ($postErrors === []) {
                $sortOrder = filter_input(INPUT_POST, 'sort_order', FILTER_VALIDATE_INT);
                $fields = [
                    'group_key' => $groupKey,
                    'label' => $label,
                    'help_text' => $helpText,
                    'placeholder' => $placeholder,
                    'input_type' => $inputType,
                    'sort_order' => $sortOrder !== false && $sortOrder !== null
                        ? (int) $sortOrder
                        : ($action === 'create_custom_field' ? crm_layout_field_next_sort_order($areaKey) : crm_layout_field_sort_order($areaKey, $fieldKey)),
                    'is_enabled' => !$isCustomField || !empty($_POST['is_enabled']),
                    'is_required_hint' => !empty($_POST['is_required_hint']),
                ];

                if ($action === 'create_custom_field') {
                    $fieldKey = crm_layout_field_create_custom($areaKey, $fields);
                    set_flash('success', 'Az új egyedi mező elkészült.');
                } else {
                    crm_layout_field_save_fields($areaKey, $fieldKey, $isCustomField ? 'custom' : 'system', $fields);
                    set_flash('success', 'A mező beállításai frissültek.');
                }

                module_admin_redirect($areaKey, $fieldKey, 'field');
            }
        }
    } catch (Throwable $exception) {
        $postErrors[] = APP_DEBUG ? $exception->getMessage() : 'A CRM testreszabás mentése sikertelen.';
    }

    if ($postErrors !== []) {
        set_flash('error', implode(' ', $postErrors));
        module_admin_redirect($areaKey, $fieldKey !== '' ? $fieldKey : $moduleKey, $fieldKey !== '' ? 'field' : 'module');
    }
}

$modules = $schemaErrors === [] ? ui_modules_for_area($selectedArea, true) : [];
$layoutFields = $schemaErrors === [] ? crm_layout_fields_for_area($selectedArea, true) : [];
$selectedAreaMeta = $areas[$selectedArea] ?? ['label' => $selectedArea, 'description' => ''];
?>
<section class="admin-section module-admin-page crm-customization-page">
    <div class="container">
        <div class="admin-header">
            <div>
                <p class="eyebrow">Szuperadmin</p>
                <h1>CRM testreszabás</h1>
                <p>A meglévő folyamatok változatlanok maradnak; itt a látható modulok sorrendje, szövegei, linkjei és a mezőfeliratok kezelhetők.</p>
            </div>
            <div class="admin-actions">
                <a class="button button-secondary" href="<?= h(url_path('/admin/dashboard')); ?>">Vezérlőpult</a>
            </div>
        </div>

        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div>
        <?php endif; ?>

        <?php if ($schemaErrors !== []): ?>
            <div class="alert alert-error">
                <?php foreach ($schemaErrors as $schemaError): ?><p><?= h($schemaError); ?></p><?php endforeach; ?>
            </div>
        <?php else: ?>
            <nav class="crm-customization-tabs module-admin-tabs" aria-label="CRM területek">
                <?php foreach ($areas as $areaKey => $area): ?>
                    <a class="<?= $areaKey === $selectedArea ? 'is-active' : ''; ?>" href="<?= h($baseUrl . '?area=' . rawurlencode((string) $areaKey)); ?>">
                        <?= h((string) $area['label']); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <section class="admin-request-panel module-create-panel">
                <div class="admin-request-section-title">
                    <h2>Új modul létrehozása</h2>
                    <span><?= h((string) $selectedAreaMeta['label']); ?></span>
                </div>
                <p class="muted-text"><?= h((string) $selectedAreaMeta['description']); ?></p>
                <form class="form" method="post" action="<?= h($baseUrl . '?area=' . rawurlencode($selectedArea)); ?>">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="create_custom_module">
                    <div class="form-grid two compact">
                        <div>
                            <label for="new_area_key">Megjelenési hely</label>
                            <select id="new_area_key" name="area_key">
                                <?php foreach ($areas as $areaKey => $area): ?>
                                    <option value="<?= h((string) $areaKey); ?>" <?= $areaKey === $selectedArea ? 'selected' : ''; ?>><?= h((string) $area['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div><label for="new_title">Modul címe</label><input id="new_title" name="title" required></div>
                        <div><label for="new_subtitle">Mellékszöveg</label><input id="new_subtitle" name="subtitle"></div>
                        <div><label for="new_href">Link</label><input id="new_href" name="href" placeholder="/admin/dashboard"></div>
                    </div>
                    <label for="new_body">Leírás</label>
                    <textarea id="new_body" name="body" rows="3"></textarea>
                    <div class="form-actions">
                        <button class="button" type="submit">Új modul mentése</button>
                    </div>
                </form>
            </section>

            <div class="module-admin-list">
                <?php foreach ($modules as $module): ?>
                    <?php
                    $moduleKey = (string) $module['module_key'];
                    $isCustom = !empty($module['is_custom']);
                    $storedTitle = (string) ($module['stored_title'] ?? '');
                    $storedSubtitle = (string) ($module['stored_subtitle'] ?? '');
                    $storedBody = (string) ($module['stored_body'] ?? '');
                    $storedHref = (string) ($module['stored_href'] ?? '');
                    $titleValue = $isCustom ? (string) $module['title'] : $storedTitle;
                    $subtitleValue = $isCustom ? (string) $module['subtitle'] : $storedSubtitle;
                    $bodyValue = $isCustom ? (string) $module['body'] : $storedBody;
                    $hrefValue = $isCustom ? (string) $module['href'] : $storedHref;
                    ?>
                    <article class="module-admin-card" id="module-<?= h($moduleKey); ?>">
                        <div class="module-admin-card-head">
                            <div>
                                <p class="eyebrow"><?= $isCustom ? 'Egyedi modul' : 'Rendszermodul'; ?></p>
                                <h2><?= h((string) $module['title']); ?></h2>
                                <p><?= h(ui_module_area_label($selectedArea)); ?> · sorrend: <?= (int) $module['sort_order']; ?></p>
                            </div>
                            <div class="module-admin-actions">
                                <?php foreach (['top' => 'Legfelülre', 'up' => 'Fel', 'down' => 'Le', 'bottom' => 'Legalulra'] as $direction => $label): ?>
                                    <form method="post" action="<?= h($baseUrl . '?area=' . rawurlencode($selectedArea)); ?>">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="move_module">
                                        <input type="hidden" name="area_key" value="<?= h($selectedArea); ?>">
                                        <input type="hidden" name="module_key" value="<?= h($moduleKey); ?>">
                                        <input type="hidden" name="direction" value="<?= h($direction); ?>">
                                        <button class="button button-secondary" type="submit"><?= h($label); ?></button>
                                    </form>
                                <?php endforeach; ?>
                                <?php if ($isCustom): ?>
                                    <form method="post" action="<?= h($baseUrl . '?area=' . rawurlencode($selectedArea)); ?>" onsubmit="return confirm('Biztosan törlöd ezt az egyedi modult?');">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_custom_module">
                                        <input type="hidden" name="area_key" value="<?= h($selectedArea); ?>">
                                        <input type="hidden" name="module_key" value="<?= h($moduleKey); ?>">
                                        <button class="button button-secondary danger-button" type="submit">Törlés</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <form class="form module-admin-edit-form" method="post" action="<?= h($baseUrl . '?area=' . rawurlencode($selectedArea)); ?>">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="action" value="save_module">
                            <input type="hidden" name="area_key" value="<?= h($selectedArea); ?>">
                            <input type="hidden" name="module_key" value="<?= h($moduleKey); ?>">

                            <div class="form-grid two compact">
                                <div>
                                    <label for="title_<?= h($moduleKey); ?>">Modul címe</label>
                                    <input id="title_<?= h($moduleKey); ?>" name="title" value="<?= h($titleValue); ?>" placeholder="<?= h((string) ($module['default_title'] ?? '')); ?>" <?= $isCustom ? 'required' : ''; ?>>
                                </div>
                                <div>
                                    <label for="subtitle_<?= h($moduleKey); ?>">Mellékszöveg / jelvény</label>
                                    <input id="subtitle_<?= h($moduleKey); ?>" name="subtitle" value="<?= h($subtitleValue); ?>" placeholder="<?= h((string) ($module['default_subtitle'] ?? '')); ?>">
                                </div>
                                <?php if (!empty($module['supports_href']) || $isCustom): ?>
                                    <div>
                                        <label for="href_<?= h($moduleKey); ?>">Link</label>
                                        <input id="href_<?= h($moduleKey); ?>" name="href" value="<?= h($hrefValue); ?>" placeholder="<?= h((string) ($module['default_href'] ?? '/')); ?>">
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <label for="sort_order_<?= h($moduleKey); ?>">Sorrend szám</label>
                                    <input id="sort_order_<?= h($moduleKey); ?>" name="sort_order" type="number" step="1" value="<?= (int) $module['sort_order']; ?>">
                                </div>
                            </div>

                            <label for="body_<?= h($moduleKey); ?>">Leírás</label>
                            <textarea id="body_<?= h($moduleKey); ?>" name="body" rows="3" placeholder="<?= h((string) ($module['default_body'] ?? '')); ?>"><?= h($bodyValue); ?></textarea>

                            <?php if (!$isCustom): ?>
                                <p class="muted-text">Üresen hagyott mezőnél az alapértelmezett vagy dinamikusan számolt szöveg marad érvényben.</p>
                            <?php else: ?>
                                <label class="checkbox-row">
                                    <input type="checkbox" name="is_enabled" value="1" <?= !empty($module['is_enabled']) ? 'checked' : ''; ?>>
                                    <span>Modul megjelenítése</span>
                                </label>
                            <?php endif; ?>

                            <div class="form-actions">
                                <button class="button" type="submit">Mentés</button>
                            </div>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>

            <section class="admin-request-panel module-create-panel crm-field-create-panel">
                <div class="admin-request-section-title">
                    <h2>Új egyedi mező létrehozása</h2>
                    <span><?= h((string) $selectedAreaMeta['label']); ?></span>
                </div>
                <p class="muted-text">Az egyedi mező először konfigurációként jön létre. Az értékek mentését külön, célzott bekötéssel lehet rákapcsolni az adott adatlapra.</p>
                <form class="form" method="post" action="<?= h($baseUrl . '?area=' . rawurlencode($selectedArea)); ?>">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="create_custom_field">
                    <input type="hidden" name="area_key" value="<?= h($selectedArea); ?>">
                    <div class="form-grid two compact">
                        <div><label for="new_field_label">Mező neve</label><input id="new_field_label" name="label" required></div>
                        <div><label for="new_field_group">Mezőcsoport</label><input id="new_field_group" name="group_key" value="custom"></div>
                        <div>
                            <label for="new_field_type">Mezőtípus</label>
                            <select id="new_field_type" name="input_type">
                                <?php foreach ($fieldTypeOptions as $typeKey => $typeLabel): ?>
                                    <option value="<?= h($typeKey); ?>"><?= h($typeLabel); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div><label for="new_field_placeholder">Placeholder</label><input id="new_field_placeholder" name="placeholder"></div>
                    </div>
                    <label for="new_field_help">Segédszöveg</label>
                    <textarea id="new_field_help" name="help_text" rows="3"></textarea>
                    <label class="checkbox-row">
                        <input type="checkbox" name="is_required_hint" value="1">
                        <span>Kötelezőként jelölés a felületen</span>
                    </label>
                    <div class="form-actions">
                        <button class="button" type="submit">Új mező mentése</button>
                    </div>
                </form>
            </section>

            <div class="module-admin-list field-admin-list">
                <?php foreach ($layoutFields as $field): ?>
                    <?php
                    $fieldKey = (string) $field['field_key'];
                    $isCustomField = !empty($field['is_custom']);
                    $labelValue = $isCustomField ? (string) $field['label'] : (string) ($field['stored_label'] ?? '');
                    $helpValue = $isCustomField ? (string) $field['help_text'] : (string) ($field['stored_help_text'] ?? '');
                    $placeholderValue = $isCustomField ? (string) $field['placeholder'] : (string) ($field['stored_placeholder'] ?? '');
                    ?>
                    <article class="module-admin-card field-admin-card" id="field-<?= h($fieldKey); ?>">
                        <div class="module-admin-card-head">
                            <div>
                                <p class="eyebrow"><?= $isCustomField ? 'Egyedi mező' : 'Rendszermező'; ?></p>
                                <h2><?= h((string) $field['label']); ?></h2>
                                <p><?= h((string) $field['group_key']); ?> · <?= h((string) $field['input_type']); ?> · sorrend: <?= (int) $field['sort_order']; ?></p>
                            </div>
                            <div class="module-admin-actions">
                                <?php foreach (['top' => 'Legfelülre', 'up' => 'Fel', 'down' => 'Le', 'bottom' => 'Legalulra'] as $direction => $label): ?>
                                    <form method="post" action="<?= h($baseUrl . '?area=' . rawurlencode($selectedArea)); ?>">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="move_field">
                                        <input type="hidden" name="area_key" value="<?= h($selectedArea); ?>">
                                        <input type="hidden" name="field_key" value="<?= h($fieldKey); ?>">
                                        <input type="hidden" name="direction" value="<?= h($direction); ?>">
                                        <button class="button button-secondary" type="submit"><?= h($label); ?></button>
                                    </form>
                                <?php endforeach; ?>
                                <?php if ($isCustomField): ?>
                                    <form method="post" action="<?= h($baseUrl . '?area=' . rawurlencode($selectedArea)); ?>" onsubmit="return confirm('Biztosan törlöd ezt az egyedi mezőt?');">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_custom_field">
                                        <input type="hidden" name="area_key" value="<?= h($selectedArea); ?>">
                                        <input type="hidden" name="field_key" value="<?= h($fieldKey); ?>">
                                        <button class="button button-secondary danger-button" type="submit">Törlés</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <form class="form module-admin-edit-form" method="post" action="<?= h($baseUrl . '?area=' . rawurlencode($selectedArea)); ?>">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="action" value="save_field">
                            <input type="hidden" name="area_key" value="<?= h($selectedArea); ?>">
                            <input type="hidden" name="field_key" value="<?= h($fieldKey); ?>">

                            <div class="form-grid two compact">
                                <div>
                                    <label for="field_label_<?= h($fieldKey); ?>">Mező neve</label>
                                    <input id="field_label_<?= h($fieldKey); ?>" name="label" value="<?= h($labelValue); ?>" placeholder="<?= h((string) ($field['default_label'] ?? '')); ?>" <?= $isCustomField ? 'required' : ''; ?>>
                                </div>
                                <div>
                                    <label for="field_group_<?= h($fieldKey); ?>">Mezőcsoport</label>
                                    <input id="field_group_<?= h($fieldKey); ?>" name="group_key" value="<?= h((string) $field['group_key']); ?>">
                                </div>
                                <div>
                                    <label for="field_type_<?= h($fieldKey); ?>">Mezőtípus</label>
                                    <select id="field_type_<?= h($fieldKey); ?>" name="input_type">
                                        <?php foreach ($fieldTypeOptions as $typeKey => $typeLabel): ?>
                                            <option value="<?= h($typeKey); ?>" <?= (string) $field['input_type'] === $typeKey ? 'selected' : ''; ?>><?= h($typeLabel); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label for="field_sort_<?= h($fieldKey); ?>">Sorrend szám</label>
                                    <input id="field_sort_<?= h($fieldKey); ?>" name="sort_order" type="number" step="1" value="<?= (int) $field['sort_order']; ?>">
                                </div>
                                <div>
                                    <label for="field_placeholder_<?= h($fieldKey); ?>">Placeholder</label>
                                    <input id="field_placeholder_<?= h($fieldKey); ?>" name="placeholder" value="<?= h($placeholderValue); ?>" placeholder="<?= h((string) ($field['default_placeholder'] ?? '')); ?>">
                                </div>
                            </div>

                            <label for="field_help_<?= h($fieldKey); ?>">Segédszöveg</label>
                            <textarea id="field_help_<?= h($fieldKey); ?>" name="help_text" rows="3" placeholder="<?= h((string) ($field['default_help_text'] ?? '')); ?>"><?= h($helpValue); ?></textarea>

                            <label class="checkbox-row">
                                <input type="checkbox" name="is_required_hint" value="1" <?= !empty($field['is_required_hint']) ? 'checked' : ''; ?>>
                                <span>Kötelezőként jelölés a felületen</span>
                            </label>

                            <?php if (!$isCustomField): ?>
                                <p class="muted-text">Üresen hagyott mezőnévnél az alapértelmezett vagy dinamikusan számolt felirat marad érvényben.</p>
                            <?php else: ?>
                                <label class="checkbox-row">
                                    <input type="checkbox" name="is_enabled" value="1" <?= !empty($field['is_enabled']) ? 'checked' : ''; ?>>
                                    <span>Mező megjelenítése</span>
                                </label>
                            <?php endif; ?>

                            <div class="form-actions">
                                <button class="button" type="submit">Mentés</button>
                            </div>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
