<?php
/** @var array $event @var array $order @var array $tickets @var string $url @var string $kind */
$coral = '#E8483B';
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title><?= e($event['title']) ?></title></head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;color:#1c1c1f;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:28px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">

    <tr><td style="padding:0 4px 16px;font-size:15px;font-weight:800;letter-spacing:-0.02em;color:#111;">
        <span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:<?= $coral ?>;margin-right:6px;"></span>Gatepass
    </td></tr>

    <tr><td style="background:#ffffff;border-radius:16px;padding:28px 28px 8px;">
        <p style="margin:0 0 6px;font-size:13px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:<?= $coral ?>;">
            <?= $kind === 'reminder' ? 'Coming up soon' : 'You\'re going!' ?>
        </p>
        <h1 style="margin:0 0 18px;font-size:26px;line-height:1.15;letter-spacing:-0.02em;color:#000;"><?= e($event['title']) ?></h1>

        <table role="presentation" cellpadding="0" cellspacing="0" style="font-size:15px;line-height:1.5;margin-bottom:8px;">
            <tr><td style="color:#6b6b73;padding:2px 14px 2px 0;vertical-align:top;">When</td><td style="color:#111;"><?= e(event_when($event)) ?></td></tr>
            <tr><td style="color:#6b6b73;padding:2px 14px 2px 0;vertical-align:top;">Where</td><td style="color:#111;"><?= e($event['venue'] !== '' ? $event['venue'] : 'Online') ?></td></tr>
            <?php if ($event['online_url'] !== ''): ?>
            <tr><td style="color:#6b6b73;padding:2px 14px 2px 0;vertical-align:top;">Join</td><td><a href="<?= e($event['online_url']) ?>" style="color:<?= $coral ?>;"><?= e($event['online_url']) ?></a></td></tr>
            <?php endif; ?>
            <tr><td style="color:#6b6b73;padding:2px 14px 2px 0;vertical-align:top;">Host</td><td style="color:#111;"><?= e($event['host_name']) ?></td></tr>
        </table>

        <p style="margin:16px 0 0;font-size:14px;color:#3a3a40;">
            📅 <strong>Get a reminder on your phone:</strong> open the attached <em>event.ics</em> file and tap "Add to calendar".
        </p>
    </td></tr>

    <?php foreach ($tickets as $i => $t): ?>
    <tr><td style="background:#ffffff;border-top:2px dashed #e4e4e7;padding:22px 28px;<?= $i === count($tickets) - 1 ? 'border-radius:0 0 16px 16px;' : '' ?>">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
            <td style="vertical-align:middle;">
                <p style="margin:0;font-size:12px;color:#6b6b73;text-transform:uppercase;letter-spacing:.06em;">Ticket <?= $i + 1 ?> of <?= count($tickets) ?></p>
                <p style="margin:4px 0 10px;font-size:18px;font-weight:700;color:#000;"><?= e($t['type_name']) ?></p>
                <p style="margin:0;font:600 17px/1 ui-monospace,Menlo,Consolas,monospace;letter-spacing:.08em;color:#111;"><?= e($t['code']) ?></p>
            </td>
            <td width="132" style="vertical-align:middle;text-align:right;">
                <img src="cid:qr<?= $i ?>" width="132" height="132" alt="QR code for ticket <?= e($t['code']) ?>" style="display:block;border:0;">
            </td>
        </tr></table>
    </td></tr>
    <?php endforeach; ?>

    <tr><td style="padding:22px 4px 0;text-align:center;">
        <a href="<?= e($url) ?>" style="display:inline-block;background:<?= $coral ?>;color:#fff;font-weight:700;font-size:15px;text-decoration:none;padding:13px 22px;border-radius:10px;">Open my tickets</a>
        <p style="margin:18px 0 0;font-size:13px;line-height:1.6;color:#6b6b73;">
            Show the QR code at the entrance. Each ticket can be scanned once, so don't share it.<br>
            Order <?= e($order['reference']) ?> · <?= e(money($order['total'])) ?> · <?= e($order['buyer_name']) ?>
        </p>
        <p style="margin:14px 0 0;font-size:12px;color:#9a9aa2;">Sent by Gatepass for <?= e($event['host_name']) ?>.</p>
    </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
