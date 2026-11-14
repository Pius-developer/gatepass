<?php /** @var array $event @var array $order */ ?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Cancelled</title></head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;color:#1c1c1f;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:28px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:16px;">
    <tr><td style="padding:28px;">
        <p style="margin:0 0 6px;font-size:13px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#c4282d;">Event cancelled</p>
        <h1 style="margin:0 0 16px;font-size:24px;line-height:1.2;color:#000;"><?= e($event['title']) ?></h1>
        <p style="margin:0 0 12px;font-size:15px;line-height:1.6;">Hi <?= e($order['buyer_name']) ?>, the organizer (<?= e($event['host_name']) ?>) has cancelled this event, which was set for <?= e(event_when($event)) ?>. Your tickets won't be needed.</p>
        <?php if ((int) $order['total'] > 0): ?>
        <p style="margin:0 0 12px;font-size:15px;line-height:1.6;">Your order <strong><?= e($order['reference']) ?></strong> was <?= e(money($order['total'])) ?>. Reply to this email or contact the organizer about a refund.</p>
        <?php endif; ?>
        <p style="margin:0;font-size:15px;">Sorry about that.</p>
    </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
