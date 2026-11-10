@extends('layouts.console')

@section('title', __('How this console works'))
@section('subtitle', $body?->name)

@php
    $user = auth()->user();
    $one = __('school');

    // Each page is described only if this person can open it.
    $pages = array_values(array_filter([
        [__('Overview'), route('body.dashboard'), __('Where you land. The key figures, each school\'s report for the month, what is worth asking about, and the money, school by school. Change the month at the top right.'), true],
        [__('Compliance'), route('body.compliance.index'), __('Your rules, such as the fees each school may charge, checked against what each school reports. It shows which rule is out of line, at which school, and by how much.'), $user->can('compliance.view')],
        [__('To look into'), route('body.findings.index'), __('Things the system noticed and cannot explain, most serious first. Each one says what was seen and what to ask. Record the answer once you know what happened.'), $user->can('finding.view')],
        [__('All schools together'), route('body.consolidation'), __('Every school\'s income, spending and cash added up for a month, with a version you can print or save as a PDF.'), $user->can('consolidation.view')],
        [__('All institutions'), route('body.institutions.index'), __('The list of your schools. Open one for its own page: the month in full, the year so far, its budget sheet and its daybook.'), $user->can('institution.view')],
        [__('Budgets compared'), route('body.budgets.compare'), __('Every school\'s budget for the year on its totals, side by side: income, spending and capital, budgeted against what has come in or gone out, and how much of the year has gone.'), $user->can('consolidation.view')],
        [__('Compare schools'), route('body.daybook.compare'), __('The schools\' daybooks side by side, so you can see how each one spends on the same kind of thing.'), $user->can('daybook.compare')],
        [__('What schools send'), route('body.capabilities'), __('Which parts of the report each school\'s system sends. A school that does not send a part shows "doesn\'t send this" there, never a zero.'), $user->can('capability.view')],
        [__('People'), route('body.manage.users.index'), __('Who can sign in to this console, and what each person may see and do.'), $user->can('user.view')],
    ], fn ($page) => $page[3]));

    $words = [
        [__('Report'), __('The summary a school\'s system sends for one month: money in and out, fees, students, staff pay and more. It arrives by itself; nobody types it in.')],
        [__('Version, sent again, Corrected'), __('When a school\'s figures for a month change, its system sends the month again as a new version. Every version is kept, so you can always see what changed.')],
        [__('Still open, closed'), __('A month still open may change as the school finishes its books. A closed month should not change. If it does, it appears under To look into.')],
        [__('Doesn\'t send this'), __('That school\'s system does not report this figure. It does not mean the figure is zero.')],
        [__('From 1 of 2 schools only'), __('A total that leaves out a school because it did not send that figure. The total for all your schools is not known until every school sends it.')],
        [__('Rule broken, cannot be checked, waived'), __('Broken: the school\'s figures go against your rule. Cannot be checked: the school has not sent what the rule needs. Waived: you have allowed an exception.')],
        [__('Act now, Needs action, Worth a look'), __('How serious something under To look into is. None of them is proof of wrongdoing; each is a question to ask the school.')],
        [__('Daybook'), __('Every entry in a school\'s books for a month, line by line, as the school recorded it.')],
        [__('Budget sheet'), __('The school\'s own budget for the year, with how much of each line has been used so far.')],
        [__('Students per class'), __('How many students each class has in the session, as the school counts them. No student is named.')],
        [__('Staff list'), __('Who is on a school\'s books at the end of a month: staff number, name, department and post, as the school records them. Never pay. Only some roles can open it, and every opening is recorded.')],
        [__('Class 71 · Scolarites'), __('An account class, numbered as in the school\'s chart of accounts and named in the school\'s own words.')],
    ];
@endphp

