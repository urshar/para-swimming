<?php

namespace App\Console\Commands;

use App\Models\Result;
use App\Models\WpsPointParameter;
use App\Services\WpsPointVersionResolver;
use App\Services\WpsScmConversionService;
use App\Support\WpsSportClass;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Zeigt, warum Ergebnisse keine WPS-Punkte tragen.
 *
 * Anlass: In der Praxis hatten von 215 Ergebnissen eines Athleten nur 14 eine Punktzahl. Ob
 * das an fehlenden Parametersätzen liegt, an nicht gelaufener Berechnung oder an
 * unbrauchbaren Ausgangsdaten, ließ sich von außen nicht unterscheiden — das Kommando
 * beantwortet genau diese Frage.
 *
 * Rein lesend; es berechnet und speichert nichts.
 */
class WpsPointsCoverageCommand extends Command
{
    /** Ergebnisstatus ohne wertbare Leistung — für diese gibt es keine Punkte. */
    private const array NON_SCORING_STATUSES = ['DNS', 'DNF', 'DSQ', 'SICK', 'WDR'];

    protected $signature = 'wps:points-coverage
        {--athlete= : Auf einen Athleten einschränken (Nachname)}
        {--year= : Auf ein Wettkampfjahr einschränken}
        {--limit=15 : Wie viele fehlende Kombinationen aufgelistet werden}';

    protected $description = 'Prüft, für welche Ergebnisse WPS-Punkte fehlen und warum';

    public function handle(
        WpsPointVersionResolver $versionResolver,
        WpsScmConversionService $conversionService,
    ): int {
        $ergebnisse = $this->relevantResults();

        if ($ergebnisse->isEmpty()) {
            $this->warn('Keine Ergebnisse für diese Auswahl gefunden.');

            return self::SUCCESS;
        }

        $this->summary($ergebnisse);

        $ohnePunkte = $ergebnisse->filter(
            static fn (Result $r): bool => $r->getAttribute('wps_points') === null
                || (int) $r->getAttribute('wps_points') <= 0
        );

        if ($ohnePunkte->isEmpty()) {
            $this->info('Alle Ergebnisse tragen eine Punktzahl.');

            return self::SUCCESS;
        }

        $this->reasons($ohnePunkte, $versionResolver, $conversionService);

        return self::SUCCESS;
    }

    /** @return Collection<int, Result> */
    private function relevantResults(): Collection
    {
        $nachname = (string) $this->option('athlete');
        $jahr = $this->option('year');

        return Result::query()
            ->with(['athlete', 'meet', 'swimEvent.strokeType'])
            ->whereNotNull('swim_time')
            ->where('swim_time', '>', 0)
            ->when(
                $nachname !== '',
                static fn ($query) => $query->whereHas(
                    'athlete',
                    static fn ($q) => $q->where('last_name', 'like', "%$nachname%")
                )
            )
            ->when(
                $jahr !== null,
                // whereBetween statt YEAR() — nicht DB-portabel.
                static fn ($query) => $query->whereHas(
                    'meet',
                    static fn ($q) => $q->whereBetween('start_date', ["$jahr-01-01", "$jahr-12-31 23:59:59"])
                )
            )
            ->get()
            ->filter(static fn (Result $r): bool => $r->swimEvent !== null && $r->meet !== null);
    }

    /** @param  Collection<int, Result>  $ergebnisse */
    private function summary(Collection $ergebnisse): void
    {
        $mitPunkten = $ergebnisse->filter(
            static fn (Result $r): bool => (int) $r->getAttribute('wps_points') > 0
        )->count();

        $this->newLine();
        $this->line(sprintf(
            'Ergebnisse: %d · mit Punkten: %d · ohne: %d',
            $ergebnisse->count(),
            $mitPunkten,
            $ergebnisse->count() - $mitPunkten,
        ));
        $this->newLine();
    }

