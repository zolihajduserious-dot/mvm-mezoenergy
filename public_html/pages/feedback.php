<?php
declare(strict_types=1);

require_login();

if (!can_submit_development_suggestion()) {
    http_response_code(403);
    exit('Az ügyfélportálról nem küldhető fejlesztési javaslat.');
}

$user = current_user();
$userId = is_array($user) ? (int) $user['id'] : 0;
$errors = [];
$form = [
    'title' => '',
    'body' => '',
];

if (is_post()) {
    require_valid_csrf_token();

    $action = (string) ($_POST['action'] ?? 'create_suggestion');

    if ($action === 'update_suggestion_status') {
        $result = update_development_suggestion_status(
            max(0, (int) ($_POST['suggestion_id'] ?? 0)),
            (string) ($_POST['status'] ?? ''),
            trim((string) ($_POST['admin_note'] ?? ''))
        );
        set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'A javaslat frissítése sikertelen.'));
        redirect('/feedback');
    }

    $form['title'] = trim((string) ($_POST['title'] ?? ''));
    $form['body'] = trim((string) ($_POST['body'] ?? ''));
    $result = create_development_suggestion($form);

    if ($result['ok'] ?? false) {
        set_flash('success', (string) $result['message']);
        redirect('/feedback');
    }

    $errors[] = (string) ($result['message'] ?? 'A fejlesztési javaslat mentése sikertelen.');
}

$schemaErrors = development_suggestion_schema_errors();
$flash = get_flash();
$statusLabels = development_suggestion_status_labels();
$suggestions = [];

if ($schemaErrors === []) {
    $suggestions = can_manage_development_suggestions()
        ? all_development_suggestions(200)
        : development_suggestions_for_user($userId, 50);
}
?>
<section class="admin-section development-suggestions-page">
    <div class="container">
        <div class="admin-header">
            <div>
                <p class="eyebrow"><?= h(user_role_label()); ?></p>
                <h1>Fejlesztési javaslatok</h1>
                <p>Ide kerülhet minden észrevétel, hiba vagy új funkció ötlet, amit érdemes átnézni.</p>
            </div>
            <div class="admin-actions">
                <a class="button button-secondary" href="<?= h(url_path(dashboard_path_for_user())); ?>">Vissza</a>
            </div>
        </div>

        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div>
        <?php endif; ?>

        <?php if ($schemaErrors !== []): ?>
            <div class="alert alert-error">
                <?php foreach ($schemaErrors as $schemaError): ?><p><?= h($schemaError); ?></p><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="form-grid two">
            <section class="auth-panel">
                <h2>Új javaslat rögzítése</h2>

                <?php if ($errors !== []): ?>
                    <div class="alert alert-error">
                        <?php foreach ($errors as $error): ?><p><?= h($error); ?></p><?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form class="form" method="post" action="<?= h(url_path('/feedback')); ?>">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="create_suggestion">

                    <label for="suggestion_title">Rövid cím</label>
                    <input id="suggestion_title" name="title" value="<?= h($form['title']); ?>" maxlength="190" required>

                    <label for="suggestion_body">Javaslat vagy hiba leírása</label>
                    <textarea id="suggestion_body" name="body" rows="8" maxlength="5000" required><?= h($form['body']); ?></textarea>

                    <button class="button" type="submit">Javaslat beküldése</button>
                </form>
            </section>

            <section class="auth-panel">
                <h2><?= can_manage_development_suggestions() ? 'Beérkezett javaslatok' : 'Saját javaslataim'; ?></h2>

                <?php if ($suggestions === []): ?>
                    <p class="muted-text">Még nincs rögzített fejlesztési javaslat.</p>
                <?php else: ?>
                    <div class="development-suggestion-list">
                        <?php foreach ($suggestions as $suggestion): ?>
                            <?php $status = (string) ($suggestion['status'] ?? 'new'); ?>
                            <article class="development-suggestion-card">
                                <div class="admin-header compact">
                                    <div>
                                        <p class="eyebrow"><?= h(development_suggestion_actor_label($suggestion)); ?></p>
                                        <h3><?= h((string) $suggestion['title']); ?></h3>
                                        <p><?= h((string) $suggestion['created_at']); ?></p>
                                    </div>
                                    <span class="status-badge status-badge-<?= h($status); ?>"><?= h(development_suggestion_status_label($status)); ?></span>
                                </div>
                                <p><?= nl2br(h((string) $suggestion['body'])); ?></p>

                                <?php if (trim((string) ($suggestion['admin_note'] ?? '')) !== ''): ?>
                                    <div class="alert alert-info"><p><?= nl2br(h((string) $suggestion['admin_note'])); ?></p></div>
                                <?php endif; ?>

                                <?php if (can_manage_development_suggestions()): ?>
                                    <form class="form development-suggestion-review-form" method="post" action="<?= h(url_path('/feedback')); ?>">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="action" value="update_suggestion_status">
                                        <input type="hidden" name="suggestion_id" value="<?= (int) $suggestion['id']; ?>">

                                        <label for="suggestion_status_<?= (int) $suggestion['id']; ?>">Státusz</label>
                                        <select id="suggestion_status_<?= (int) $suggestion['id']; ?>" name="status">
                                            <?php foreach ($statusLabels as $statusKey => $statusLabel): ?>
                                                <option value="<?= h($statusKey); ?>" <?= $status === $statusKey ? 'selected' : ''; ?>><?= h($statusLabel); ?></option>
                                            <?php endforeach; ?>
                                        </select>

                                        <label for="suggestion_note_<?= (int) $suggestion['id']; ?>">Belső megjegyzés</label>
                                        <textarea id="suggestion_note_<?= (int) $suggestion['id']; ?>" name="admin_note" rows="2"><?= h((string) ($suggestion['admin_note'] ?? '')); ?></textarea>

                                        <button class="button button-secondary" type="submit">Státusz mentése</button>
                                    </form>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</section>
