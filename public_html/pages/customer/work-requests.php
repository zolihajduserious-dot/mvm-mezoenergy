<?php
declare(strict_types=1);

require_role(['customer']);

$customer = current_customer();

if ($customer === null) {
    set_flash('error', 'Előbb töltsd ki az ügyféladataidat.');
    redirect('/customer/profile');
}

$requests = connection_requests_for_customer((int) $customer['id']);
$documents = download_documents(true);
$flash = get_flash();
$requestStatusLabels = connection_request_status_labels();
$emailStatusLabels = [
    'pending' => 'Értesítésre vár',
    'sent' => 'Admin értesítve',
    'failed' => 'Küldési hiba',
];
$mvmThreadStatusLabels = mvm_email_thread_status_labels();
?>
<section class="admin-section">
    <div class="container">
        <div class="admin-header">
            <div>
                <p class="eyebrow">Ügyfélportál</p>
                <h1>Igényeim</h1>
                <p>Itt láthatod és módosíthatod a mérőhelyi igényeidet. Lezárás után az igény végleges, és már nem módosítható.</p>
            </div>
            <a class="button" href="<?= h(url_path('/customer/work-request')); ?>">Új igény rögzítése</a>
        </div>

        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div>
        <?php endif; ?>

        <?php if ($documents !== []): ?>
            <section class="download-panel">
                <div>
                    <h2>Letölthető dokumentumok</h2>
                    <p>Töltsd le, töltsd ki, majd az adott igény szerkesztésénél töltsd fel. A meghatalmazás később pótolható, és az adott igényből online is aláírható.</p>
                </div>
                <div class="inline-link-list">
                    <?php foreach ($documents as $document): ?>
                        <a href="<?= h(url_path('/documents/file') . '?id=' . (int) $document['id']); ?>" target="_blank"><?= h($document['title']); ?></a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($requests === []): ?>
            <div class="empty-state">
                <h2>Még nincs igény rögzítve</h2>
                <p>Az első mérőhelyi igényhez indíts új adatlapot. Mentheted piszkozatként is, és később folytathatod.</p>
                <a class="button" href="<?= h(url_path('/customer/work-request')); ?>">Új igény rögzítése</a>
            </div>
        <?php else: ?>
            <div class="portal-card-grid">
                <?php foreach ($requests as $request): ?>
                    <?php
                    $files = connection_request_files((int) $request['id']);
                    $quotes = quotes_for_connection_request((int) $request['id']);
                    $acceptedQuote = accepted_quote_for_connection_request((int) $request['id']);
                    $latestQuote = $quotes[0] ?? null;
                    $quoteState = quote_state_summary($latestQuote, $acceptedQuote, connection_request_quote_missing_reason($request));
                    $mvmEmailThreads = mvm_email_threads_with_messages((int) $request['id']);
                    $requestStatus = (string) ($request['request_status'] ?? 'finalized');
                    $emailStatus = (string) ($request['email_status'] ?? 'pending');
                    $isEditable = connection_request_is_editable($request);
                    $quoteStatusLabels = quote_status_labels();
                    ?>

                    <article class="portal-card">
                        <div class="portal-card-header">
                            <div>
                                <span class="portal-kicker"><?= $isEditable ? 'Utoljára mentve' : 'Lezárva'; ?>: <?= h($request['closed_at'] ?: $request['updated_at'] ?: $request['created_at']); ?></span>
                                <h2><?= h($request['project_name']); ?></h2>
                            </div>
                            <span class="status-badge status-badge-<?= h($requestStatus); ?>"><?= h($requestStatusLabels[$requestStatus] ?? $requestStatus); ?></span>
                        </div>

                        <?php if (!empty($request['email_error'])): ?>
                            <div class="alert alert-error"><p><?= h($request['email_error']); ?></p></div>
                        <?php endif; ?>

                        <div class="portal-card-details">
                            <div>
                                <span>Igénytípus</span>
                                <strong><?= h(connection_request_type_label($request['request_type'] ?? null)); ?></strong>
                            </div>
                            <div>
                                <span>Cím</span>
                                <strong><?= h($request['site_postal_code'] . ' ' . $request['site_address']); ?></strong>
                            </div>
                            <div>
                                <span>HRSZ</span>
                                <strong><?= h($request['hrsz'] ?: '-'); ?></strong>
                            </div>
                            <div>
                                <span>Mérő</span>
                                <strong><?= h($request['meter_serial'] ?: '-'); ?></strong>
                            </div>
                            <div>
                                <span>Fogyasztási hely</span>
                                <strong><?= h($request['consumption_place_id'] ?: '-'); ?></strong>
                            </div>
                        </div>

                        <div class="portal-card-details portal-card-details-compact">
                            <div>
                                <span>Meglévő mindennapszaki</span>
                                <strong><?= h($request['existing_general_power'] ?: '-'); ?></strong>
                            </div>
                            <div>
                                <span>Igényelt mindennapszaki</span>
                                <strong><?= h($request['requested_general_power'] ?: '-'); ?></strong>
                            </div>
                            <div>
                                <span>Meghatalmazás</span>
                                <strong><?= connection_request_has_file_type((int) $request['id'], 'authorization') ? 'Feltöltve' : 'Hiányzik'; ?></strong>
                            </div>
                            <div>
                                <span>Admin értesítés</span>
                                <strong><?= h($emailStatusLabels[$emailStatus] ?? $emailStatus); ?></strong>
                            </div>
                        </div>

                        <div class="quote-state-card quote-state-card-<?= h((string) $quoteState['class']); ?>">
                            <div>
                                <span class="portal-kicker">Árajánlat állapota</span>
                                <strong><?= h((string) $quoteState['title']); ?></strong>
                                <p><?= h((string) $quoteState['description']); ?></p>
                            </div>
                            <strong><?= h((string) $quoteState['amount']); ?></strong>
                        </div>

                        <?php if ($files !== []): ?>
                            <div class="portal-card-files">
                                <h3>Kapcsolódó fájlok</h3>
                                <div class="file-preview-grid">
                                    <?php foreach ($files as $file): ?>
                                        <?php
                                        $fileUrl = url_path('/customer/work-requests/file') . '?id=' . (int) $file['id'];
                                        $previewKind = portal_file_preview_kind($file);
                                        ?>
                                        <article class="file-preview-card file-preview-card-<?= h($previewKind); ?>">
                                            <div class="file-preview-media">
                                                <?php if ($previewKind === 'image'): ?>
                                                    <a href="<?= h($fileUrl); ?>" target="_blank" aria-label="<?= h($file['label']); ?> megnyitása">
                                                        <img src="<?= h($fileUrl); ?>" alt="<?= h($file['label']); ?>" width="92" height="92" loading="lazy">
                                                    </a>
                                                <?php elseif ($previewKind === 'pdf'): ?>
                                                    <iframe src="<?= h($fileUrl); ?>#toolbar=0&navpanes=0" title="<?= h($file['label']); ?>" width="92" height="92" loading="lazy"></iframe>
                                                <?php else: ?>
                                                    <div class="file-preview-fallback">
                                                        <span><?= h(portal_file_preview_extension($file)); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="file-preview-meta">
                                                <strong><?= h($file['label']); ?></strong>
                                                <span><?= h($file['original_name']); ?></span>
                                                <a href="<?= h($fileUrl); ?>" target="_blank">Megnyitás</a>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($quotes !== []): ?>
                            <div class="portal-card-files">
                                <h3>Kapcsolódó árajánlat</h3>
                                <div class="quote-mini-list">
                                    <?php foreach ($quotes as $quote): ?>
                                        <?php $quoteStatus = (string) ($quote['status'] ?? 'draft'); ?>
                                        <article class="quote-mini-card">
                                            <div>
                                                <strong><?= h((string) $quote['quote_number']); ?></strong>
                                                <span><?= h((string) $quote['subject']); ?></span>
                                                <?php if (!empty($quote['accepted_at'])): ?>
                                                    <span>Elfogadva: <?= h((string) $quote['accepted_at']); ?></span>
                                                <?php elseif (!empty($quote['consultation_requested_at'])): ?>
                                                    <span>Egyeztetés kérve: <?= h((string) $quote['consultation_requested_at']); ?></span>
                                                <?php elseif (!empty($quote['sent_at'])): ?>
                                                    <span>Kiküldve: <?= h((string) $quote['sent_at']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <span class="status-badge status-badge-<?= h($quoteStatus); ?>"><?= h($quoteStatusLabels[$quoteStatus] ?? $quoteStatus); ?></span>
                                                <strong><?= h(quote_display_total($quote)); ?></strong>
                                            </div>
                                            <div class="inline-link-list">
                                                <a href="<?= h(url_path('/customer/quotes/view') . '?id=' . (int) $quote['id']); ?>">Megnyitás</a>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($mvmEmailThreads !== []): ?>
                            <div class="portal-card-files">
                                <h3>MVM levelezés</h3>
                                <div class="mvm-mail-thread-list mvm-mail-thread-list-compact">
                                    <?php foreach ($mvmEmailThreads as $thread): ?>
                                        <article class="mvm-mail-thread">
                                            <div class="mvm-mail-thread-head">
                                                <div>
                                                    <span class="portal-kicker"><?= h((string) $thread['document_label']); ?></span>
                                                    <strong><?= h($mvmThreadStatusLabels[$thread['status']] ?? (string) $thread['status']); ?></strong>
                                                </div>
                                                <span><?= h((string) ($thread['last_message_at'] ?: $thread['created_at'])); ?></span>
                                            </div>
                                            <p><?= h(latest_mvm_email_message_preview($thread)); ?></p>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="portal-card-files">
                            <h3>Műveletek</h3>
                            <div class="inline-link-list">
                                <a href="<?= h(authorization_signature_url($request)); ?>" target="_blank">Meghatalmazás online aláírása</a>
                                <?php if ($isEditable): ?>
                                    <a href="<?= h(url_path('/customer/work-request') . '?id=' . (int) $request['id']); ?>">Módosítás és feltöltés</a>
                                <?php else: ?>
                                    <span class="status-badge status-badge-finalized">Lezárt igény</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
