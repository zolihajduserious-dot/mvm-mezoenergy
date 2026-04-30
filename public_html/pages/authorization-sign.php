<?php
declare(strict_types=1);

$requestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$token = trim((string) ($_GET['token'] ?? ''));
$request = $requestId ? find_connection_request($requestId) : null;

if ($request === null || !authorization_signature_token_is_valid($request, $token)) {
    http_response_code(404);
    require PAGE_PATH . '/404.php';
    return;
}

$errors = [];
$flash = get_flash();

if (is_post()) {
    require_valid_csrf_token();

    try {
        save_signed_authorization_document((int) $request['id'], $_POST);
        send_admin_activity_notification(
            'Elektronikusan aláírt meghatalmazás érkezett',
            'Az ügyfél a nyilvános meghatalmazás linken elektronikusan aláírta a meghatalmazást.',
            [
                [
                    'title' => 'Igény adatai',
                    'rows' => [
                        ['label' => 'Igény', 'value' => $request['project_name'] ?? '-'],
                        ['label' => 'Ügyfél', 'value' => ($request['requester_name'] ?? '-') . "\n" . ($request['email'] ?? '-') . "\n" . ($request['phone'] ?? '-')],
                        ['label' => 'Cím', 'value' => trim((string) ($request['site_postal_code'] ?? '') . ' ' . (string) ($request['site_address'] ?? ''))],
                    ],
                ],
            ],
            [
                ['label' => 'Munka megnyitása', 'url' => absolute_url('/admin/minicrm-import?request=' . (int) $request['id'] . '#portal-work-' . (int) $request['id'])],
            ],
            ['email' => $request['email'] ?? '', 'name' => $request['requester_name'] ?? ''],
            null,
            'Nyilvános meghatalmazás aláírás'
        );
        set_flash('success', 'Az aláírt meghatalmazást elmentettük az igényhez.');
        redirect('/authorization-sign?id=' . (int) $request['id'] . '&token=' . rawurlencode($token));
    } catch (Throwable $exception) {
        $errors[] = APP_DEBUG ? $exception->getMessage() : 'Az aláírt meghatalmazás mentése sikertelen.';
    }
}

$authorizationDone = connection_request_has_file_type((int) $request['id'], 'authorization');
?>
<section class="content-section authorization-sign-section">
    <div class="container">
        <div class="section-heading">
            <p class="eyebrow">Meghatalmazás</p>
            <h1>Elektronikus aláírás</h1>
            <p>Telefonon ujjal, számítógépen egérrel írható alá. Az ügyfél adatait a rendszer automatikusan ráteszi az MVM meghatalmazásra, majd az ügyfél és két tanú aláírása után PDF-ként menti az igényhez.</p>
        </div>

        <?php if ($flash !== null): ?>
            <div class="alert alert-<?= h((string) $flash['type']); ?>"><p><?= h((string) $flash['message']); ?></p></div>
        <?php endif; ?>

        <?php if ($authorizationDone): ?>
            <div class="alert alert-success"><p>Ehhez az igényhez már van rögzített meghatalmazás. Új aláírás beküldésével új verzió készül.</p></div>
        <?php endif; ?>

        <?php if ($errors !== []): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?><p><?= h($error); ?></p><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <section class="auth-panel form-block">
            <h2>Igény adatai</h2>
            <dl class="admin-request-data-list">
                <div>
                    <dt>Ügyfél</dt>
                    <dd><?= h((string) ($request['requester_name'] ?? '-')); ?></dd>
                </div>
                <div>
                    <dt>Email</dt>
                    <dd><?= h((string) ($request['email'] ?? '-')); ?></dd>
                </div>
                <div>
                    <dt>Telefonszám</dt>
                    <dd><?= h((string) ($request['phone'] ?? '-')); ?></dd>
                </div>
                <div>
                    <dt>Felhasználási hely</dt>
                    <dd><?= h(trim((string) ($request['site_postal_code'] ?? '') . ' ' . (string) ($request['site_address'] ?? '')) ?: '-'); ?></dd>
                </div>
            </dl>
        </section>

        <form class="form authorization-sign-form" method="post" action="<?= h(url_path('/authorization-sign') . '?id=' . (int) $request['id'] . '&token=' . rawurlencode($token)); ?>">
            <?= csrf_field(); ?>

            <section class="auth-panel form-block">
                <h2>Tanúk adatai</h2>
                <div class="form-grid two">
                    <div>
                        <label for="witness_1_name">1. tanú neve</label>
                        <input id="witness_1_name" name="witness_1_name" required>
                    </div>
                    <div>
                        <label for="witness_1_address">1. tanú címe</label>
                        <input id="witness_1_address" name="witness_1_address" required>
                    </div>
                    <div>
                        <label for="witness_2_name">2. tanú neve</label>
                        <input id="witness_2_name" name="witness_2_name" required>
                    </div>
                    <div>
                        <label for="witness_2_address">2. tanú címe</label>
                        <input id="witness_2_address" name="witness_2_address" required>
                    </div>
                </div>
            </section>

            <section class="auth-panel form-block">
                <h2>Aláírások</h2>
                <div class="signature-pad-grid">
                    <div class="signature-pad-card" data-signature-pad>
                        <div>
                            <label>Ügyfél aláírása</label>
                            <button class="button button-secondary" type="button" data-signature-clear>Újra</button>
                        </div>
                        <canvas aria-label="Ügyfél aláírása"></canvas>
                        <input type="hidden" name="customer_signature" data-signature-input required>
                    </div>

                    <div class="signature-pad-card" data-signature-pad>
                        <div>
                            <label>1. tanú aláírása</label>
                            <button class="button button-secondary" type="button" data-signature-clear>Újra</button>
                        </div>
                        <canvas aria-label="1. tanú aláírása"></canvas>
                        <input type="hidden" name="witness_1_signature" data-signature-input required>
                    </div>

                    <div class="signature-pad-card" data-signature-pad>
                        <div>
                            <label>2. tanú aláírása</label>
                            <button class="button button-secondary" type="button" data-signature-clear>Újra</button>
                        </div>
                        <canvas aria-label="2. tanú aláírása"></canvas>
                        <input type="hidden" name="witness_2_signature" data-signature-input required>
                    </div>
                </div>
                <p class="muted-text">Az aláírás mezőbe ujjal vagy egérrel lehet írni. A mentés után az eredeti MVM meghatalmazás kitöltött, aláírt PDF-je az igény dokumentumai közé kerül.</p>
            </section>

            <div class="form-actions">
                <button class="button" type="submit">Aláírt meghatalmazás mentése</button>
            </div>
        </form>
    </div>