@section('content')
<div class="max-w-4xl space-y-10">

    <section>
        <h2 class="font-serif text-xl text-ink">{{ __('What this console is') }}</h2>
        <div class="mt-3 space-y-3 text-[15px] leading-relaxed text-ink-soft">
            <p>{{ trans_choice(
                '{1}Your school keeps its accounts in its own system.|[2,*]Each of your :count schools keeps its accounts in its own system.',
                max($schoolCount, 1), ['count' => $schoolCount]
            ) }} {{ __('Every month, and again whenever its figures change, that system sends a summary here by itself. It is signed, so it cannot be altered on the way, and nobody types it in.') }}</p>
            <p>{{ __('Here you can see each school\'s month and year, check them against your rules, see what is worth asking about, and compare the schools.') }}</p>
            <p class="rounded border-l-4 border-accent bg-surface px-4 py-3 text-ink">{{ __('This console never changes a school\'s books, never holds or moves money, and is not the official record. When a figure here looks wrong, it is checked in the school\'s own books.') }}</p>
        </div>
    </section>

    <section>
        <h2 class="font-serif text-xl text-ink">{{ __('Your monthly round') }}</h2>
        <p class="mt-2 text-[15px] text-ink-soft">{{ __('Schools send last month\'s report in the first days of the new month, so early in the month is a good time. It takes about 15 minutes.') }}</p>
        <ol class="mt-4 space-y-4">
            @foreach (array_values(array_filter([
                [__('Did every report arrive?'), __('On the Overview, the first figure says how many schools sent their report, and the table names any that did not. A school that sends nothing tells you nothing, so call it first.'), true],
                [__('Are your rules followed?'), __('Open Compliance. Red is a rule broken; amber is a rule that cannot be checked because the school has not sent what it needs.'), $user->can('compliance.view')],
                [__('What is worth asking about?'), $user->can('finding.resolve')
                    ? __('Open To look into, most serious first. Read what was seen, ask the school, and record what you learned. An answered item leaves the list but is kept.')
                    : __('Open To look into, most serious first, and read what was seen. Recording the answer is for the people responsible for the money; you will see who answered and what they said.'), true],
                [__('How is the money?'), $user->can('consolidation.view')
                    ? __('Open a school for its month, its year so far and its daybook, or All schools together for everything added up. Where a total leaves a school out, it says so.')
                    : __('Open a school for its month and its year so far.'), true],
            ], fn ($step) => $step[2])) as $i => [$title, $text])
                <li class="flex gap-4">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-accent text-sm font-semibold text-white">{{ $i + 1 }}</span>
                    <div>
                        <p class="font-medium text-ink">{{ $title }}</p>
                        <p class="mt-0.5 text-[15px] text-ink-soft">{{ $text }}</p>
                    </div>
                </li>
            @endforeach
        </ol>
    </section>

    <section>
        <h2 class="font-serif text-xl text-ink">{{ __('What each page is for') }}</h2>
        <dl class="mt-4 divide-y divide-rule rounded border border-rule bg-surface">
            @foreach ($pages as [$name, $href, $text])
                <div class="grid gap-1 px-4 py-3 sm:grid-cols-[12rem_1fr] sm:gap-4">
                    <dt><a href="{{ $href }}" class="font-medium text-ink underline underline-offset-2 hover:text-ink-soft">{{ $name }}</a></dt>
                    <dd class="text-sm text-ink-soft">{{ $text }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <section>
        <h2 class="font-serif text-xl text-ink">{{ __('Words you will see') }}</h2>
        <dl class="mt-4 divide-y divide-rule rounded border border-rule bg-surface">
            @foreach ($words as [$word, $meaning])
                <div class="grid gap-1 px-4 py-3 sm:grid-cols-[14rem_1fr] sm:gap-4">
                    <dt class="font-medium text-ink">{{ $word }}</dt>
                    <dd class="text-sm text-ink-soft">{{ $meaning }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <section>
        <h2 class="font-serif text-xl text-ink">{{ __('When something looks wrong') }}</h2>
        <div class="mt-3 space-y-3 text-[15px] leading-relaxed text-ink-soft">
            <p>{{ __('Ask the school\'s bursar or principal. Each item under To look into says what was noticed and what to ask. Most have an ordinary explanation; the point is to know it.') }}</p>
            <p>{{ __('If a school has stopped sending, or the console itself seems wrong, tell the people who run this console. They can see whether the school\'s system is still connected.') }}</p>
        </div>
    </section>

    @if ($user->guide_hidden_at && ! \App\Support\BodyContext::isImpersonating())
        <section class="border-t border-rule pt-6">
            <form method="POST" action="{{ route('body.guide.restore') }}" class="flex flex-wrap items-center gap-3 text-sm">
                @csrf
                <span class="text-ink-soft">{{ __('You hid the "Start here" box on the Overview.') }}</span>
                <button type="submit" class="rounded border border-rule-strong px-3 py-1 text-ink-soft hover:bg-surface-sunk">{{ __('Show it again') }}</button>
            </form>
        </section>
    @endif
</div>
@endsection
