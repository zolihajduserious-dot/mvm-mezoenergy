<?php
declare(strict_types=1);

require_role(['admin', 'specialist']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$requestIdFromQuery = filter_input(INPUT_GET, 'request_id', FILTER_VALIDATE_INT);
$minicrmItemId = filter_input(INPUT_GET, 'minicrm_item', FILTER_VALIDATE_INT);
$quote = $id ? find_quote($id) : null;

if ($quote === null) {
    set_flash('error', 'Az ajánlat nem található.');
    redirect('/admin/quotes');
}

$result = null;
$serviceFeeRequestId = $requestIdFromQuery ?: (int) ($quote['connection_request_id'] ?? 0);

if (is_post()) {
    require_valid_csrf_token();
    $action = (string) ($_POST['action'] ?? '');
    $feeType = (string) ($_POST['fee_type'] ?? '');
    $feeNote = trim((string) ($_POST['fee_note'] ?? ''));

    if ($action === 'service_fee_request') {
        if (!is_admin_user()) {
            $result = ['ok' => false, 'message' => 'Ezt a díjbekérőt csak admin indíthatja.'];
        } elseif ($serviceFeeRequestId <= 0 || service_fee_request_option($feeType) === null) {
            $result = ['ok' => false, 'message' => 'Hiányzó munka vagy ügykezelési díj típus.'];
        } else {
            $result = send_connection_request_service_fee_request($serviceFeeRequestId, $feeType, $feeNote);
        }
    } else {
        $result = match ($action) {
            'send' => send_quote_email((int) $quote['id']),
            'fee_request' => send_quote_fee_request_email((int) $quote['id'], $feeNote),
            default => generate_quote_pdf((int) $quote['id']),
        };
    }

    $message = (string) $result['message'];

    if (!$result['ok'] && preg_match('/dompdf|phpmailer|composer|vendor|smtp/i', $message)) {
        $message = 'A művelet jelenleg nem indítható. Kérlek próbáld újra később, vagy jelezd a weboldal karbantartójának.';
    }

    set_flash($result['ok'] ? 'success' : 'error', $message);
    $redirectUrl = '/admin/quotes/send?id=' . (int) $quote['id'];

    if ($serviceFeeRequestId > 0) {
        $redirectUrl .= '&request_id=' . $serviceFeeRequestId;
    }

    if ($minicrmItemId) {
        $redirectUrl .= '&minicrm_item=' . (int) $minicrmItemId;
    }

    redirect($redirectUrl);
}

$flash = get_flash();
$quoteTotal = quote_display_total($quote);
$quoteFileUrl = quote_file_is_available($quote) ? url_path('/admin/quotes/file') . '?id=' . (int) $quote['id'] : null;
$feeRequestSelection = quote_fee_request_selection((int) $quote['id']);
$feeRequestLine = is_array($feeRequestSelection['line'] ?? null) ? $feeRequestSelection['line'] : null;
$feeRequestFileUrl = quote_fee_request_file_is_available($quote) ? url_path('/admin/quotes/fee-request-file') . '?id=' . (int) $quote['id'] : null;
$feeRequestBlockedMessage = null;
$serviceFeeOptions = service_fee_request_options();
$quoteEditUrl = url_path('/admin/quotes/edit') . '?id=' . (int) $quote['id'];
$quoteSendActionUrl = url_path('/admin/quotes/send') . '?id=' . (int) $quote['id'];

if ($serviceFeeRequestId > 0) {
    $quoteSendActionUrl .= '&request_id=' . $serviceFeeRequestId;
}

if ($minicrmItemId) {
    $quoteEditUrl .= '&minicrm_item=' . (int) $minicrmItemId;
    $quoteSendActionUrl .= '&minicrm_item=' . (int) $minicrmItemId;
}

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
            <a class="button button-secondary" href="<?= h($quoteEditUrl); ?>">Vissza az ajánlathoz</a>
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

            <?php if (is_admin_user()): ?>
                <div class="quote-mini-list service-fee-request-list">
                    <?php foreach ($serviceFeeOptions as $feeType => $feeOption): ?>
                        <?php
                        $feeLine = service_fee_request_line((string) $feeType);
                        $serviceFeeFileUrl = $serviceFeeRequestId > 0 && connection_request_service_fee_request_file_is_available($serviceFeeRequestId, (string) $feeType)
                            ? url_path('/admin/minicrm-import/fee-request-file') . '?request_id=' . $serviceFeeRequestId . '&fee_type=' . rawurlencode((string) $feeType)
                            : null;
                        $serviceFeeBlockedMessage = null;

                        if ($serviceFeeRequestId <= 0) {
                            $serviceFeeBlockedMessage = 'Nincs kapcsolt munkaazonosító ehhez a díjbekérőhöz.';
                        } elseif (szamlazz_config_value('SZAMLAZZ_AGENT_KEY') === '') {
                            $serviceFeeBlockedMessage = 'Nincs beállítva a Számlázz.hu Agent kulcs.';
                        }
                        ?>
                        <article class="quote-mini-card service-fee-request-card">
                            <div>
                                <strong><?= h((string) $feeOption['label']); ?></strong>
                                <span><?= h((string) $feeOption['name']); ?></span>
                                <span><?= h(format_money((float) $feeOption['gross'])); ?> bruttó</span>
                            </div>
                            <div>
                                <span class="status-badge status-badge-accent">Ügykezelési díj</span>
                                <strong><?= $feeLine !== null ? h(format_money($feeLine['line_gross'])) : '-'; ?></strong>
                            </div>
                            <div class="inline-link-list">
                                <?php if ($serviceFeeFileUrl !== null): ?>
                                    <a href="<?= h($serviceFeeFileUrl); ?>" target="_blank">Díjbekérő PDF</a>
                                <?php else: ?>
                                    <button
                                        class="text-button"
                                        type="button"
                                        data-quote-action="service_fee_request"
                                        data-quote-fee-type="<?= h((string) $feeType); ?>"
                                        data-modal-title="<?= h((string) $feeOption['label']); ?> díjbekérő"
                                        data-modal-text="A rendszer <?= h(format_money((float) $feeOption['gross'])); ?> bruttó ügykezelési díjról Számlázz.hu díjbekérőt készít, majd elküldi az ügyfél email címére."
                                        <?= $serviceFeeBlockedMessage !== null ? 'disabled' : ''; ?>
                                    >Díjbekérő küldése</button>
                                    <?php if ($serviceFeeBlockedMessage !== null): ?><small><?= h($serviceFeeBlockedMessage); ?></small><?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="quote-send-actions">
                <button class="button" type="button" data-quote-action="pdf" data-modal-title="PDF generálása" data-modal-text="Új PDF készül az aktuális ajánlatadatokból. A korábbi PDF frissülhet.">PDF generálása</button>
                <button class="button button-secondary" type="button" data-quote-action="send" data-modal-title="Email küldése PDF csatolmánnyal" data-modal-text="A rendszer elkészíti az aktuális PDF-et, majd elküldi az ügyfél email címére csatolmányként.">Email küldése PDF csatolmánnyal</button>
                <button class="button button-secondary" type="button" data-quote-action="fee_request" data-modal-title="Díjbekérő küldése" data-modal-text="A rendszer a kiválasztott ügykezelési díjról Számlázz.hu díjbekérőt készít, majd elküldi az ügyfél email címére."<?= $feeRequestBlockedMessage !== null ? ' disabled' : ''; ?>>Díjbekérő küldése</button>
            </div>

            <dialog class="quote-action-dialog" id="quoteActionDialog" aria-labelledby="quoteActionDialogTitle">
                <form class="quote-action-dialog-card" method="post" action="<?= h($quoteSendActionUrl); ?>">
                <?= csrf_field(); ?>
                    <input type="hidden" id="quoteActionInput" name="action" value="pdf">
                    <input type="hidden" id="quoteFeeTypeInput" name="fee_type" value="">
                    <div>
                        <p class="eyebrow">Megerősítés</p>
                        <h2 id="quoteActionDialogTitle">PDF generálása</h2>
                        <p id="quoteActionDialogText">Új PDF készül az aktuális ajánlatadatokból.</p>
                        <label class="quote-action-note" id="quoteFeeNoteLabel" for="quoteFeeNoteInput" hidden>
                            Megjegyzés a díjbekérőre
                            <textarea id="quoteFeeNoteInput" name="fee_note" rows="3" placeholder="Példa: munka rövid leírása, cím, teljesítménybővítés..."></textarea>
                        </label>
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
        const feeTypeInput = document.getElementById('quoteFeeTypeInput');
        const feeNoteLabel = document.getElementById('quoteFeeNoteLabel');
        const feeNoteInput = document.getElementById('quoteFeeNoteInput');
        const title = document.getElementById('quoteActionDialogTitle');
        const text = document.getElementById('quoteActionDialogText');
        const action = button.dataset.quoteAction || 'pdf';

        actionInput.value = action;
        feeTypeInput.value = button.dataset.quoteFeeType || '';
        feeNoteLabel.hidden = !action.includes('fee_request');

        if (feeNoteLabel.hidden && feeNoteInput) {
            feeNoteInput.value = '';
        }

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