</section>

<script>
(function () {
    const form = document.querySelector('.authorization-sign-form');

    if (!form) {
        return;
    }

    const setupPad = (pad) => {
        const canvas = pad.querySelector('canvas');
        const input = pad.querySelector('[data-signature-input]');
        const clear = pad.querySelector('[data-signature-clear]');
        const context = canvas.getContext('2d');
        let drawing = false;
        let hasInk = false;

        const resize = () => {
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            const rect = canvas.getBoundingClientRect();
            canvas.width = Math.max(1, Math.floor(rect.width * ratio));
            canvas.height = Math.max(1, Math.floor(rect.height * ratio));
            context.setTransform(ratio, 0, 0, ratio, 0, 0);
            context.lineWidth = 2.2;
            context.lineCap = 'round';
            context.lineJoin = 'round';
            context.strokeStyle = '#101827';
            context.fillStyle = '#ffffff';
            context.fillRect(0, 0, rect.width, rect.height);
            input.value = '';
            hasInk = false;
        };

        const point = (event) => {
            const rect = canvas.getBoundingClientRect();

            return {
                x: event.clientX - rect.left,
                y: event.clientY - rect.top,
            };
        };

        const start = (event) => {
            event.preventDefault();
            drawing = true;
            const p = point(event);
            context.beginPath();
            context.arc(p.x, p.y, 0.8, 0, Math.PI * 2);
            context.fill();
            context.beginPath();
            context.moveTo(p.x, p.y);
            hasInk = true;
            input.value = canvas.toDataURL('image/png');
        };

        const move = (event) => {
            if (!drawing) {
                return;
            }

            event.preventDefault();
            const p = point(event);
            context.lineTo(p.x, p.y);
            context.stroke();
            hasInk = true;
            input.value = canvas.toDataURL('image/png');
        };

        const stop = () => {
            if (!drawing) {
                return;
            }

            drawing = false;
            input.value = hasInk ? canvas.toDataURL('image/png') : '';
        };

        resize();
        canvas.addEventListener('pointerdown', start);
        canvas.addEventListener('pointermove', move);
        canvas.addEventListener('pointerup', stop);
        canvas.addEventListener('pointerleave', stop);
        clear?.addEventListener('click', resize);

        return {input, hasInk: () => hasInk};
    };

    const pads = Array.from(document.querySelectorAll('[data-signature-pad]')).map(setupPad);

    form.addEventListener('submit', (event) => {
        const missing = pads.some((pad) => !pad.hasInk() || !pad.input.value);

        if (missing) {
            event.preventDefault();
            alert('Mindhárom aláírás kötelező.');
        }
    });
})();
</script>
