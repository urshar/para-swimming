<?php

use App\Livewire\Admin\BaseTimeTable;
use App\Models\BaseTime;
use App\Models\BaseTimeCategory;
use App\Models\BaseTimeDerivationRule;
use App\Models\BaseTimeDiscipline;
use App\Models\BaseTimeSportClass;
use App\Models\BaseTimeVersion;
use App\Models\StrokeType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

function makeAdmin_bt(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function makeClubUser_bt(): User
{
    return User::factory()->create(['is_admin' => false]);
}

// ── BaseTimeVersionController ────────────────────────────────────────────────

describe('BaseTimeVersionController', function () {
    it('Club-User bekommt 403', function () {
        $this->actingAs(makeClubUser_bt())
            ->get(route('base-times.versions.index'))
            ->assertForbidden();
    })->group('base-time-crud');

    it('Admin kann eine Version anlegen', function () {
        $this->actingAs(makeAdmin_bt())
            ->post(route('base-times.versions.store'), [
                'label' => '2021–2026',
                'valid_from' => '2021-01-01',
                'valid_until' => '2026-12-31',
            ])
            ->assertRedirect(route('base-times.versions.index'));

        expect(BaseTimeVersion::where('label', '2021–2026')->exists())->toBeTrue();
    })->group('base-time-crud');

    it('verhindert überlappende Zeiträume beim Anlegen', function () {
        BaseTimeVersion::create(['label' => 'V1', 'valid_from' => '2021-01-01', 'valid_until' => '2026-12-31']);

        $this->actingAs(makeAdmin_bt())
            ->post(route('base-times.versions.store'), [
                'label' => 'V2', 'valid_from' => '2025-01-01', 'valid_until' => null,
            ])
            ->assertSessionHasErrors('valid_from');
    })->group('base-time-crud');

    it('erlaubt beim Bearbeiten die eigene Version (kein Selbst-Overlap)', function () {
        $version = BaseTimeVersion::create(['label' => 'V1', 'valid_from' => '2021-01-01', 'valid_until' => '2026-12-31']);

        $this->actingAs(makeAdmin_bt())
            ->put(route('base-times.versions.update', $version), [
                'label' => 'V1 (angepasst)', 'valid_from' => '2021-01-01', 'valid_until' => '2026-12-31',
            ])
            ->assertRedirect(route('base-times.versions.index'));

        expect($version->fresh()->label)->toBe('V1 (angepasst)');
    })->group('base-time-crud');

    it('löscht eine Version inklusive ihrer Basiswerte', function () {
        $stroke = StrokeType::create(['name_de' => 'Freistil', 'name_en' => 'Freestyle', 'lenex_code' => 'FREE', 'code' => 'FREE']);
        $version = BaseTimeVersion::create(['label' => 'V1', 'valid_from' => '2021-01-01', 'valid_until' => null]);
        $category = BaseTimeCategory::create(['code' => 'LC_MEN', 'course' => 'LCM', 'gender' => 'M', 'label' => 'LC Men']);
        $discipline = BaseTimeDiscipline::create(['code' => '100FR', 'distance' => 100, 'relay_count' => 1, 'stroke_type_id' => $stroke->id]);
        $sportClass = BaseTimeSportClass::create(['code' => 'S1', 'sort_order' => 1]);
        BaseTime::create([
            'base_time_version_id' => $version->id, 'base_time_category_id' => $category->id,
            'base_time_discipline_id' => $discipline->id, 'base_time_sport_class_id' => $sportClass->id,
            'value_centiseconds' => 6000, 'value_type' => BaseTime::TYPE_MANUAL,
        ]);

        $this->actingAs(makeAdmin_bt())
            ->delete(route('base-times.versions.destroy', $version))
            ->assertRedirect(route('base-times.versions.index'));

        expect(BaseTimeVersion::find($version->id))->toBeNull()
            ->and(BaseTime::where('base_time_version_id', $version->id)->exists())->toBeFalse();
    })->group('base-time-crud');
});

// ── BaseTimeTable (Livewire) ──────────────────────────────────────────────────

describe('BaseTimeTable Livewire-Komponente', function () {
    beforeEach(function () {
        $stroke = StrokeType::create(['name_de' => 'Freistil', 'name_en' => 'Freestyle', 'lenex_code' => 'FREE', 'code' => 'FREE']);

        $this->version = BaseTimeVersion::create(['label' => 'V1', 'valid_from' => '2021-01-01', 'valid_until' => null]);
        $this->category = BaseTimeCategory::create(['code' => 'LC_MEN', 'course' => 'LCM', 'gender' => 'M', 'label' => 'LC Men']);

        $this->d100 = BaseTimeDiscipline::create(['code' => '100FR', 'distance' => 100, 'relay_count' => 1, 'stroke_type_id' => $stroke->id]);
        $this->d200 = BaseTimeDiscipline::create(['code' => '200FR', 'distance' => 200, 'relay_count' => 1, 'stroke_type_id' => $stroke->id]);
        $this->s1 = BaseTimeSportClass::create(['code' => 'S1', 'sort_order' => 1]);

        $this->manualRow = BaseTime::create([
            'base_time_version_id' => $this->version->id, 'base_time_category_id' => $this->category->id,
            'base_time_discipline_id' => $this->d100->id, 'base_time_sport_class_id' => $this->s1->id,
            'value_centiseconds' => 6000, 'value_type' => BaseTime::TYPE_MANUAL,
        ]);
        $this->calculatedRow = BaseTime::create([
            'base_time_version_id' => $this->version->id, 'base_time_category_id' => $this->category->id,
            'base_time_discipline_id' => $this->d200->id, 'base_time_sport_class_id' => $this->s1->id,
            'value_centiseconds' => 13200, 'value_type' => BaseTime::TYPE_CALCULATED,
        ]);
        BaseTimeDerivationRule::create([
            'base_time_category_id' => $this->category->id,
            'shorter_discipline_id' => $this->d100->id,
            'longer_discipline_id' => $this->d200->id,
        ]);

        $this->admin = makeAdmin_bt();
    })->group('base-time-crud');

    it('zeigt MANUAL editierbar und CALCULATED nur lesbar an', function () {
        Livewire::actingAs($this->admin)
            ->test(BaseTimeTable::class, ['version' => $this->version, 'category' => $this->category])
            // MANUAL-Wert landet bei wire: model nicht als sichtbares value="..." im HTML
            // (erst client-seitig aus dem Snapshot), daher hier den Komponenten-Zustand prüfen.
            ->assertSet("cells.{$this->d100->id}.{$this->s1->id}", '01:00.00')
            // CALCULATED-Wert ist reiner Text (kein Input) und daher per assertSee prüfbar.
            ->assertSee('02:12.00'); // 13200cs
    })->group('base-time-crud');

    it('speichert einen gültigen MANUAL-Wert', function () {
        Livewire::actingAs($this->admin)
            ->test(BaseTimeTable::class, ['version' => $this->version, 'category' => $this->category])
            ->set("cells.{$this->d100->id}.{$this->s1->id}", '01:05.50')
            ->assertHasNoErrors();

        expect($this->manualRow->fresh()->value_centiseconds)->toBe(6550);
    })->group('base-time-crud');

    it('lehnt ein ungültiges Zeitformat ab und behält den alten Wert', function () {
        Livewire::actingAs($this->admin)
            ->test(BaseTimeTable::class, ['version' => $this->version, 'category' => $this->category])
            ->set("cells.{$this->d100->id}.{$this->s1->id}", 'keine-zeit')
            ->assertHasErrors(["cells.{$this->d100->id}.{$this->s1->id}"]);

        expect($this->manualRow->fresh()->value_centiseconds)->toBe(6000);
    })->group('base-time-crud');

    it('ignoriert Versuche, eine CALCULATED-Zelle zu bearbeiten', function () {
        Livewire::actingAs($this->admin)
            ->test(BaseTimeTable::class, ['version' => $this->version, 'category' => $this->category])
            ->set("cells.{$this->d200->id}.{$this->s1->id}", '00:01.00');

        expect($this->calculatedRow->fresh()->value_centiseconds)->toBe(13200);
    })->group('base-time-crud');

    it('der Neu-berechnen-Button ruft den BaseTimeCalculationService auf', function () {
        Livewire::actingAs($this->admin)
            ->test(BaseTimeTable::class, ['version' => $this->version, 'category' => $this->category])
            ->set("cells.{$this->d100->id}.{$this->s1->id}", '00:50.00')
            ->call('recalculate')
            ->assertSet('recalcMessage', fn ($message) => str_contains($message, 'aktualisiert'));
    })->group('base-time-crud');
});

// ── BaseTimeTable: Sportklassen-Tab-Beschriftung ────────────────────────────────

describe('BaseTimeTable Sportklassen-Tab-Beschriftung', function () {
    it('beschriftet Tabs mit den echten Codes (erster…letzter), nicht mit der Spaltenposition', function () {
        $stroke = StrokeType::create(['name_de' => 'Freistil', 'name_en' => 'Freestyle', 'lenex_code' => 'FREE', 'code' => 'FREE']);
        $version = BaseTimeVersion::create(['label' => 'V1', 'valid_from' => '2021-01-01', 'valid_until' => null]);
        $category = BaseTimeCategory::create(['code' => 'SC_WOMEN', 'course' => 'SCM', 'gender' => 'F', 'label' => 'SC Women']);
        $discipline = BaseTimeDiscipline::create(['code' => '25FR', 'distance' => 25, 'relay_count' => 1, 'stroke_type_id' => $stroke->id]);

        // Lückenhafter Datensatz wie OeBSV 2021: S1–S15, S20, S21 (kein S16–S19).
        $numbers = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 20, 21];
        foreach ($numbers as $i => $n) {
            $sportClass = BaseTimeSportClass::create(['code' => "S$n", 'sort_order' => $i]);
            BaseTime::create([
                'base_time_version_id' => $version->id, 'base_time_category_id' => $category->id,
                'base_time_discipline_id' => $discipline->id, 'base_time_sport_class_id' => $sportClass->id,
                'value_centiseconds' => 2500, 'value_type' => BaseTime::TYPE_MANUAL,
            ]);
        }

        Livewire::actingAs(makeAdmin_bt())
            ->test(BaseTimeTable::class, ['version' => $version, 'category' => $category])
            ->assertSee('S1…S10')      // erster Block: S1..S10
            ->assertSee('S11…S21')     // zweiter Block: S11..S15, S20, S21 — letzter Code ist S21
            ->assertDontSee('11–19')   // altes, positionsbasiertes Label
            ->assertDontSee('11–21')
            ->assertDontSee('Staffeln'); // reiner Einzeldatensatz → kein Staffel-Tab
    })->group('base-time-crud');
});

