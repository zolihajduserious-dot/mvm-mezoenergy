<?php
declare(strict_types=1);

require_role(['admin', 'specialist']);

$schemaErrors = [];

if (!users_table_exists()) {
    $schemaErrors[] = 'Hianyzik a users tabla.';
}

if (!db_table_exists('contractors')) {
    $schemaErrors[] = 'Hianyzik a contractors tabla.';
}
$flash = get_flash();

if (is_post() && $schemaErrors === []) {
    require_valid_csrf_token();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete_contractor') {
        if (!is_admin_user()) {
            set_flash('error', 'Generalkivitelezo fiokot torolni csak foadmin jogosultsaggal lehet.');
            redirect('/admin/contractors');
        }

        $deleteUserId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);

        if (!$deleteUserId) {
            set_flash('error', 'A torlendo generalkivitelezo fiok nem talalhato.');
            redirect('/admin/contractors');
        }

        try {
            $summary = delete_user_account_with_related_data((int) $deleteUserId);
            set_flash('success', 'A generalkivitelezo fiok torolve: ' . (string) ($summary['user_name'] ?? ''));
        } catch (Throwable $exception) {
            set_flash('error', APP_DEBUG ? $exception->getMessage() : 'A generalkivitelezo fiok torlese sikertelen.');
        }

        redirect('/admin/contractors');
    }
}

$contractors = $schemaErrors === [] ? contractor_users() : [];
?>
<section class="admin-section">
    <div class="container">
        <div class="admin-header">
            <div>
                <p class="eyebrow">Admin</p>
                <h1>Generálkivitelezők</h1>
                <p>Saját belépéssel rendelkező generálkivitelezői fiókok áttekintése és törlése.</p>
            </div>
            <a class="button button-secondary" href="<?= h(url_path('/admin/dashboard')); ?>">Vezérlőpult</a>
        </div>

        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div>
        <?php endif; ?>

        <?php if ($schemaErrors !== []): ?>
            <div class="alert alert-error">
                <p>A generálkivitelezői fiókok kezeléséhez hiányzik egy adatbázis-frissítés.</p>
                <?php foreach ($schemaErrors as $schemaError): ?><p><?= h($schemaError); ?></p><?php endforeach; ?>
            </div>
        <?php else: ?>
            <section class="auth-panel">
                <h2>Rögzített generálkivitelezők</h2>
                <?php if ($contractors === []): ?>
                    <p class="muted-text">Még nincs generálkivitelezői fiók.</p>
                <?php else: ?>
                    <div class="status-list">
                        <?php foreach ($contractors as $contractor): ?>
                            <?php
                            $displayName = trim((string) ($contractor['contractor_name'] ?? ''));
                            $contactName = trim((string) ($contractor['contact_name'] ?? ''));
                            $companyName = trim((string) ($contractor['company_name'] ?? ''));
                            $email = trim((string) (($contractor['email'] ?? '') ?: ($contractor['user_email'] ?? '')));
                            $phone = trim((string) ($contractor['phone'] ?? ''));
                            $cityLine = trim((string) ($contractor['postal_code'] ?? '') . ' ' . (string) ($contractor['city'] ?? ''));
                            $verifiedLabel = user_email_verification_column_exists()
                                ? (trim((string) ($contractor['email_verified_at'] ?? '')) !== '' ? 'email megerősítve' : 'email nincs megerősítve')
                                : '';
                            $metaParts = array_values(array_filter([
                                $companyName,
                                $contactName !== '' && $contactName !== $displayName ? 'Kapcsolattartó: ' . $contactName : '',
                                $email,
                                $phone,
                                $cityLine,
                                $verifiedLabel,
                            ], static fn (string $value): bool => $value !== ''));
                            ?>
                            <li>
                                <span class="status-label"><?= h($displayName !== '' ? $displayName : ('Generálkivitelező #' . (int) $contractor['user_id'])); ?></span>
                                <span class="status-value"><?= h(implode(' · ', $metaParts)); ?></span>
                                <?php if (is_admin_user()): ?>
                                    <form method="post" action="<?= h(url_path('/admin/contractors')); ?>" onsubmit="return confirm('Biztosan törlöd ezt a generálkivitelezői felhasználót?') && confirm('A fiók törlődik, a hozzá kapcsolt munkák megmaradnak. Folytatod?');">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_contractor">
                                        <input type="hidden" name="user_id" value="<?= (int) $contractor['user_id']; ?>">
                                        <button class="table-action-button table-action-danger" type="submit">Törlés</button>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</section>
