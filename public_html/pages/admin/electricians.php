<?php
declare(strict_types=1);

require_role(['admin', 'specialist']);

$schemaErrors = electrician_schema_errors();
$flash = get_flash();
$errors = [];
$form = normalize_electrician_data(['is_active' => 1]);

if (is_post() && $schemaErrors === []) {
    require_valid_csrf_token();

    $action = (string) ($_POST['action'] ?? 'create_electrician');

    if ($action === 'delete_electrician') {
        if (!is_admin_user()) {
            set_flash('error', 'Szerelői fiókot törölni csak főadmin jogosultsággal lehet.');
            redirect('/admin/electricians');
        }

        $deleteUserId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);

        if (!$deleteUserId) {
            set_flash('error', 'A törlendő szerelői fiók nem található.');
            redirect('/admin/electricians');
        }

        try {
            $summary = delete_user_account_with_related_data((int) $deleteUserId);
            set_flash('success', 'A szerelői fiók törölve: ' . (string) ($summary['user_name'] ?? ''));
        } catch (Throwable $exception) {
            set_flash('error', APP_DEBUG ? $exception->getMessage() : 'A szerelői fiók törlése sikertelen.');
        }

        redirect('/admin/electricians');
    }

    $form = normalize_electrician_data($_POST);
    $password = (string) ($_POST['password'] ?? '');
    $errors = validate_electrician_data($form, true, $password);

    if ($errors === []) {
        try {
            create_electrician_account($form, $password, true);
            set_flash('success', 'A szerelői fiók elkészült.');
            redirect('/admin/electricians');
        } catch (Throwable $exception) {
            $errors[] = APP_DEBUG ? $exception->getMessage() : 'A szerelő mentése sikertelen.';
        }
    }
}

$electricians = $schemaErrors === [] ? electrician_users(false) : [];
?>
<section class="admin-section">
    <div class="container">
        <div class="admin-header">
            <div>
                <p class="eyebrow">Admin</p>
                <h1>Szerelők</h1>
                <p>Saját belépéssel rendelkező szerelők, akikhez kivitelezési munkát lehet kiadni.</p>
            </div>
            <a class="button button-secondary" href="<?= h(url_path('/admin/dashboard')); ?>">Vezérlőpult</a>
        </div>

        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div>
        <?php endif; ?>

        <?php if ($schemaErrors !== []): ?>
            <div class="alert alert-error">
                <p>Előbb futtasd le phpMyAdminban a <strong>database/electrician_workflow.sql</strong> fájlt.</p>
                <?php foreach ($schemaErrors as $schemaError): ?><p><?= h($schemaError); ?></p><?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="form-grid two">
                <section class="auth-panel">
                    <h2>Új szerelői fiók</h2>
                    <?php if ($errors !== []): ?>
                        <div class="alert alert-error"><?php foreach ($errors as $error): ?><p><?= h($error); ?></p><?php endforeach; ?></div>
                    <?php endif; ?>

                    <form class="form" method="post" action="<?= h(url_path('/admin/electricians')); ?>">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="create_electrician">
                        <label for="name">Név</label>
                        <input id="name" name="name" value="<?= h($form['name']); ?>" required>

                        <label for="phone">Telefon</label>
                        <input id="phone" name="phone" value="<?= h($form['phone']); ?>" required>

                        <label for="email">Email</label>
                        <input id="email" name="email" type="email" value="<?= h($form['email']); ?>" required>

                        <label for="password">Kezdő jelszó</label>
                        <input id="password" name="password" type="password" minlength="<?= PASSWORD_MIN_LENGTH; ?>" required>

                        <label for="notes">Megjegyzés</label>
                        <textarea id="notes" name="notes" rows="4"><?= h($form['notes']); ?></textarea>

                        <label class="checkbox-row">
                            <input type="checkbox" name="is_active" value="1" <?= (int) $form['is_active'] === 1 ? 'checked' : ''; ?>>
                            <span>Aktív szerelő</span>
                        </label>

                        <button class="button" type="submit">Szerelő létrehozása</button>
                    </form>
                </section>

                <section class="auth-panel">
                    <h2>Rögzített szerelők</h2>
                    <?php if ($electricians === []): ?>
                        <p class="muted-text">Még nincs szerelői fiók.</p>
                    <?php else: ?>
                        <div class="status-list">
                            <?php foreach ($electricians as $electrician): ?>
                                <li>
                                    <span class="status-label"><?= h((string) $electrician['name']); ?></span>
                                    <span class="status-value">
                                        <?= h((string) $electrician['email']); ?>
                                        <?= !empty($electrician['phone']) ? ' · ' . h((string) $electrician['phone']) : ''; ?>
                                        <?= (int) $electrician['is_active'] === 1 ? '' : ' · inaktív'; ?>
                                        <?= user_email_verification_column_exists() ? (trim((string) ($electrician['email_verified_at'] ?? '')) !== '' ? ' · email megerősítve' : ' · email nincs megerősítve') : ''; ?>
                                    </span>
                                    <?php if (is_admin_user()): ?>
                                        <form method="post" action="<?= h(url_path('/admin/electricians')); ?>" onsubmit="return confirm('Biztosan törlöd ezt a szerelői fiókot?');">
                                            <?= csrf_field(); ?>
                                            <input type="hidden" name="action" value="delete_electrician">
                                            <input type="hidden" name="user_id" value="<?= (int) $electrician['user_id']; ?>">
                                            <button class="table-action-button table-action-danger" type="submit">Törlés</button>
                                        </form>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        <?php endif; ?>
    </div>
</section>
