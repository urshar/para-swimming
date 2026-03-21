<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * swim_events — basierend auf LENEX 3.0 Element EVENT + SWIMSTYLE
     *
     * SWIMSTYLE hat in LENEX 3.0 folgende Attribute:
     *   stroke      → Pflicht (FREE, BACK, BREAST, FLY, MEDLEY, IMRELAY, UNKNOWN + Fin)
     *   distance    → Pflicht (Distanz in Metern, bei Staffel pro Schwimmer)
     *   relaycount  → Pflicht (1 = Einzel, >1 = Staffel)
     *   technique   → Optional (DIVE, GLIDE, KICK, PULL, START, TURN)
     *   code        → Optional (max. 6 Zeichen, wenn stroke = UNKNOWN)
     *   name        → Optional (Beschreibungsname wenn stroke = UNKNOWN)
     *
     * EVENT zusätzlich:
     *   round       → TIM, FHT, FIN, SEM, QUA, PRE, SOP, SOS, SOQ, TIMETRIAL
     *   status      → ENTRIES, SEEDED, RUNNING, UNOFFICIAL, OFFICIAL
     *   gender      → M, F, A (all/default), X (mixed, nur Staffel)
     *   timing      → überschreibt Meet-Timing pro Event
     *   preveventid → Referenz auf vorherigen Event (z.B. Vorlauf → Finale)
     */
    public function up(): void
    {
        Schema::create('swim_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stroke_type_id')->constrained()->restrictOnDelete();

            // EVENT Felder
            $table->integer('event_number')->nullable();
            $table->integer('session_number')->default(1);

            // LENEX EVENT.gender: M, F, A (all = default), X (mixed, Staffel)
            $table->enum('gender', ['M', 'F', 'A', 'X'])->default('A');

            // LENEX EVENT.round
            $table->enum('round', [
                'TIM',       // Timed finals (default)
                'FHT',       // Fastest heats
                'FIN',       // Finals (A, B, C …)
                'SEM',       // Semi finals
                'QUA',       // Quarter finals
                'PRE',       // Prelims
                'SOP',       // Swim-off after prelims
                'SOS',       // Swim-off after semi-finals
                'SOQ',       // Swim-off after quarterfinals
                'TIMETRIAL', // Time trial
            ])->default('TIM');

            // LENEX EVENT.status
            $table->enum('lenex_status', [
                'ENTRIES',
                'SEEDED',
                'RUNNING',
                'UNOFFICIAL',
                'OFFICIAL',
            ])->nullable();

            // SWIMSTYLE Felder
            $table->integer('distance');

            // relaycount: 1 = Einzel, >1 = Staffel (Anzahl Schwimmer)
            $table->integer('relay_count')->default(1);

            // SWIMSTYLE.technique (optional)
            $table->enum('technique', [
                'DIVE',
                'GLIDE',
                'KICK',
                'PULL',
                'START',
                'TURN',
            ])->nullable();

            // SWIMSTYLE.code (max. 6 Zeichen, nur wenn stroke = UNKNOWN)
            $table->string('style_code', 6)->nullable();

            // SWIMSTYLE.name (nur wenn stroke = UNKNOWN)
            $table->string('style_name')->nullable();

            // Para: Leerzeichen-getrennte Klassenliste z.B. "S1 S2 S3" oder "SB14"
            $table->string('sport_classes')->nullable();

            // Referenz auf vorherigen Event (Vorlauf → Finale), -1 = kein Vorgänger
            $table->integer('prev_event_id')->nullable();

            // EVENT.timing (überschreibt Meet-Timing)
            $table->enum('timing', [
                'AUTOMATIC',
                'SEMIAUTOMATIC',
                'MANUAL3',
                'MANUAL2',
                'MANUAL1',
            ])->nullable();

            // LENEX Import-Referenz: nicht unique — Meetmanager vergibt pro Export neu
            $table->string('lenex_event_id')->nullable();

            $table->timestamps();

            $table->index(['meet_id', 'lenex_event_id']);
            $table->index(['meet_id', 'gender', 'distance']);
            $table->index('stroke_type_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swim_events');
    }
};
