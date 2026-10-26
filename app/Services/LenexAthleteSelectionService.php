<?php

namespace App\Services;

use App\Models\Nation;
use App\Models\StrokeType;
use Exception;
use SimpleXMLElement;

/**
 * Athletenauswahl vor dem Import einer Ergebnisdatei (Importumfang "Nur ausgewählte Athleten", siehe
 * docs/specs/lenex-athlete-selection.md): gleicht die Schwimmer der Datei ohne Schreibzugriff mit der Datenbank ab.
 *
 * - matches:    Schwimmer mit bestehendem Athleten (LenexResolverService::findAthlete(), dieselben Regeln wie beim
 *               Import) — im Auswahlschritt vorausgewählt. Findet findAthlete() nichts, zusätzlich über Name + Jahrgang
 *               (findAthleteByBirthYear(), für fremde Veranstaltungen mit fremder Lizenz und nur dem Jahrgang) — nicht
 *               vorausgewählt.
 * - candidates: nicht gefundene Schwimmer mit Para-Hinweis (HANDICAP-Element oder Start in einem Bewerb mit
 *               Klassenangabe) — können bewusst neu angelegt werden. Alle anderen bleiben unerwähnt.
 *
 * Gezählt werden nur Einzelergebnisse; Staffeln werden in diesem Modus nicht importiert. Wer nur in Staffeln startet,
 * erscheint deshalb nicht.
 */
final readonly class LenexAthleteSelectionService
{
    public function __construct(
        private LenexParserService $parser,
        private LenexResolverService $resolver,
    ) {}

    /**
     * @return array{
     *     matches: list<array{lenex_id: string, athlete_id: int, athlete_name: string, birth_year: ?string, matched_by: string, preselected: bool, file_name: string, file_club: string, file_nation: string, events: list<string>}>,
     *     candidates: list<array{lenex_id: string, file_name: string, birth_date: string, gender: string, file_club: string, file_nation: string, events: list<string>}>
     * }
     *
     * @throws Exception
     */
    public function preview(string $filePath): array
    {
        $meetXml = $this->parser->loadXml($filePath)->MEETS->MEET[0];
        $events = $this->individualEvents($meetXml);
        $nationIds = Nation::pluck('id', 'code')->mapWithKeys(fn (int $id, string $code): array => [strtoupper($code) => $id])->all();

        $matches = [];
        $candidates = [];
        $seen = [];

        foreach ($meetXml->CLUBS->CLUB ?? [] as $clubXml) {
            foreach ($clubXml->ATHLETES->ATHLETE ?? [] as $athleteXml) {
                $lenexId = (string) ($athleteXml['athleteid'] ?? '');
                $eventIds = [];
                foreach ($athleteXml->RESULTS->RESULT ?? [] as $resultXml) {
                    $eventId = (string) ($resultXml['eventid'] ?? '');
                    if (isset($events[$eventId])) {
                        $eventIds[] = $eventId;
                    }
                }
                if ($lenexId === '' || $eventIds === [] || isset($seen[$lenexId])) {
                    continue;
                }
                $seen[$lenexId] = true;

                $nation = LenexParserService::athleteNation($athleteXml, $clubXml);
                $file = [
                    'lenex_id' => $lenexId,
                    'file_name' => trim(($athleteXml['lastname'] ?? '').', '.($athleteXml['firstname'] ?? ''), ' ,'),
                    'file_club' => (string) ($clubXml['name'] ?? ''),
                    'file_nation' => $nation,
                    'events' => array_values(array_unique(array_map(fn (string $id): string => $events[$id]['label'], $eventIds))),
                ];

                $found = $this->resolver->findAthlete($athleteXml, $nationIds[$nation] ?? 0)
                    ?? $this->resolver->findAthleteByBirthYear($athleteXml, $nationIds[$nation] ?? 0);
                if ($found) {
                    $athlete = $found['athlete'];
                    $matches[] = $file + [
                        'athlete_id' => $athlete->id,
                        'athlete_name' => $athlete->display_name,
                        'birth_year' => $athlete->birth_date?->format('Y'),
                        'matched_by' => $found['matchedBy'],
                        // Treffer nur über den Jahrgang muss der Admin bewusst anhaken
                        'preselected' => $found['matchedBy'] !== LenexResolverService::MATCH_NAME_YEAR,
                    ];

                    continue;
                }

                $paraHint = isset($athleteXml->HANDICAP)
                    || array_filter($eventIds, fn (string $id): bool => $events[$id]['classified']) !== [];
                if ($paraHint) {
                    $candidates[] = $file + [
                        'birth_date' => (string) ($athleteXml['birthdate'] ?? ''),
                        'gender' => (string) ($athleteXml['gender'] ?? ''),
                    ];
                }
            }
        }

        return [
            'matches' => self::sortByName($matches, 'athlete_name'),
            'candidates' => self::sortByName($candidates, 'file_name'),
        ];
    }

    /**
     * Einzelbewerbe der Datei: eventid → Anzeige ("100 m Brust") und ob eine AGEGROUP Klassen nennt.
     *
     * @return array<string, array{label: string, classified: bool}>
     */
    private function individualEvents(SimpleXMLElement $meetXml): array
    {
        $events = [];
        foreach ($meetXml->SESSIONS->SESSION ?? [] as $sessionXml) {
            foreach ($sessionXml->EVENTS->EVENT ?? [] as $eventXml) {
                $styleXml = $eventXml->SWIMSTYLE ?? null;
                $eventId = (string) ($eventXml['eventid'] ?? '');
                if (! $styleXml || $eventId === '' || (int) ($styleXml['relaycount'] ?? 1) > 1) {
                    continue;
                }

                $classified = false;
                foreach ($eventXml->AGEGROUPS->AGEGROUP ?? [] as $groupXml) {
                    $classified = $classified || LenexParserService::agegroupHandicap($groupXml) !== '';
                }

                $strokeCode = (string) ($styleXml['stroke'] ?? '');
                $stroke = StrokeType::findByLenexCode($strokeCode);
                $events[$eventId] = [
                    'label' => trim(((int) ($styleXml['distance'] ?? 0)).' m '.($stroke?->name_de ?? $strokeCode)),
                    'classified' => $classified,
                ];
            }
        }

        return $events;
    }

    /**
     * @template T of array
     *
     * @param  list<T>  $rows
     * @return list<T>
     */
    private static function sortByName(array $rows, string $key): array
    {
        usort($rows, fn (array $a, array $b): int => strcmp(
            sprintf('%s|%s', mb_strtolower($a[$key]), $a['lenex_id']),
            sprintf('%s|%s', mb_strtolower($b[$key]), $b['lenex_id']),
        ));

        return $rows;
    }
}
