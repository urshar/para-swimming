<?php

namespace App\Services;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Liest die Access-Datei (.mdb/.accdb) des Splash Team Managers über PDO_ODBC.
 *
 * Läuft nur auf einem Windows-Rechner mit dem "Microsoft Access Driver (*.mdb, *.accdb)" (64 bit) — der Import ist
 * ein einmaliger Umzug auf der Dev-Umgebung, prod bekommt die Daten danach per Seeder.
 */
final readonly class AccessTeamManagerSource implements TeamManagerSource
{
    private const string DRIVER = 'Microsoft Access Driver (*.mdb, *.accdb)';

    public function read(string $path): array
    {
        if (! extension_loaded('pdo_odbc')) {
            throw new RuntimeException('Die PHP-Erweiterung pdo_odbc fehlt — der Import läuft nur auf einem Windows-Rechner mit Access-Treiber.');
        }

        try {
            $pdo = new PDO('odbc:Driver={'.self::DRIVER.'};Dbq='.$path.';ReadOnly=1;');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return [
                'clubs' => $this->rows($pdo, 'SELECT * FROM CLUBS ORDER BY CLUBSID'),
                'members' => $this->rows($pdo, 'SELECT * FROM MEMBERS ORDER BY MEMBERSID'),
            ];
        } catch (PDOException $e) {
            throw new RuntimeException('Die Datei ist keine lesbare Team-Manager-Datei: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * Access liefert Texte in Windows-1252; alles andere (Zahlen, Datumswerte) bleibt davon unberührt.
     *
     * @return list<array<string, string|null>>
     */
    private function rows(PDO $pdo, string $sql): array
    {
        $rows = [];

        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = array_map(
                fn ($value) => $value === null ? null : mb_convert_encoding((string) $value, 'UTF-8', 'Windows-1252'),
                $row,
            );
        }

        return $rows;
    }
}
