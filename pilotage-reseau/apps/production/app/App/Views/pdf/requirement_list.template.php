<?php
/**
 * Template HTML do TCPDF: lista zapotrzebowania materiałowego.
 *
 * Dostępne zmienne:
 *  - array $rows
 *  - bool  $stockMinimum
 */
?>
<style>
    .meta { font-size: 11px; }
    table.req { border-collapse: collapse; width: 100%; font-size: 10px; }
    table.req th, table.req td { border: 1px solid #333; padding: 4px; }
    table.req th { background: #efefef; font-weight: bold; }
    .right { text-align: right; }
    .center { text-align: center; }
</style>

<div class="meta">
    <b>
        <?= $stockMinimum ? 'Uwzględniono stany magazynowe' : 'Nie uwzględniono stanów magazynowych' ?>
    </b>
</div>
<br>

<?php if (empty($rows)): ?>
    <div class="meta">Brak danych do wyświetlenia.</div>
<?php else: ?>
    <table class="req">
        <thead>
        <tr>
            <th style="width:45%">Materiał</th>
            <th style="width:10%">Jm</th>
            <th style="width:15%">Wymagane</th>
            <th style="width:15%">Na stanie</th>
            <th style="width:15%">Do zamówienia</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= htmlspecialchars((string)($r['material_name'] ?? '')) ?></td>
                <td class="center"><?= htmlspecialchars((string)($r['unit'] ?? '')) ?></td>
                <td class="right"><?= htmlspecialchars((string)($r['required'] ?? '')) ?></td>
                <td class="right"><?= htmlspecialchars((string)($r['in_stock'] ?? '')) ?></td>
                <td class="right"><?= htmlspecialchars((string)($r['to_order'] ?? '')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

