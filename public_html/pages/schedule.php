<?php
declare(strict_types=1);

$requestId = filter_input(INPUT_GET, 'request_id', FILTER_VALIDATE_INT);
$token = trim((string) ($_GET['token'] ?? ''));
$request = $requestId ? find_connection_request((int) $requestId) : null;

if ($request === null || $token === '' || !hash_equals(connection_request_schedule_token($request), $token)) {
    http_response_code(404);
    require PAGE_PATH . '/404.php';
    return;
}

$errors = connection_request_schedule_schema_errors();
$flash = get_flash();

if (is_post() && $errors === []) {
    require_valid_csrf_token();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'book_schedule_day') {
        $date = trim((string) ($_POST['work_date'] ?? ''));
        $slots = connection_request_schedule_slots((int) $request['id']);
        $openDates = [];

        foreach ($slots as $slot) {
            if ((string) ($slot['status'] ?? '') === 'open') {
                $openDates[(string) $slot['work_date']] = true;
            }
        }

        if (!isset($openDates[$date])) {
            set_flash('error', 'Ezt a napot a szerelő még nem nyitotta meg, vagy már nem szabad.');
        } else {
            $result = connection_request_schedule_upsert_slot((int) $request['id'], $date, 'booked', 'customer', null, 'Ügyfél által választott nap');
            set_flash(($result['ok'] ?? false) ? 'success' : 'error', (string) ($result['message'] ?? 'Az időpont mentése sikertelen.'));
        }

        redirect('/schedule?request_id=' . (int) $request['id'] . '&token=' . rawurlencode($token));
    }
}

$slots = $errors === [] ? connection_request_schedule_slots((int) $request['id']) : [];
$bookedSlot = $errors === [] ? connection_request_booked_schedule_slot((int) $request['id']) : null;
$openSlots = array_values(array_filter($slots, static fn (array $slot): bool => (string) ($slot['status'] ?? '') === 'open'));
$dueBreakdown = connection_request_electrician_due_breakdown((int) $request['id']);
$siteAddress = trim((string) ($request['site_postal_code'] ?? '') . ' ' . (string) ($request['site_address'] ?? ''));
?>
<section class="admin-section">
    <div class="container">
        <div class="admin-header">
            <div>
                <p class="eyebrow">Kivitelezési naptár</p>
                <h1>Időpontválasztás</h1>
                <p><?= h((string) ($request['project_name'] ?? 'Munka')); ?><?= $siteAddress !== '' ? ' · ' . h($siteAddress) : ''; ?></p>
            </div>
        </div>

        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
            <div class="alert alert-error"><?php foreach ($errors as $error): ?><p><?= h($error); ?></p><?php endforeach; ?></div>
        <?php else: ?>
            <?php if ($bookedSlot !== null): ?>
                <section class="auth-panel form-block">
                    <p class="eyebrow">Foglalva</p>
                    <h2><?= h(connection_request_schedule_day_label((string) $bookedSlot['work_date'])); ?></h2>
                    <p>A kivitelezési nap ehhez a munkához rögzítve lett.</p>
                </section>
            <?php endif; ?>

            <section class="auth-panel form-block">
                <h2>Kivitelezés napján fizetendő</h2>
                <dl class="admin-request-data-list admin-request-data-list-compact">
                    <div><dt>Regisztrált villanyszerelői tételek</dt><dd><?= h(format_money((float) $dueBreakdown['registered'])); ?></dd></div>
                    <div><dt>Villanyszerelői szakmunkás tételek</dt><dd><?= h(format_money((float) $dueBreakdown['specialist'])); ?></dd></div>
                    <div><dt>Összesen a szerelő részére</dt><dd><?= h(format_money((float) $dueBreakdown['total'])); ?></dd></div>
                    <div><dt>Nem része</dt><dd>MVM csekk és ügykezelési díj</dd></div>
                </dl>
            </section>

            <section class="auth-panel form-block">
                <div class="admin-request-section-title">
                    <h2>Szabad hétköznapok</h2>
                    <span><?= count($openSlots); ?> nap</span>
                </div>

                <?php if ($openSlots === []): ?>
                    <div class="empty-state">
                        <h3>Még nincs megnyitott időpont</h3>
                        <p>A szerelő hamarosan megnyitja a választható kivitelezési napokat.</p>
                    </div>
                <?php else: ?>
                    <div class="quote-mini-list">
                        <?php foreach ($openSlots as $slot): ?>
                            <article class="quote-mini-card">
                                <strong><?= h(connection_request_schedule_day_label((string) $slot['work_date'])); ?></strong>
                                <span>Szabad időpont</span>
                                <form method="post" action="<?= h(url_path('/schedule') . '?request_id=' . (int) $request['id'] . '&token=' . rawurlencode($token)); ?>">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="action" value="book_schedule_day">
                                    <input type="hidden" name="work_date" value="<?= h((string) $slot['work_date']); ?>">
                                    <button class="button" type="submit">Ezt a napot választom</button>
                                </form>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</section>
