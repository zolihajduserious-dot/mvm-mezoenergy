<?php
declare(strict_types=1);

require_role(['admin', 'specialist']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$quote = $id ? find_quote($id) : null;

if ($quote === null) {
    set_flash('error', 'Az ajánlat nem található.');
    redirect('/admin/quotes');
}

$result = null;

if (is_post()) {
    require_valid_csrf_token();
    $action = (string) ($_POST['action'] ?? '');
    $result = match ($action) {
        'send' => send_quote_email((int) $quote['id']),
        'fee_request' => send_quote_fee_request_email((int) $quote['id']),
        default => generate_quote_pdf((int) $quote['id']),
    };
    $message = (string) $result['message'];

    if (!$result['ok'] && preg_match('/dompdf|phpmailer|composer|vendor|smtp/i', $message)) {
        $message = 'A művelet jelenleg nem indítható. Kérlek próbáld újra később, vagy jelezd a weboldal karbantartójának.';
    }

    set_flash($result['ok'] ? 'success' : 'error', $message);
    redirect('/admin/quotes/send?id=' . (int) $quote['id']);
}

$flash = get_flash();
$quoteTotal = quote_display_total($quote);
$quoteFileUrl = quote_file_is_available($quote) ? url_path('/admin/quotes/file') . '?id=' . (int) $quote['id'] : null;
$feeRequestSelection = quote_fee_request_selection((int) $quote['id']);
$feeRequestLine = is_array($feeRequestSelection['line'] ?? null) ? $feeRequestSelection['line'] : null;
$feeRequestFileUrl = quote_fee_request_file_is_available($quote) ? url_path('/admin/quotes/fee-request-file') . '?id=' . (int) $quote['id'] : null;
$feeRequestBlockedMessage = null;

if ((string) ($quote['status'] ?? '') !== 'accepted') {
    $feeRequestBlockedMessage = 'Díjbekérő csak elfogadott árajánlatból küldhető.';
} elseif (!$feeRequestSelection['ok']) {
    $feeRequestBlockedMessage = (string) $feeRequestSelection['message'];
} elseif ($feeRequestFileUrl !== null) {
    $feeRequestBlockedMessage = 'A díjbekérő már elkészült, a PDF innen megnyitható.';
} elseif (szamlazz_config_value('SZAMLAZZ_AGENT_KEY') === '') {
    $feeRequestBlockedMessage = 'Nincs beállítva a Számlázz.hu Agent kulcs.';
}
?>
<section class="admin-section">
    <div class="container">
        <div class="admin-header">
            <div>
                <p class="eyebrow">Ajánlat</p>
                <h1>PDF és email küldés</h1>
                <p><?= h($quote['quote_number']); ?> · <?= h($quote['requester_name']); ?></p>
            </div>
            <a class="button button-secondary" href="<?= h(url_path('/admin/quotes/edit') . '?id=' . (int) $quote['id']); ?>">Vissza az ajánlathoz</a>
        </div>

        <section class="auth-panel quote-send-panel">
            <div class="quote-send-summary">
                <div>
                    <span>Ajánlatszám</span>
                    <strong><?= h((string) $quote['quote_number']); ?></strong>
                </div>
                <div>
                    <span>Ügyfél</span>
                    <strong><?= h((string) $quote['requester_name']); ?></strong>
                </div>
                <div>
                    <span>Email</span>
                    <strong><?= h((string) $quote['email']); ?></strong>
                </div>
                <div>
                    <span>Összeg</span>
                    <strong><?= h($quoteTotal); ?></strong>
                </div>
            </div>

            <?php if ($flash !== null): ?><div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div><?php endif; ?>

            <?php if ($quoteFileUrl !== null): ?>
                <div class="quote-send-ready">
                    <div>
                        <strong>A legutóbbi PDF elérhető</strong>
                        <span>Megnyithatod ellenőrzésre, vagy készíthetsz új PDF-et az aktuális adatokból.</span>
                    </div>
                    <a class="button button-secondary" href="<?= h($quoteFileUrl); ?>" target="_blank">PDF megnyitása</a>
                </div>
            <?php endif; ?>

            <?php if ($feeRequestLine !== null): ?>
                <div class="quote-send-ready">
                    <div>
                        <strong>Díjbekérő tétel</strong>
                        <span><?= h((string) $feeRequestLine['name']); ?> · <?= h(format_money($feeRequestLine['line_gross'])); ?></span>
                    </div>
                    <?php if ($feeRequestFileUrl !== null): ?><a class="button button-secondary" href="<?= h($feeRequestFileUrl); ?>" target="_blank">Díjbekérő PDF</a><?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($feeRequestBlockedMessage !== null): ?>
                <div class="alert alert-info"><p><?= h($feeRequestBlockedMessage); ?></p></div>
            <?php endif; ?>

            <div class="quote-send-actions">
                <button class="button" type="button" data-quote-action="pdf" data-modal-title="PDF generálása" data-modal-text="Új PDF készül az aktuális ajánlatadatokból. A korábbi PDF frissülhet.">PDF generálása</button>
                <button class="button button-secondary" type="button" data-quote-action="send" data-modal-title="Email küldése PDF csatolmánnyal" data-modal-text="A rendszer elkészíti az aktuális PDF-et, majd elküldi az ügyfél email címére csatolmányként.">Email küldése PDF csatolmánnyal</button>
                <button class="button button-secondary" type="button" data-quote-action="fee_request" data-modal-title="Díjbekérő küldése" data-modal-text="A rendszer a kiválasztott ügykezelési díjról Számlázz.hu díjbekérőt készít, majd elküldi az ügyfél email címére."<?= $feeRequestBlockedMessage !== null ? ' disabled' : ''; ?>>Díjbekérő küldése</button>
            </div>

            <dialog class="quote-action-dialog" id="quoteActionDialog" aria-labelledby="quoteActionDialogTitle">
                <form class="quote-action-dialog-card" method="post" action="<?= h(url_path('/admin/quotes/send') . '?id=' . (int) $quote['id']); ?>">
                <?= csrf_field(); ?>
                    <input type="hidden" id="quoteActionInput" name="action" value="pdf">
                    <div>
                        <p class="eyebrow">Megerősítés</p>
                        <h2 id="quoteActionDialogTitle">PDF generálása</h2>
                        <p id="quoteActionDialogText">Új PDF készül az aktuális ajánlatadatokból.</p>
                    </div>
                    <div class="quote-action-dialog-summary">
                        <span><?= h((string) $quote['quote_number']); ?></span>
                        <strong><?= h((string) $quote['requester_name']); ?></strong>
                        <span><?= h((string) $quote['email']); ?></span>
                    </div>
                    <div class="quote-action-dialog-actions">
                        <button class="button button-secondary" id="quoteActionCancel" type="button">Mégsem</button>
                        <button class="button" type="submit">Indítás</button>
                    </div>
                </form>
            </dialog>
        </section>
    </div>
</section>

<script>
document.querySelectorAll('[data-quote-action]').forEach((button) => {
    button.addEventListener('click', () => {
        const dialog = document.getElementById('quoteActionDialog');
        const actionInput = document.getElementById('quoteActionInput');
        const title = document.getElementById('quoteActionDialogTitle');
        const text = document.getElementById('quoteActionDialogText');

        actionInput.value = button.dataset.quoteAction || 'pdf';
        title.textContent = button.dataset.modalTitle || 'Művelet indítása';
        text.textContent = button.dataset.modalText || 'Biztosan indítod a műveletet?';

        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
            return;
        }

        dialog.setAttribute('open', 'open');
    });
});

document.getElementById('quoteActionCancel')?.addEventListener('click', () => {
    const dialog = document.getElementById('quoteActionDialog');

    if (typeof dialog.close === 'function') {
        dialog.close();
        return;
    }

    dialog.removeAttribute('open');
});
</script>