// ── BaseTimeTable: Einzel/Staffel-Trennung ──────────────────────────────────────

describe('BaseTimeTable Einzel/Staffel-Trennung', function () {
    it('zeigt Staffeln in einem eigenen Tab und hält sie aus den Einzel-Tabs heraus', function () {
        $free = StrokeType::create(['name_de' => 'Freistil', 'name_en' => 'Freestyle', 'lenex_code' => 'FREE', 'code' => 'FREE']);
        $version = BaseTimeVersion::create(['label' => 'V1', 'valid_from' => '2021-01-01', 'valid_until' => null]);
        $category = BaseTimeCategory::create(['code' => 'SC_MIXED', 'course' => 'SCM', 'gender' => 'X', 'label' => 'SC Mixed']);

        $einzel = BaseTimeDiscipline::create(['code' => '50FR', 'distance' => 50, 'relay_count' => 1, 'stroke_type_id' => $free->id]);
        $staffel = BaseTimeDiscipline::create(['code' => '4x25FR', 'distance' => 25, 'relay_count' => 4, 'stroke_type_id' => $free->id]);

        // 12 Einzelklassen (→ 2 Einzel-Chunks), 2 reine Staffelklassen (S14, S34).
        foreach (range(1, 12) as $n) {
            $sc = BaseTimeSportClass::create(['code' => "S$n", 'sort_order' => $n]);
            BaseTime::create([
                'base_time_version_id' => $version->id, 'base_time_category_id' => $category->id,
                'base_time_discipline_id' => $einzel->id, 'base_time_sport_class_id' => $sc->id,
                'value_centiseconds' => 3000, 'value_type' => BaseTime::TYPE_MANUAL,
            ]);
        }
        foreach (['S14', 'S34'] as $i => $code) {
            $sc = BaseTimeSportClass::create(['code' => $code, 'sort_order' => 20 + $i]);
            BaseTime::create([
                'base_time_version_id' => $version->id, 'base_time_category_id' => $category->id,
                'base_time_discipline_id' => $staffel->id, 'base_time_sport_class_id' => $sc->id,
                'value_centiseconds' => 6000, 'value_type' => BaseTime::TYPE_MANUAL,
            ]);
        }

        $html = Livewire::actingAs(makeAdmin_bt())
            ->test(BaseTimeTable::class, ['version' => $version, 'category' => $category])
            ->html();

        // Eigener Staffel-Tab; Staffelbewerb NUR im Staffel-Panel (1x), Einzelbewerb in beiden
        // Einzel-Chunk-Panels (2x) und nicht im Staffel-Panel; reine Staffelklasse S34 taucht nicht
        // als leere Spalte in den Einzel-Tabs auf (1x als Header im Staffel-Panel).
        expect($html)->toContain('Staffeln')
            ->and(substr_count($html, '4x25FR'))->toBe(1)
            ->and(substr_count($html, '50FR'))->toBe(2)
            ->and(substr_count($html, 'S34'))->toBe(1);
    })->group('base-time-crud');
});
