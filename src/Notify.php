<?php
declare(strict_types=1);

/** The emails Gatepass sends to buyers. Templates are in views/emails/. */
final class Notify
{
    /** The ticket email: one QR code per ticket, plus a calendar file. */
    public static function tickets(array $order, string $kind = 'tickets'): bool
    {
        $event = events()->findWithHostById((int) $order['event_id']);
        $tickets = orders()->tickets((int) $order['id']);
        if (!$event || !$tickets) {
            return false;
        }
        $url = ticket_url($order);

        $inline = [];
        foreach ($tickets as $i => $t) {
            $inline['qr' . $i] = ['data' => Qr::png($t['code'], 6, 3), 'type' => 'image/png'];
        }
        $note = 'Your ' . plural(count($tickets), 'ticket') . ' (order ' . $order['reference'] . '). Show the QR code at the entrance.'
              . ($event['online_url'] !== '' ? "\nJoin online: " . $event['online_url'] : '');
        $ics = Ics::event($event, $url, host(), $note);

        $subject = $kind === 'reminder'
            ? 'Tomorrow: ' . $event['title']
            : 'Your ' . (count($tickets) === 1 ? 'ticket' : 'tickets') . ' for ' . $event['title'];

        $html = partial('emails/tickets', compact('event', 'order', 'tickets', 'url', 'kind'));
        $text = self::text($event, $order, $tickets, $url, $kind);

        return mailer()->send($order['buyer_email'], $subject, $text, $html, $inline,
            ['event.ics' => ['data' => $ics, 'type' => 'text/calendar; charset=UTF-8; method=PUBLISH']]);
    }

    /** The cron job: release unpaid holds, send day-before reminders. */
    public static function runReminders(): array
    {
        $released = orders()->expireStale();
        $sent = 0;
        foreach (orders()->dueReminders((int) config('remind_hours')) as $order) {
            // Mark first: if the mail server is down we skip this one rather than retry forever.
            orders()->markReminded((int) $order['id']);
            if (self::tickets($order, 'reminder')) {
                $sent++;
            }
        }
        return ['released_orders' => $released, 'reminders_sent' => $sent];
    }

    public static function cancelled(array $event, array $order): bool
    {
        $html = partial('emails/cancelled', compact('event', 'order'));
        $text = "Hi {$order['buyer_name']},\n\n{$event['title']} (" . event_when($event) . ") has been cancelled by the organizer, {$event['host_name']}.\n\n"
              . ((int) $order['total'] > 0 ? "Your order {$order['reference']} was " . money($order['total']) . ". Reply to this email or contact the organizer about a refund.\n\n" : '')
              . "Sorry about that.\nGatepass";
        return mailer()->send($order['buyer_email'], 'Cancelled: ' . $event['title'], $text, $html);
    }

    private static function text(array $event, array $order, array $tickets, string $url, string $kind): string
    {
        $lines = [
            'Hi ' . $order['buyer_name'] . ',',
            '',
            $kind === 'reminder'
                ? $event['title'] . ' is coming up soon. Here are your tickets again.'
                : 'You\'re going to ' . $event['title'] . '!',
            '',
            'When:  ' . event_when($event),
            'Where: ' . ($event['venue'] !== '' ? $event['venue'] : 'Online'),
        ];
        if ($event['online_url'] !== '') {
            $lines[] = 'Join:  ' . $event['online_url'];
        }
        $lines[] = '';
        foreach ($tickets as $t) {
            $lines[] = '  ' . $t['type_name'] . '   ' . $t['code'];
        }
        $lines[] = '';
        $lines[] = 'Show the QR codes (or the codes above) at the entrance. Each ticket works once.';
        $lines[] = 'Your tickets online: ' . $url;
        $lines[] = 'Add it to your calendar: open the attached event.ics file.';
        $lines[] = '';
        $lines[] = 'Order ' . $order['reference'] . ' · ' . money($order['total']);
        $lines[] = 'Hosted by ' . $event['host_name'] . ' on Gatepass';
        return implode("\n", $lines);
    }
}
