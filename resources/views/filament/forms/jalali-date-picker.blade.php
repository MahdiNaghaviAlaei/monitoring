@php
    $inputId = $getId();
    $statePath = $getStatePath();
    $label = $getLabel();
@endphp
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
<div
    class="relative min-w-0"
    wire:key="{{ $inputId }}"
    x-data="{
        value: $wire.entangle(@js($statePath)),
        open: false,
        selected: null,
        formatter: null,
        today: @js(now()->timezone('Asia/Tehran')->format('Y-m-d')),
        focus: @js(\App\Support\JalaliDate::toGregorian($getState()) ?? now()->timezone('Asia/Tehran')->format('Y-m-d')),
        init() {
            this.formatter = new Intl.DateTimeFormat('en-US-u-ca-persian-nu-latn', { year: 'numeric', month: 'numeric', day: 'numeric', timeZone: 'UTC' });
            this.selected = this.gregorian(this.value);
            this.$watch('value', value => this.selected = this.gregorian(value));
        },
        normalize(value) {
            return String(value ?? '').trim().replace(/[۰-۹]/g, d => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
        },
        date(value) { return new Date(value + 'T00:00:00Z'); },
        iso(date) { return date.toISOString().slice(0, 10); },
        parts(date) {
            const parts = this.formatter.formatToParts(date);
            return Object.fromEntries(parts.filter(p => ['year', 'month', 'day'].includes(p.type)).map(p => [p.type, Number(p.value)]));
        },
        format(date) {
            const p = this.parts(date);
            return p.year + '/' + String(p.month).padStart(2, '0') + '/' + String(p.day).padStart(2, '0');
        },
        digits(number) { return new Intl.NumberFormat('fa-IR', { useGrouping: false }).format(number); },
        gregorian(value) {
            const match = this.normalize(value).match(/^(\d{4})[/-](\d{1,2})[/-](\d{1,2})$/);
            if (!match) return null;
            const [y, m, d] = match.slice(1).map(Number);
            if (y < 1000 || y > 1600 || m < 1 || m > 12 || d < 1 || d > 31) return null;
            const target = y * 10000 + m * 100 + d;
            let low = Math.floor(Date.UTC(y + 621, 0, 1) / 86400000);
            let high = Math.floor(Date.UTC(y + 623, 0, 1) / 86400000);
            while (low <= high) {
                const mid = Math.floor((low + high) / 2);
                const date = new Date(mid * 86400000);
                const p = this.parts(date);
                const current = p.year * 10000 + p.month * 100 + p.day;
                if (current === target) return this.iso(date);
                if (current < target) low = mid + 1;
                else high = mid - 1;
            }
            return null;
        },
        get year() { return this.parts(this.date(this.focus)).year; },
        get month() { return this.parts(this.date(this.focus)).month; },
        get first() {
            const date = this.date(this.focus);
            date.setUTCDate(date.getUTCDate() - this.parts(date).day + 1);
            return date;
        },
        get blanks() { return (this.first.getUTCDay() + 1) % 7; },
        get days() {
            const first = this.first;
            const days = [];
            for (let offset = 0; offset < 31; offset++) {
                const date = new Date(first.getTime() + offset * 86400000);
                const p = this.parts(date);
                if (p.month !== this.month) break;
                days.push({ day: p.day, iso: this.iso(date), value: this.format(date) });
            }
            return days;
        },
        toggle() {
            if (!this.open) this.focus = this.gregorian(this.value) ?? this.today;
            this.open = !this.open;
        },
        move(step) {
            const date = new Date(this.first.getTime() + (step > 0 ? 32 : -1) * 86400000);
            const year = this.parts(date).year;
            if (year >= 1000 && year <= 1600) this.focus = this.iso(date);
        },
        jump(month, year) {
            const date = this.gregorian(year + '/' + month + '/1');
            if (date) this.focus = date;
        },
        choose(value) {
            this.value = value;
            this.open = false;
            this.$refs.trigger.focus();
        },
    }"
    x-on:click.outside="open = false"
    x-on:keydown.escape="if (open) { $event.stopPropagation(); $event.preventDefault(); open = false; $refs.trigger.focus() }"
