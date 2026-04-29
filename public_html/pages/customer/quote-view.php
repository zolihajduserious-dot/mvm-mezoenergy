<?php
declare(strict_types=1);

require_role(['customer']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$quote = $id ? find_quote($id) : null;

if ($quote === null || !customer_can_view_quote($quote)) {
    http_response_code(404);
    require PAGE_PATH . '/404.php';
    return;
}

$lines = quote_lines((int) $quote['id']);
$flash = get_flash();
$intent = (string) ($_GET['intent'] ?? '');
$status = (string) ($quote['status'] ?? 'sent');
$canRespond = !in_array($status, ['accepted', 'consultation_requested'], true);
$quoteState = quote_state_summary($quote, $status === 'accepted' ? $quote : null);

if (is_post()) {
    require_valid_csrf_token();
    $action = (string) ($_POST['quote_action'] ?? '');
    $message = trim((string) ($_POST['response_message'] ?? ''));
    $result = record_quote_customer_response((int) $quote['id'], $action, $message);
    set_flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('/customer/quotes/view?id=' . (int) $quote['id']);
}
?>
<section class="content-section">
    <div class="container">
        <div class="section-heading">
            <p class="eyebrow">Ajánlat</p>
            <h1><?= h($quote['quote_number']); ?></h1>
            <p><?= h($quote['subject']); ?></p>
        </div>

        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div>
        <?php endif; ?>

        <?php if (!empty($quote['customer_message'])): ?>
            <div class="alert alert-info"><p><?= nl2br(h($quote['customer_message'])); ?></p></div>
        <?php endif; ?>

        <div class="quote-state-card quote-state-card-<?= h((string) $quoteState['class']); ?>">
            <div>
                <span class="portal-kicker">Árajánlat állapota</span>
                <strong><?= h((string) $quoteState['title']); ?></strong>
                <p><?= h((string) $quoteState['description']); ?></p>
            </div>
            <strong><?= h((string) $quoteState['amount']); ?></strong>
        </div>

        <?php if (quote_file_is_available($quote)): ?>
            <div class="admin-actions">
                <a class="button" href="<?= h(url_path('/customer/quotes/file') . '?id=' . (int) $quote['id']); ?>" target="_blank">Árajánlat letöltése</a>
            </div>
        <?php endif; ?>

        <?php if ($lines !== []): ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Tétel</th><th>Mennyiség</th><th>Ár</th><th>Összesen</th></tr></thead>
                    <tbody>
                        <?php foreach ($lines as $line): ?>
                            <tr>
                                <td><strong><?= h($line['name']); ?></strong><span><?= h(quote_effective_category((string) $line['category'], (string) $line['name'])); ?></span></td>
                                <td><?= h($line['quantity']); ?> <?= h($line['unit']); ?></td>
                                <td><?= h(format_money($line['unit_price'])); ?></td>
                                <td><?= h(format_money($line['line_gross'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr><td colspan="3"><strong>Teljes fizetendő összeg</strong></td><td><strong><?= h(format_money($quote['total_gross'])); ?></strong></td></tr>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <h2>Feltöltött árajánlat</h2>
                <p>Az árajánlat részleteit a letölthető dokumentumban találod.</p>
            </div>
        <?php endif; ?>

        <div id="quote-actions" class="quote-response-panel">
            <?php if ($canRespond): ?>
                <?php if ($intent === 'accept'): ?>
                    <div class="alert alert-info"><p>Az elfogadáshoz kattints az alábbi gombra.</p></div>
                <?php elseif ($intent === 'consultation'): ?>
                    <div class="alert alert-info"><p>Írd le röviden, miről szeretnél egyeztetni, majd küldd el a kérést.</p></div>
                <?php endif; ?>

                <div class="quote-response-grid">
                    <form method="post" action="<?= h(url_path('/customer/quotes/view') . '?id=' . (int) $quote['id']); ?>" class="quote-response-card">
                        <?= csrf_field(); ?>
                        <h2>Elfogadás</h2>
                        <p>Ha az ajánlat megfelelő, itt tudod elfogadni. Az admin azonnal értesítést kap.</p>
                        <button class="button" name="quote_action" value="accept" type="submit">Elfogadom az árajánlatot</button>
                    </form>

                    <form method="post" action="<?= h(url_path('/customer/quotes/view') . '?id=' . (int) $quote['id']); ?>" class="quote-response-card">
                        <?= csrf_field(); ?>
                        <h2>Egyeztetés</h2>
                        <p>Ha kérdésed van, vagy módosítást szeretnél, küldj egyeztetési kérést.</p>
                        <label for="response_message_customer">Megjegyzés az egyeztetéshez</label>
                        <textarea id="response_message_customer" name="response_message" rows="4" placeholder="Például: szeretnék kérdezni a tételekről vagy módosítást kérek."></textarea>
                        <button class="button button-secondary" name="quote_action" value="consultation" type="submit">Árajánlat egyeztetés</button>
                    </form>
                </div>
            <?php elseif ($status === 'accepted'): ?>
                <div class="alert alert-success"><p>Az ajánlat elfogadva.</p></div>
            <?php elseif ($status === 'consultation_requested'): ?>
                <div class="alert alert-info"><p>Az árajánlat egyeztetési kérését rögzítettük. Hamarosan felvesszük veled a kapcsolatot.</p></div>
            <?php endif; ?>
        </div>
    </div>
</section>