    /**
     * Gruppiert die punktelosen Ergebnisse nach dem wahrscheinlichen Grund.
     *
     * @param  Collection<int, Result>  $ohnePunkte
     */
    private function reasons(
        Collection $ohnePunkte,
        WpsPointVersionResolver $versionResolver,
        WpsScmConversionService $conversionService,
    ): void {
        $gruende = [
            'Staffel — es gibt keine WPS-Staffelparameter' => 0,
            'ohne Sportklasse am Ergebnis' => 0,
            'ohne wertbaren Status (DNS, DSQ …)' => 0,
            'keine Punkteversion zum Wettkampfdatum' => 0,
            'kein Parametersatz für diese Kombination' => 0,
            // Kurzbahnzeiten werden über einen Faktor auf ein Langbahn-Äquivalent gebracht;
            // fehlt er, gibt es trotz vorhandener Parameter keine Punkte.
            'kein SCM-Umrechnungsfaktor' => 0,
            'alles vorhanden — Berechnung nie gelaufen' => 0,
        ];

        $fehlendeKombinationen = [];

        foreach ($ohnePunkte as $ergebnis) {
            $event = $ergebnis->swimEvent;

            if ((int) $event->getAttribute('relay_count') > 1) {
                $gruende['Staffel — es gibt keine WPS-Staffelparameter']++;

                continue;
            }

            if ($ergebnis->getAttribute('sport_class') === null) {
                $gruende['ohne Sportklasse am Ergebnis']++;

                continue;
            }

            // Die Null-Prüfung entfällt: in_array() mit strengem Vergleich schließt null
            // ohnehin aus, weil null in der Liste nicht vorkommt.
            if (in_array($ergebnis->getAttribute('status'), self::NON_SCORING_STATUSES, true)) {
                $gruende['ohne wertbaren Status (DNS, DSQ …)']++;

                continue;
            }

            $version = $versionResolver->resolveForDate(
                $ergebnis->meet->start_date->format('Y-m-d')
            );

            if ($version === null) {
                $gruende['keine Punkteversion zum Wettkampfdatum']++;

                continue;
            }

            $klasse = WpsSportClass::mapToWps($ergebnis->getAttribute('sport_class')) ?? '';

            // Parameter gibt es nur für die Langbahn; Kurzbahn wird umgerechnet.
            $vorhanden = WpsPointParameter::query()
                ->where('wps_point_version_id', $version->getKey())
                ->where('course', WpsPointParameter::COURSE_LCM)
                ->where('gender', $ergebnis->athlete?->getAttribute('gender'))
                ->where('stroke_type_id', $event->getAttribute('stroke_type_id'))
                ->where('distance', $event->getAttribute('distance'))
                ->where('relay_count', 1)
                ->where('sport_class', $klasse)
                ->exists();

            if (! $vorhanden) {
                $gruende['kein Parametersatz für diese Kombination']++;

                // Die Bahnlänge gehört dazu: Ob ein Bewerb überhaupt existieren kann, hängt
                // an ihr — 100 m Lagen etwa gibt es nur auf der Kurzbahn.
                $schluessel = sprintf(
                    '%s %d m %s · %s · %s',
                    $ergebnis->athlete?->getAttribute('gender'),
                    $event->getAttribute('distance'),
                    $event->strokeType?->name_de ?? '?',
                    $klasse,
                    $ergebnis->meet->getAttribute('course'),
                );

                $fehlendeKombinationen[$schluessel] = ($fehlendeKombinationen[$schluessel] ?? 0) + 1;

                continue;
            }

            // Für Kurzbahn braucht es zusätzlich einen Umrechnungsfaktor.
            if ($ergebnis->meet->getAttribute('course') !== 'LCM') {
                $faktor = $conversionService->resolveFactor(
                    (int) $event->getAttribute('stroke_type_id'),
                    (int) $event->getAttribute('distance'),
                    $klasse,
                    (string) $ergebnis->athlete?->getAttribute('gender'),
                );

                if ($faktor === null) {
                    $gruende['kein SCM-Umrechnungsfaktor']++;

                    continue;
                }
            }

            $gruende['alles vorhanden — Berechnung nie gelaufen']++;
        }

        $this->table(
            ['Grund', 'Anzahl'],
            collect($gruende)
                ->filter(static fn (int $anzahl): bool => $anzahl > 0)
                ->map(static fn (int $anzahl, string $grund): array => [$grund, $anzahl])
                ->values()
                ->all(),
        );

        if ($gruende['keine Punkteversion zum Wettkampfdatum'] > 0) {
            $this->newLine();
            $this->line(
                'Hinweis: "Keine Punkteversion zum Wettkampfdatum" heißt meist, dass für diese '
                .'Jahre noch keine WPS-Punktetabelle hinterlegt ist — kein Fehler, sondern eine '
                .'Lücke im Bestand.'
            );
        }

        if ($fehlendeKombinationen === []) {
            return;
        }

        arsort($fehlendeKombinationen);

        $this->newLine();
        $this->line('Häufigste Kombinationen ohne Parametersatz:');

        $this->table(
            ['Geschlecht · Bewerb · Klasse', 'Ergebnisse'],
            collect($fehlendeKombinationen)
                ->take((int) $this->option('limit'))
                ->map(static fn (int $anzahl, string $schluessel): array => [$schluessel, $anzahl])
                ->values()
                ->all(),
        );
    }
}
