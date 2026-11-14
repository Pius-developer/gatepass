<?php
declare(strict_types=1);

/**
 * Calendar files (.ics). Opening one on an iPhone or Android phone offers to add
 * the event to the calendar, with the reminder alarms below. No app needed.
 */
final class Ics
{
    /**
     * @param array $event row from events (+ host_name)
     * @param string $url  link shown in the calendar entry (the buyer's tickets, or the event page)
     */
    public static function event(array $event, string $url, string $uidHost, string $extraNote = ''): string
    {
        $location = $event['venue'] !== '' ? $event['venue'] : 'Online';
        $description = trim($extraNote . "\n\n" . 'Event page and tickets: ' . $url);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Gatepass//Tickets//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:event-' . $event['id'] . '@' . $uidHost,
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART:' . self::utc($event['starts_at']),
            'DTEND:' . self::utc($event['ends_at']),
            'SUMMARY:' . self::escape($event['title']),
            'LOCATION:' . self::escape($location),
            'DESCRIPTION:' . self::escape($description),
            'URL:' . $url,
            'STATUS:' . ($event['status'] === 'cancelled' ? 'CANCELLED' : 'CONFIRMED'),
            // Two reminders: the day before, and an hour before.
            'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:' . self::escape($event['title'] . ' is tomorrow'), 'TRIGGER:-P1D', 'END:VALARM',
            'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:' . self::escape($event['title'] . ' starts in 1 hour'), 'TRIGGER:-PT1H', 'END:VALARM',
            'END:VEVENT',
            'END:VCALENDAR',
        ];
        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    /** Google Calendar "add event" link, for people who use Google Calendar on the web. */
    public static function googleLink(array $event, string $url): string
    {
        return 'https://calendar.google.com/calendar/render?' . http_build_query([
            'action'   => 'TEMPLATE',
            'text'     => $event['title'],
            'dates'    => self::utc($event['starts_at']) . '/' . self::utc($event['ends_at']),
            'details'  => 'Tickets: ' . $url,
            'location' => $event['venue'] !== '' ? $event['venue'] : 'Online',
        ]);
    }

    private static function utc(string $sqlUtc): string
    {
        return gmdate('Ymd\THis\Z', strtotime($sqlUtc . ' UTC'));
    }

    private static function escape(string $s): string
    {
        return str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], $s);
    }

    /** Lines longer than 75 bytes must be folded (RFC 5545), without splitting a UTF-8 character. */
    private static function fold(string $line): string
    {
        $out = '';
        $current = '';
        foreach (mb_str_split($line) as $ch) {
            if (strlen($current . $ch) > 74) {
                $out .= $current . "\r\n ";
                $current = '';
            }
            $current .= $ch;
        }
        return $out . $current;
    }
}
