<?php
/**
 * Health Check Page - Demonstrates SPA Navigation
 *
 * @var \App\View\AppView $this
 * @var string $status
 * @var array $checks
 */

$this->assign('title', 'Health Check');
?>

<div style="max-width: 600px; margin: 40px auto;">
    <h1>Health Check</h1>

    <div style="background: <?= $status === 'healthy' ? '#d4edda' : '#f8d7da' ?>; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
        <strong>Status:</strong> <?= h(ucfirst($status)) ?>
    </div>

    <table style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr style="background: #f5f5f5;">
                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #ddd;">Check</th>
                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #ddd;">Value</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($checks as $check => $value): ?>
            <tr>
                <td style="padding: 12px; border-bottom: 1px solid #ddd;"><?= h(ucfirst($check)) ?></td>
                <td style="padding: 12px; border-bottom: 1px solid #ddd;"><?= h($value) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div style="margin-top: 30px;">
        <?= $this->Spa->navLink('Back to Home', '/', ['class' => 'btn btn-primary']) ?>
    </div>
</div>
