{{-- Allgemeine Felder der Richtzeitenliste (Wettkampfjahr, Aktiv-Schalter, Qualifikationszeitraum) —
     ausgelagert, damit sie sowohl im Anlegen-Formular als auch im "Allgemeine Daten"-Tab beim Bearbeiten
     verwendet werden können, ohne den Feld-Block zu duplizieren (Muster aus meets/_grunddaten-fields.blade.php,
     dort bereits für denselben Zweck etabliert). Erwartet eine umschließende Card vom aufrufenden View. --}}
<flux:field>
    <flux:label>Wettkampfjahr *</flux:label>
    <flux:input name="year" type="number" min="2000" max="2100" class="w-32"
                value="{{ old('year', $list?->year) }}" required/>
    <flux:error name="year"/>
</flux:field>

<flux:field>
    <flux:switch name="is_active" value="1" align="left"
                 :checked="old('is_active', $list?->is_active ?? true)"
                 label="Aktiv"/>
</flux:field>

<div class="grid grid-cols-2 gap-4 pt-2 border-t border-zinc-100 dark:border-zinc-700">
    <flux:field>
        <flux:label>Qualifikationszeitraum — Beginn</flux:label>
        <flux:date-picker type="input" locale="de-AT" name="qualification_period_start"
                    value="{{ old('qualification_period_start', $list?->qualification_period_start?->toDateString()) }}" clearable/>
        <flux:description class="mt-1!">Erster Wettkampftag der vorherigen ÖSTM & ÖM.</flux:description>
        <flux:error name="qualification_period_start"/>
    </flux:field>
    <flux:field>
        <flux:label>Qualifikationszeitraum — Ende</flux:label>
        <flux:date-picker type="input" locale="de-AT" name="qualification_period_end"
                    value="{{ old('qualification_period_end', $list?->qualification_period_end?->toDateString()) }}" clearable/>
        <flux:description class="mt-1!">14 Tage vor dem ersten Wettkampftag dieser ÖSTM & ÖM — kann später
            nachgetragen/geändert werden, sobald der Termin feststeht.
        </flux:description>
        <flux:error name="qualification_period_end"/>
    </flux:field>
</div>
