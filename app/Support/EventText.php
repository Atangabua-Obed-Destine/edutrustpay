<?php

namespace App\Support;

/**
 * The one line each sync event shows on a school's page, in plain words.
 *
 * The badge beside it already says Received, Refused or Corrected, so these
 * say what arrived and what it means, not which code path ran. Everything is
 * written once here so the API and the rewording migration cannot drift apart.
 */
final class EventText
{
    public static function report(string $period, int $sequence, string $status): string
    {
        return __('Monthly report for :month, version :n. :state', [
            'month' => period_text($period),
            'n' => $sequence,
            'state' => $status === 'final' ? __('The month is closed.') : __('The month is still open.'),
        ]);
    }

    public static function restated(string $period, int $sequence, int $previousSequence, string $previousStatus): string
    {
        return $previousStatus === 'final'
            ? __(':month sent again as version :n, replacing version :old, which had been closed.', [
                'month' => period_text($period), 'n' => $sequence, 'old' => $previousSequence,
            ])
            : __(':month sent again as version :n, replacing version :old.', [
                'month' => period_text($period), 'n' => $sequence, 'old' => $previousSequence,
            ]);
    }

    public static function sameVersionConflict(string $period, int $sequence): string
    {
        return __(':month sent again with different figures but the same version number (:n). It was refused and the version already here was kept.', [
            'month' => period_text($period), 'n' => $sequence,
        ]);
    }

    public static function daybook(string $period, int $sequence, int $lines): string
    {
        $entries = $lines === 0
            ? __('with no entries')
            : trans_choice(':count entry|:count entries', $lines, ['count' => $lines]);

        return $sequence > 1
            ? __('Daybook for :month, version :n, :entries.', ['month' => period_text($period), 'n' => $sequence, 'entries' => $entries])
            : __('Daybook for :month, :entries.', ['month' => period_text($period), 'entries' => $entries]);
    }

    public static function budgetSheet(string $period, int $sequence): string
    {
        return $sequence > 1
            ? __('Budget sheet for :month, version :n.', ['month' => period_text($period), 'n' => $sequence])
            : __('Budget sheet for :month.', ['month' => period_text($period)]);
    }

    /**
     * @param  string  $what  'report', 'daybook' or 'budget sheet'
     */
    public static function refused(string $what, string $code): string
    {
        $why = match ($code) {
            'malformed_json' => __('it could not be read'),
            'unsupported_contract_version' => __('it uses a report format this console does not accept'),
            'institution_mismatch' => __('it was addressed to a different school'),
            'invalid_payload', 'invalid_daybook', 'invalid_budget_sheet' => __('it did not pass the format checks'),
            default => __('it was not accepted (:code)', ['code' => $code]),
        };

        $thing = match ($what) {
            'daybook' => __('A daybook'),
            'budget sheet' => __('A budget sheet'),
            default => __('A monthly report'),
        };

        return __(':thing was refused because :why.', ['thing' => $thing, 'why' => $why]);
    }

    public static function badSignature(): string
    {
        return __('A message failed the signature check, so it was refused. It did not carry this school\'s current key.');
    }

    public static function keyReplaced(): string
    {
        return __('The school replaced its connection key.');
    }

    public static function couldNotProcess(string $period): string
    {
        return __('The report for :month arrived but could not be processed. The operator needs to look at it.', [
            'month' => period_text($period),
        ]);
    }

    public static function checkedIn(): string
    {
        return __('Checked in. Nothing new to send.');
    }

    /**
     * A check-in carries a note from the school's system. The notes our own
     * school systems send are put in plain words here, so a school still on an
     * older release reads the same; any other note is the school's own and is
     * shown as it was sent.
     */
    public static function heartbeat(mixed $note): string
    {
        $note = is_string($note) ? trim($note) : '';

        return match ($note) {
            '', 'Alive, nothing new to report.' => self::checkedIn(),
            'Alive.' => __('The school\'s system is running and in touch.'),
            'Alive, but reports are failing to deliver from this end.'
                => __('The school\'s system is running, but its reports are not getting through. The school should check its connection.'),
            'Connection test from the institution settings screen.', 'Connection test from the school settings screen.'
                => __('Someone at the school tested the connection from its settings page.'),
            'Credential check from edutrustpay:doctor.' => __('The school\'s technician checked its connection key.'),
            // The summary column holds 255 characters.
            default => mb_substr($note, 0, 255),
        };
    }
}
