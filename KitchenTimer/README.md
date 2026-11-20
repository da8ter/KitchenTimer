# Küchentimer

![Küchentimer — Kachel](https://raw.githubusercontent.com/da8ter/images/main/Kuechentimer%20Kachel.png)

Mehrere benannte Kurzzeitwecker in einer Kachel der Symcon-Kachelvisualisierung. Die Timer laufen in Symcon, nicht im Browser: Sie laufen ohne offene Visualisierung weiter, sind auf allen Geräten gleich und überstehen einen Neustart.

## Funktionen

- Bis zu zehn Timer gleichzeitig, jeder mit Namen.
- Schnellstart über Minutenknöpfe und eigene Vorlagen (z. B. „Eier 7:00“), eigener Timer über „+“.
- Je Timer: Pause, Weiter, +1 Minute, Stopp. Ein klingelnder Timer lässt sich stoppen oder um eine Minute verlängern.
- Große Kachel: bis zu drei Timer als große Ringe nebeneinander, Restzeit und Knöpfe im Ring; mehr Timer oder zu wenig Breite als Liste. Kleine Kachel: der Timer, der als Nächstes abläuft bzw. klingelt.
- Beim Ablauf: Ton in der Kachel, eine frei wählbare Aktion (Ansage, Mitteilung, Licht …) und Variablen für eigene Ereignisse.

## Visualisierung

![Küchentimer — Liste](https://raw.githubusercontent.com/da8ter/images/main/Kuechentimer%20Liste.png)

![Küchentimer — Klingeln](https://raw.githubusercontent.com/da8ter/images/main/Kuechentimer%20Klingeln.png)

## Voraussetzungen

- Symcon ab Version 8.1 mit Kachelvisualisierung.

## Einrichtung

1. Instanz **Küchentimer** anlegen und in die Visualisierung aufnehmen.
2. Im Formular nach Wunsch Vorlagen (Name, Minuten, Sekunden) und die Minuten der Schnellstart-Knöpfe eintragen.
3. Unter **Wenn ein Timer abläuft** festlegen, was passiert:
   - **Ton in der Kachel abspielen**: Browser und Apps geben Ton erst frei, nachdem die Kachel einmal berührt wurde. Auf einem Gerät, an dem niemand die Kachel bedient hat, bleibt sie deshalb stumm. Für eine verlässliche Meldung die Aktion nutzen.
   - **Aktion**: eine beliebige Symcon-Aktion, etwa eine Ansage über einen Lautsprecher oder eine Mitteilung der Visualisierung. Den Namen des Timers liefert die Variable „Klingelnder Timer“.
   - **Aktion wiederholen**: Abstand in Sekunden, bis jemand bestätigt (0 = nur einmal).
   - **Klingeln automatisch beenden**: Danach verschwindet ein unbestätigter Timer von selbst.

## Variablen

| Name | Typ | Bedeutung |
|---|---|---|
| Laufende Timer | Integer | Anzahl der laufenden Timer (ohne pausierte und klingelnde) |
| Nächster Timer | String | Name des Timers, der als Nächstes abläuft |
| Nächster Timer endet um | String | Uhrzeit dazu (HH:MM:SS) |
| Klingelt | Boolean | An, solange ein Timer klingelt. Ausschalten bestätigt alle klingelnden Timer. |
| Klingelnder Timer | String | Namen der klingelnden Timer, durch Komma getrennt |

Die Restzeit selbst zeigt nur die Kachel; eine Variable im Sekundentakt gibt es bewusst nicht.

## Funktionen für Skripte

```php
KTIMER_Start(int $InstanzID, string $Name, int $Sekunden): string;   // liefert die Kennung des Timers, '' bei Fehler
KTIMER_Pause(int $InstanzID, string $TimerID): bool;
KTIMER_Resume(int $InstanzID, string $TimerID): bool;
KTIMER_Extend(int $InstanzID, string $TimerID, int $Sekunden): bool; // klingelnde Timer laufen damit neu an
KTIMER_Stop(int $InstanzID, string $TimerID): bool;
KTIMER_StopAll(int $InstanzID): void;
KTIMER_Acknowledge(int $InstanzID): void;                            // bestätigt alle klingelnden Timer
KTIMER_GetTimers(int $InstanzID): string;                            // JSON: id, name, duration, state, remaining
```

Beispiel: `KTIMER_Start(12345, 'Nudeln', 9 * 60);` – so startet auch eine Sprachroutine oder ein Taster einen Timer.

## Grenzen

- Höchstens zehn Timer, je Timer höchstens 24 Stunden.
- Der Ton der Kachel hängt vom Gerät ab (siehe Einrichtung).
