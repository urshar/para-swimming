{{--
    Grunddaten-Felder des Wettkampf-Formulars.
    Partial, weil dieselben Felder sowohl im Admin-Tab "Grunddaten" als auch
    (ohne Tabs) für Nicht-Admins angezeigt werden — siehe form.blade.php.
    Erwartet: $meet (optional), $nations, $autId.
--}}
<flux:field>
    <flux:label>Name <span class="text-red-500 dark:text-red-400">*</span></flux:label>
    <flux:input name="name" value="{{ old('name', $meet->name ?? '') }}" required/>
    <flux:error name="name"/>
</flux:field>

<div class="grid grid-cols-2 gap-4">
    <flux:field>
        <flux:label>Stadt <span class="text-red-500 dark:text-red-400">*</span></flux:label>
        <flux:input name="city" value="{{ old('city', $meet->city ?? '') }}" required/>
        <flux:error name="city"/>
    </flux:field>
    <flux:field>
        <flux:label>Nation <span class="text-red-500 dark:text-red-400">*</span></flux:label>
        <flux:select variant="listbox" searchable name="nation_id" required>
            @foreach($nations as $nation)
                <flux:select.option value="{{ $nation->id }}"
                    :selected="old('nation_id', $meet->nation_id ?? $autId) == $nation->id">
                    {{ $nation->code }} – {{ $nation->name_de }}
                </flux:select.option>
            @endforeach
        </flux:select>
        <flux:error name="nation_id"/>
    </flux:field>
</div>

<div class="grid grid-cols-2 gap-4">
    <flux:field>
        <flux:label>Startdatum <span class="text-red-500 dark:text-red-400">*</span></flux:label>
        <flux:date-picker type="input" locale="de-AT" name="start_date"
                    value="{{ old('start_date', isset($meet) ? $meet->start_date->format('Y-m-d') : '') }}"
                    required/>
        <flux:error name="start_date"/>
    </flux:field>
    <flux:field>
        <flux:label>Enddatum</flux:label>
        <flux:date-picker type="input" locale="de-AT" name="end_date"
                    value="{{ old('end_date', isset($meet) && $meet->end_date ? $meet->end_date->format('Y-m-d') : '') }}" clearable/>
        <flux:error name="end_date"/>
    </flux:field>
</div>

<div class="grid grid-cols-2 gap-4">
    <flux:field>
        <flux:label>Bahnlänge <span class="text-red-500 dark:text-red-400">*</span></flux:label>
        <flux:select variant="listbox" name="course" required>
            @foreach(['LCM' => 'LCM (50m)', 'SCM' => 'SCM (25m)', 'SCY' => 'SCY (Yards)', 'OPEN' => 'Freiwasser'] as $val => $label)
                <flux:select.option value="{{ $val }}" :selected="old('course', $meet->course ?? 'LCM') === $val">
                    {{ $label }}
                </flux:select.option>
            @endforeach
        </flux:select>
        <flux:error name="course"/>
    </flux:field>
    <flux:field>
        <flux:label>Zeitnahme</flux:label>
        <flux:select variant="listbox" name="timing" placeholder="Nicht angegeben" clearable>
            @foreach(['AUTOMATIC' => 'Automatisch', 'SEMIAUTOMATIC' => 'Halbautomatisch', 'MANUAL3' => 'Manuell 3', 'MANUAL2' => 'Manuell 2', 'MANUAL1' => 'Manuell 1'] as $val => $label)
                <flux:select.option value="{{ $val }}" :selected="old('timing', $meet->timing ?? '') === $val">
                    {{ $label }}
                </flux:select.option>
            @endforeach
        </flux:select>
        <flux:error name="timing"/>
    </flux:field>
</div>

<flux:field>
    <flux:label>Veranstalter</flux:label>
    <flux:input name="organizer" value="{{ old('organizer', $meet->organizer ?? '') }}"/>
    <flux:error name="organizer"/>
</flux:field>

<div class="grid grid-cols-2 gap-4">
    <flux:field>
        <flux:label>Meldetyp</flux:label>
        <flux:select variant="listbox" name="entry_type" placeholder="Nicht angegeben" clearable>
            <flux:select.option value="OPEN" :selected="old('entry_type', $meet->entry_type ?? '') === 'OPEN'">
                Offen
            </flux:select.option>
            <flux:select.option value="INVITATION" :selected="old('entry_type', $meet->entry_type ?? '') === 'INVITATION'">
                Nur Eingeladene
            </flux:select.option>
        </flux:select>
        <flux:error name="entry_type"/>
    </flux:field>
    <flux:field>
        <flux:label>Höhe über Meeresspiegel</flux:label>
        <flux:input name="altitude" type="number" min="0" max="9000"
                    value="{{ old('altitude', $meet->altitude ?? 0) }}"/>
        <flux:error name="altitude"/>
    </flux:field>
</div>

<flux:field>
    <flux:label>Meldeschluss</flux:label>
    <flux:date-picker type="input" locale="de-AT" name="entries_deadline"
                value="{{ old('entries_deadline', isset($meet) && $meet->entries_deadline ? $meet->entries_deadline->format('Y-m-d') : '') }}" clearable/>
    <flux:description class="mt-1">Datum bis zu dem Vereine Meldungen einreichen können.</flux:description>
    <flux:error name="entries_deadline"/>
</flux:field>

<flux:field>
    <flux:label>Vereinsmeldungen freigegeben</flux:label>
    <flux:switch name="is_open" value="1" :checked="old('is_open', $meet->is_open ?? false)"
                 label="Österreichische Vereine können sich für diesen Wettkampf anmelden"/>
</flux:field>
