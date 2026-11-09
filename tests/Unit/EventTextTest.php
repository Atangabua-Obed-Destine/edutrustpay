<?php

namespace Tests\Unit;

use App\Support\EventText;
use Tests\TestCase;

class EventTextTest extends TestCase
{
    public function test_a_report_line_says_the_month_the_version_and_whether_it_is_closed(): void
    {
        $this->assertSame('Monthly report for October 2026, version 5. The month is still open.', EventText::report('2026-10', 5, 'provisional'));
        $this->assertSame('Monthly report for August 2026, version 4. The month is closed.', EventText::report('2026-08', 4, 'final'));
    }

    public function test_a_correction_only_mentions_closing_when_the_replaced_version_was_closed(): void
    {
        $this->assertSame('October 2026 sent again as version 5, replacing version 4.', EventText::restated('2026-10', 5, 4, 'provisional'));
        $this->assertSame('August 2026 sent again as version 5, replacing version 4, which had been closed.', EventText::restated('2026-08', 5, 4, 'final'));
    }

    public function test_daybook_entries_are_counted_in_words_that_agree(): void
    {
        $this->assertSame('Daybook for October 2026, 1 entry.', EventText::daybook('2026-10', 1, 1));
        $this->assertSame('Daybook for October 2026, 7 entries.', EventText::daybook('2026-10', 1, 7));
        $this->assertSame('Daybook for September 2025, with no entries.', EventText::daybook('2025-09', 1, 0));
        $this->assertSame('Daybook for October 2026, version 2, 7 entries.', EventText::daybook('2026-10', 2, 7));
    }

    public function test_our_school_systems_check_in_notes_read_plainly_and_any_other_note_is_kept(): void
    {
        $this->assertSame('Checked in. Nothing new to send.', EventText::heartbeat(null));
        $this->assertSame('Checked in. Nothing new to send.', EventText::heartbeat(['not' => 'text']));
        $this->assertStringContainsString('reports are not getting through', EventText::heartbeat('Alive, but reports are failing to deliver from this end.'));
        $this->assertSame('Generator on, back after the storm.', EventText::heartbeat('  Generator on, back after the storm. '));
        $this->assertSame(255, mb_strlen(EventText::heartbeat(str_repeat('é', 400))));
    }

    public function test_a_refusal_says_why_and_keeps_an_unknown_code_visible(): void
    {
        $this->assertSame('A daybook was refused because it was addressed to a different school.', EventText::refused('daybook', 'institution_mismatch'));
        $this->assertSame('A monthly report was refused because it was not accepted (something_new).', EventText::refused('report', 'something_new'));
    }
}
