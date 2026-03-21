<?php

namespace Database\Seeders;

use App\Models\ExceptionCode;
use Illuminate\Database\Seeder;

class ExceptionCodesSeeder extends Seeder
{
    /**
     * WPS Codes of Exception — alle 17 Codes aus der offiziellen Dokumentation.
     *
     * applies_to Werte:
     *   null          → allgemein, unabhängig vom Schwimmstil (H, Y, E, A, T, B, 0)
     *   'BACK'        → nur Rücken (1)
     *   'FLY'         → nur Schmetterling (4, 5)
     *   'FLY_BREAST'  → Schmetterling + Brust (7)
     *   'BREAST_UPPER'→ Brust Oberkörper (2, 3)
     *   'BREAST_LOWER'→ Brust Unterkörper (8, 9, 12, +)
     *
     * Gilt-für-Zusammenfassung laut WPS Dokument:
     *   Freestyle    → keine Exceptions (nur 0/Nil)
     *   Backstroke   → nur Code 1
     *   Butterfly    → Codes 4, 5, 7
     *   Breaststroke → Oberkörper: 2, 3, 7 | Unterkörper: 8, 9, 12, +
     *   Allgemein    → H, Y, E, A, T, B (stilunabhängig)
     */
    public function run(): void
    {
        $codes = [

            // ── Allgemeine Codes (stilunabhängig) ─────────────────────────────
            [
                'code' => 'H',
                'name_en' => 'Hearing Impaired – Light or Signal Required',
                'name_de' => 'Hörbehindert – Licht oder Signal erforderlich',
                'description_en' => 'Swimmer with hearing impairment requires a light, signal or touch start. A strobe light may be placed by the starter or beside the relevant swimmers blocks. Other signals can be used such as an arm gesture. Support Staff may be used to perform a touch start.',
                'description_de' => 'Schwimmer mit Hörbehinderung benötigt ein Lichtsignal, ein anderes Signal oder einen Berührungsstart. Ein Stroboskoplicht kann neben dem Startblock platziert werden. Andere Signale wie ein Armzeichen sind möglich. Betreuungspersonal kann einen Berührungsstart durchführen.',
                'wps_rules' => '11.1.6, 11.1.7, 11.1.8, 1.4.4.3',
                'applies_to' => null,
            ],
            [
                'code' => 'Y',
                'name_en' => 'Starting Device',
                'name_de' => 'Starthilfsmittel / Startgerät',
                'description_en' => 'Swimmer uses a device when starting. A starting device is any assistive device that enables the swimmer to perform an effective start. Typical devices include straps, cords or towels which enable swimmers to grip effectively for backstroke or forward starts. Starting devices must be approved by WPS prior to use.',
                'description_de' => 'Schwimmer verwendet beim Start ein Hilfsmittel. Typische Geräte sind Gurte, Seile oder Handtücher, die ein effektives Greifen beim Rücken- oder Vorwärtsstart ermöglichen. Startgeräte müssen vorab von WPS genehmigt werden.',
                'wps_rules' => '11.1.2.8, 11.3.1.3',
                'applies_to' => null,
            ],
            [
                'code' => 'E',
                'name_en' => 'Unable to Grip for Backstroke Start',
                'name_de' => 'Kein Griff für Rücken-Start möglich',
                'description_en' => 'Swimmer is unable to hold the backstroke grips due to missing or weak hands and/or wrist. This code means a swimmer is permitted to start in backstroke without using the backstroke grips, holding the top of the starting/timing pad instead.',
                'description_de' => 'Schwimmer kann die Rücken-Startgriffe aufgrund fehlender oder schwacher Hände und/oder Handgelenke nicht halten. Der Schwimmer darf stattdessen die Oberkante des Start-/Zeitnahmepolsters halten.',
                'wps_rules' => '11.3.1.2',
                'applies_to' => null,
            ],
            [
                'code' => 'A',
                'name_en' => 'Assistance Required',
                'name_de' => 'Assistenz erforderlich',
                'description_en' => 'Swimmer requires assistance at the start or finish. Swimmers are entitled to a Support Staff who provides assistance at the start, to enter the pool or access the starting blocks prior to commencing the race and/or to assist exiting the pool at the end of the race.',
                'description_de' => 'Schwimmer benötigt Assistenz beim Start oder Ziel. Der Schwimmer hat Anspruch auf Betreuungspersonal, das beim Start, beim Einsteigen ins Becken oder beim Verlassen des Beckens am Ende des Rennens hilft.',
                'wps_rules' => '11.1.2.2, 11.1.2.8, 11.1.7, 11.1.8, 11.3.1.3',
                'applies_to' => null,
            ],
            [
                'code' => 'T',
                'name_en' => 'Tappers',
                'name_de' => 'Tapper (Wendesignalgeber)',
                'description_en' => 'Swimmer with visual impairment who requires a tapper. A tapper will use a tapping device to notify the swimmer when they are approaching the turn, by a single or double tap onto the swimmer. Tappers are compulsory for S/SB/SM11 swimmers. If a tapper is required at both ends, a separate tapper must be used.',
                'description_de' => 'Sehbehinderter Schwimmer benötigt einen Tapper. Der Tapper verwendet ein Tipp-Gerät um den Schwimmer durch einfaches oder doppeltes Tippen auf ihn zu signalisieren, dass er sich der Wende nähert. Tapper sind für S/SB/SM11 Schwimmer Pflicht.',
                'wps_rules' => '10.8.3, 10.8.3.1, 10.8.3.2, 10.8.3.3, 11.7.12',
                'applies_to' => null,
            ],
            [
                'code' => 'B',
                'name_en' => 'Blackened Goggles',
                'name_de' => 'Geschwärzte Schwimmbrille',
                'description_en' => 'For S/SB/SM11 swimmers it is compulsory to wear blackened goggles unless they have two prosthetic eyes. The goggles should be checked at the end of the race by a technical official. If the swimmer has no eyes they are not required to wear blackened goggles.',
                'description_de' => 'Für S/SB/SM11 Schwimmer ist das Tragen geschwärzter Brillen Pflicht, außer bei zwei Augenprothesen. Die Brille soll am Ende des Rennens von einem Kampfrichter überprüft werden.',
                'wps_rules' => '11.8.8',
                'applies_to' => null,
            ],
            [
                'code' => '0',
                'name_en' => 'Nil – No exceptions apply',
                'name_de' => 'Keine Ausnahmen',
                'description_en' => 'No exceptions apply to the swimmer.',
                'description_de' => 'Keine Ausnahmen für diesen Schwimmer.',
                'wps_rules' => null,
                'applies_to' => null,
            ],

            // ── Rückenschwimmen ───────────────────────────────────────────────
            [
                'code' => '1',
                'name_en' => 'One Hand Start',
                'name_de' => 'Einhandstart',
                'description_en' => 'The swimmer cannot grip the start with 2 hands. They will place one hand/arm on the start, but the other arm may sit next to the gripping arm, be in the water, or be non-existent.',
                'description_de' => 'Der Schwimmer kann den Start nicht mit 2 Händen greifen. Eine Hand/Arm wird am Start platziert, der andere Arm kann daneben liegen, im Wasser sein oder nicht vorhanden sein.',
                'wps_rules' => '11.3.1.1',
                'applies_to' => 'BACK',
            ],

            // ── Schmetterling ─────────────────────────────────────────────────
            [
                'code' => '4',
                'name_en' => 'Butterfly – One Hand Touch',
                'name_de' => 'Schmetterling – Einhandanschlag',
                'description_en' => 'The swimmer uses one arm to perform the swim stroke, so must touch at the turn and finish with the one hand or arm used for the swim. The non functioning arm may be dragged or stretched forward.',
                'description_de' => 'Der Schwimmer schwimmt einarming und muss daher bei Wende und Ziel mit dem verwendeten Arm anschlagen. Der nicht funktionsfähige Arm kann nachgezogen oder nach vorne gestreckt werden.',
                'wps_rules' => '11.5.4.3',
                'applies_to' => 'FLY',
            ],
            [
                'code' => '5',
                'name_en' => 'Butterfly – Simultaneous Intent to Touch',
                'name_de' => 'Schmetterling – Gleichzeitiger Anschlagsversuch',
                'description_en' => 'The swimmer uses both arms to perform the swim stroke. The swimmer must attempt to touch the wall with both arms/hands stretched forward. This exception means only the longer arm may touch the wall, but both arms must be stretched forward simultaneously.',
                'description_de' => 'Der Schwimmer schwimmt beidarmig. Es muss versucht werden, die Wand mit beiden vorgestreckten Armen zu berühren. Nur der längere Arm darf die Wand berühren, aber beide Arme müssen gleichzeitig nach vorne gestreckt sein.',
                'wps_rules' => '11.5.4.1, 11.5.4.4',
                'applies_to' => 'FLY',
            ],

            // ── Schmetterling + Brust ─────────────────────────────────────────
            [
                'code' => '7',
                'name_en' => 'Part of Upper Body Must Touch',
                'name_de' => 'Oberkörper-Anschlag',
                'description_en' => 'Allows for any part of the swimmers upper body to touch the wall at the turn or finish. Athletes will typically touch with their head or shoulders or their shortened arm(s).',
                'description_de' => 'Jeder Teil des Oberkörpers darf bei Wende und Ziel die Wand berühren. Üblicherweise wird mit dem Kopf, den Schultern oder verkürzten Armen angeschlagen.',
                'wps_rules' => '11.4.6.2, 11.5.4.2',
                'applies_to' => 'FLY_BREAST',
            ],

            // ── Brustschwimmen Oberkörper ─────────────────────────────────────
            [
                'code' => '2',
                'name_en' => 'Breaststroke – One Hand Touch',
                'name_de' => 'Brust – Einhandanschlag',
                'description_en' => 'The swimmer uses one arm to perform the swim stroke, so must touch at the turn and finish with the one hand or arm used for the swim. The non functioning arm may be dragged or stretched forward.',
                'description_de' => 'Der Schwimmer schwimmt einarming und muss daher bei Wende und Ziel mit dem verwendeten Arm anschlagen. Der nicht funktionsfähige Arm kann nachgezogen oder nach vorne gestreckt werden.',
                'wps_rules' => '11.4.6.3',
                'applies_to' => 'BREAST_UPPER',
            ],
            [
                'code' => '3',
                'name_en' => 'Breaststroke – Simultaneous Intent to Touch',
                'name_de' => 'Brust – Gleichzeitiger Anschlagsversuch',
                'description_en' => 'The swimmer uses both arms to perform the swim stroke. The swimmer must attempt to touch the wall with both hands simultaneously. This exception means only the longer arm may touch the wall, but both arms must be stretched forward simultaneously.',
                'description_de' => 'Der Schwimmer schwimmt beidarmig. Es muss versucht werden, die Wand mit beiden Händen gleichzeitig zu berühren. Nur der längere Arm darf die Wand berühren, aber beide Arme müssen gleichzeitig gestreckt sein.',
                'wps_rules' => '11.4.6.1, 11.4.6.4',
                'applies_to' => 'BREAST_UPPER',
            ],

            // ── Brustschwimmen Unterkörper ────────────────────────────────────
            [
                'code' => '8',
                'name_en' => 'Right Foot Must Turn Out',
                'name_de' => 'Rechter Fuß muss auswärts gedreht werden',
                'description_en' => 'The swimmer must turn out their right foot when performing the propulsive part of the breaststroke kick.',
                'description_de' => 'Der Schwimmer muss den rechten Fuß beim propulsiven Teil des Bruststoßes nach außen drehen.',
                'wps_rules' => '11.4.5.1',
                'applies_to' => 'BREAST_LOWER',
            ],
            [
                'code' => '9',
                'name_en' => 'Left Foot Must Turn Out',
                'name_de' => 'Linker Fuß muss auswärts gedreht werden',
                'description_en' => 'The swimmer must turn out their left foot when performing the propulsive part of the breaststroke kick.',
                'description_de' => 'Der Schwimmer muss den linken Fuß beim propulsiven Teil des Bruststoßes nach außen drehen.',
                'wps_rules' => '11.4.5.1',
                'applies_to' => 'BREAST_LOWER',
            ],
            [
                'code' => '12',
                'name_en' => 'Leg Drag OR Show Intent to Kick',
                'name_de' => 'Beine nachziehen ODER Stoßabsicht zeigen',
                'description_en' => 'The swimmer may choose to either drag both legs or show intent to kick. The swimmer must maintain the leg drag or the intent to kick throughout the race and may not change. E.g. a swimmer cannot drag legs for first 50m then begin kicking in the last 50m.',
                'description_de' => 'Der Schwimmer kann entweder beide Beine nachziehen oder Stoßabsicht zeigen. Diese Wahl muss über das gesamte Rennen beibehalten werden. Z.B. darf ein Schwimmer nicht die ersten 50m die Beine nachziehen und dann in den letzten 50m beginnen zu stoßen.',
                'wps_rules' => '11.4.4.1',
                'applies_to' => 'BREAST_LOWER',
            ],
            [
                'code' => '+',
                'name_en' => 'Athlete is physically capable of performing a Butterfly Kick',
                'name_de' => 'Athlet kann Delfinbeinschlag ausführen',
                'description_en' => 'The "+" code is not so much a rule exception but rather informs officials the swimmer is physically capable of performing a butterfly kick. If this action is observed during the normal breaststroke cycle, it is a violation of WPS Rule 11.4.5. Any swimmer is permitted to take a single butterfly kick at any time prior to the first breaststroke kick after the start or turn.',
                'description_de' => 'Der "+"-Code ist weniger eine Regelausnahme, sondern informiert Kampfrichter dass der Schwimmer physisch in der Lage ist, einen Delfinbeinschlag auszuführen. Wird dies während des normalen Bruststoßzyklus beobachtet, ist es eine Regelverletzung. Jeder Schwimmer darf vor dem ersten Bruststoß nach Start oder Wende einen einzelnen Delfinbeinschlag machen.',
                'wps_rules' => '11.4.1, 11.4.5',
                'applies_to' => 'BREAST_LOWER',
            ],
        ];

        foreach ($codes as $code) {
            ExceptionCode::updateOrCreate(
                ['code' => $code['code']],
                array_merge($code, ['is_active' => true])
            );
        }

        $this->command->info('ExceptionCodes: '.count($codes).' Einträge angelegt/aktualisiert.');
    }
}