>
    <div class="flex items-center gap-2">
        <x-filament::input.wrapper class="flex-1" :valid="! $errors->has($statePath)">
            <x-filament::input :id="$inputId" type="text" x-model="value" dir="ltr" autocomplete="off"
                :placeholder="__('monitoring.history.date_placeholder')" maxlength="20"
                :aria-invalid="$errors->has($statePath) ? 'true' : 'false'" :aria-describedby="$inputId.'-error'" />
        </x-filament::input.wrapper>
        <x-filament::icon-button type="button" icon="heroicon-o-calendar-days" x-ref="trigger" x-on:click="toggle()"
            :label="__('monitoring.history.calendar.open', ['label' => $label])"
            aria-haspopup="dialog" x-bind:aria-expanded="open" :aria-controls="$inputId.'-calendar'" />
    </div>

    <div id="{{ $inputId }}-calendar" x-cloak x-show="open" x-trap="open" role="dialog"
        aria-label="{{ __('monitoring.history.calendar.label') }}"
        style="width: 18rem; max-width: calc(100vw - 3rem)" class="absolute z-50 rounded-lg bg-white p-4 shadow-lg ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="grid gap-y-3">
            <div class="flex items-center justify-between gap-2">
                <x-filament::icon-button type="button" icon="heroicon-m-chevron-right" x-on:click="move(-1)" :label="__('monitoring.history.calendar.previous_month')" />
                <select x-bind:value="month" x-on:change="jump(Number($event.target.value), year)"
                    aria-label="{{ __('monitoring.history.calendar.month') }}" class="grow border-none bg-transparent p-0 text-sm text-gray-950 dark:bg-gray-900 dark:text-white">
                    @foreach (__('monitoring.history.calendar.months') as $month)
                        <option value="{{ $loop->iteration }}">{{ $month }}</option>
                    @endforeach
                </select>
                <input type="number" min="1000" max="1600" x-bind:value="year"
                    x-on:change="jump(month, Number($event.target.value)); $event.target.value = year"
                    aria-label="{{ __('monitoring.history.calendar.year') }}" class="w-16 border-none bg-transparent p-0 text-sm text-gray-950 dark:text-white" />
                <x-filament::icon-button type="button" icon="heroicon-m-chevron-left" x-on:click="move(1)" :label="__('monitoring.history.calendar.next_month')" />
            </div>
            <div class="grid grid-cols-7 gap-1">
                @foreach (__('monitoring.history.calendar.weekdays') as $day)
                    <span class="text-center text-xs text-gray-500 dark:text-gray-400" title="{{ __('monitoring.history.calendar.weekday_names')[$loop->index] }}">{{ $day }}</span>
                @endforeach
            </div>
            <div class="grid grid-cols-7 gap-1">
                <template x-for="blank in blanks" x-bind:key="'blank-' + blank"><span aria-hidden="true"></span></template>
                <template x-for="day in days" x-bind:key="day.iso">
                    <button type="button" x-text="digits(day.day)" x-on:click="choose(day.value)"
                        x-bind:aria-label="day.value" x-bind:aria-pressed="selected === day.iso"
                        x-bind:aria-current="day.iso === today ? 'date' : null"
                        x-bind:class="{ 'bg-primary-600 text-white': selected === day.iso, 'text-gray-950 dark:text-white': selected !== day.iso }"
                        class="rounded-full text-center text-sm leading-loose focus:outline-none focus:ring-2 focus:ring-primary-600"></button>
                </template>
            </div>
            <div class="flex items-center justify-between">
                <x-filament::button type="button" size="sm" color="gray" x-on:click="choose(format(date(today)))">{{ __('monitoring.history.calendar.today') }}</x-filament::button>
                <x-filament::button type="button" size="sm" color="gray" x-on:click="open = false; $refs.trigger.focus()">{{ __('monitoring.actions.close') }}</x-filament::button>
            </div>
        </div>
    </div>
</div>
</x-dynamic-component>