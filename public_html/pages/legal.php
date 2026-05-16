<?php
declare(strict_types=1);

$legalDocuments = [
    'adatkezelesi-tajekoztato' => [
        'title' => 'Adatkezelési tájékoztató',
        'file' => PUBLIC_ROOT . '/legal/adatkezelesi-tajekoztato.md',
        'description' => 'A Mező Energy CRM és mérőhelyi ügyintézési rendszer adatkezelési tájékoztatója.',
    ],
    'aszf' => [
        'title' => 'Általános szerződési feltételek',
        'file' => PUBLIC_ROOT . '/legal/aszf.md',
        'description' => 'A Mező Energy CRM használatára vonatkozó általános szerződési feltételek.',
    ],
    'adatfeldolgozoi-megallapodas' => [
        'title' => 'Adatfeldolgozói megállapodás',
        'file' => PUBLIC_ROOT . '/legal/adatfeldolgozoi-megallapodas.md',
        'description' => 'Adatfeldolgozói feltételek külső szerelők és generálkivitelezők saját ügyféladataihoz.',
    ],
];

$legalKey = match (current_route()) {
    'adatkezelesi-tajekoztato' => 'adatkezelesi-tajekoztato',
    'aszf' => 'aszf',
    'adatfeldolgozoi-megallapodas' => 'adatfeldolgozoi-megallapodas',
    default => 'adatkezelesi-tajekoztato',
};
$legalDocument = $legalDocuments[$legalKey];
$legalMarkdown = is_file($legalDocument['file']) ? (string) file_get_contents($legalDocument['file']) : '';

function legal_inline_markdown(string $text): string
{
    $html = h($text);
    $html = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $html) ?? $html;
    $html = preg_replace('/(https?:\/\/[^\s<]+)/u', '<a href="$1" target="_blank" rel="noopener">$1</a>', $html) ?? $html;

    return $html;
}

function legal_render_table(array $rows): string
{
    if ($rows === []) {
        return '';
    }

    $parsedRows = [];

    foreach ($rows as $row) {
        $cells = array_map('trim', explode('|', trim($row, '| ')));

        if ($cells !== [] && count(array_filter($cells, static fn (string $cell): bool => trim($cell, ' -:') !== '')) === 0) {
            continue;
        }

        $parsedRows[] = $cells;
    }

    if ($parsedRows === []) {
        return '';
    }

    $header = array_shift($parsedRows);
    $html = '<div class="legal-table-wrap"><table class="legal-table"><thead><tr>';

    foreach ($header as $cell) {
        $html .= '<th>' . legal_inline_markdown($cell) . '</th>';
    }

    $html .= '</tr></thead><tbody>';

    foreach ($parsedRows as $row) {
        $html .= '<tr>';

        foreach ($row as $cell) {
            $html .= '<td>' . legal_inline_markdown($cell) . '</td>';
        }

        $html .= '</tr>';
    }

    return $html . '</tbody></table></div>';
}

function legal_render_markdown(string $markdown): string
{
    $lines = preg_split('/\R/u', str_replace(["\r\n", "\r"], "\n", $markdown)) ?: [];
    $html = '';
    $listType = null;
    $tableRows = [];

    $closeList = static function () use (&$html, &$listType): void {
        if ($listType !== null) {
            $html .= '</' . $listType . '>';
            $listType = null;
        }
    };
    $flushTable = static function () use (&$html, &$tableRows): void {
        if ($tableRows !== []) {
            $html .= legal_render_table($tableRows);
            $tableRows = [];
        }
    };

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($trimmed === '') {
            $flushTable();
            $closeList();
            continue;
        }

        if (str_starts_with($trimmed, '|') && str_ends_with($trimmed, '|')) {
            $closeList();
            $tableRows[] = $trimmed;
            continue;
        }

        $flushTable();

        if (preg_match('/^(#{1,4})\s+(.+)$/u', $trimmed, $matches)) {
            $closeList();
            $level = min(4, strlen($matches[1]) + 1);
            $html .= '<h' . $level . '>' . legal_inline_markdown($matches[2]) . '</h' . $level . '>';
            continue;
        }

        if (str_starts_with($trimmed, '>')) {
            $closeList();
            $html .= '<blockquote><p>' . legal_inline_markdown(trim(substr($trimmed, 1))) . '</p></blockquote>';
            continue;
        }

        if (preg_match('/^-\s+(.+)$/u', $trimmed, $matches)) {
            if ($listType !== 'ul') {
                $closeList();
                $listType = 'ul';
                $html .= '<ul>';
            }

            $html .= '<li>' . legal_inline_markdown($matches[1]) . '</li>';
            continue;
        }

        if (preg_match('/^\d+\.\s+(.+)$/u', $trimmed, $matches)) {
            if ($listType !== 'ol') {
                $closeList();
                $listType = 'ol';
                $html .= '<ol>';
            }

            $html .= '<li>' . legal_inline_markdown($matches[1]) . '</li>';
            continue;
        }

        $closeList();
        $html .= '<p>' . legal_inline_markdown(rtrim($trimmed, ' ')) . '</p>';
    }

    $flushTable();
    $closeList();

    return $html;
}
?>
<section class="admin-section legal-page">
    <div class="container legal-container">
        <div class="admin-header legal-header">
            <div>
                <p class="eyebrow">Jogi dokumentumok</p>
                <h1><?= h($legalDocument['title']); ?></h1>
                <p><?= h($legalDocument['description']); ?></p>
            </div>
            <div class="legal-actions">
                <a class="button button-secondary" href="<?= h(url_path('/adatkezelesi-tajekoztato')); ?>">Adatkezelés</a>
                <a class="button button-secondary" href="<?= h(url_path('/aszf')); ?>">ÁSZF</a>
                <a class="button button-secondary" href="<?= h(url_path('/adatfeldolgozoi-megallapodas')); ?>">Adatfeldolgozás</a>
            </div>
        </div>

        <?php if ($legalMarkdown === ''): ?>
            <div class="alert alert-error"><p>A jogi dokumentum fájl nem található.</p></div>
        <?php else: ?>
            <article class="legal-document">
                <?= legal_render_markdown($legalMarkdown); ?>
            </article>
        <?php endif; ?>
    </div>
</section>
