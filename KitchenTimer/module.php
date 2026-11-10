<?php

declare(strict_types=1);

/**
 * Küchentimer: mehrere benannte Kurzzeitwecker in einer Kachel. Die Timer laufen im Modul (Endzeiten im
 * Attribut `Timers`), damit sie ohne offene Visu weiterlaufen und auf allen Geräten gleich sind. Die Kachel
 * zählt selbst herunter und bekommt nur bei Änderungen eine Nachricht.
 */
class KitchenTimer extends IPSModuleStrict
{
    private const MAX_TIMERS = 10;
    private const MAX_SECONDS = 86400;
    private const MAX_NAME = 40;
    private const TILE_TEXTS = ['Timer', 'New timer', 'Name', 'Minutes', 'Seconds', 'Start', 'Cancel', 'Stop', 'Pause', 'Resume',
        'No timer running', 'Time is up', 'min', 'more', 'Maximum number of timers reached'];

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('Presets', '[]');
        $this->RegisterPropertyString('QuickMinutes', '1, 3, 5, 10, 15');
        $this->RegisterPropertyBoolean('Sound', true);
        $this->RegisterPropertyString('RingAction', '{}');
        $this->RegisterPropertyInteger('RepeatSeconds', 20);
        $this->RegisterPropertyInteger('RingMinutes', 3);
        $this->RegisterAttributeString('Timers', '[]');
        $this->RegisterTimer('Tick', 0, 'KTIMER_Tick($_IPS[\'TARGET\']);');

        $value = static fn (string $icon): array => ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => $icon];
        $this->RegisterVariableInteger('Running', $this->Translate('Running timers'), $value('timer'), 10);
        $this->RegisterVariableString('NextName', $this->Translate('Next timer'), $value('hourglass-half'), 20);
        $this->RegisterVariableString('NextEnd', $this->Translate('Next timer ends at'), $value('clock'), 30);
        $this->RegisterVariableBoolean('Ringing', $this->Translate('Ringing'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH, 'USE_ICON_FALSE' => true, 'ICON_TRUE' => 'bell-ring', 'ICON_FALSE' => 'bell',
        ], 40);
        $this->EnableAction('Ringing');
        $this->RegisterVariableString('RingingName', $this->Translate('Ringing timer'), $value('bell'), 50);

        $this->SetVisualizationType(defined('INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN') ? INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN : 1);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }
        // Nach einem Neustart: abgelaufene Timer klingeln sofort, die übrigen laufen weiter
        $this->Tick();
        $this->UpdateVisualizationValue(json_encode(['type' => 'reload']));
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    // ── Öffentliche Funktionen ──

    /** Startet einen Timer und liefert seine Kennung; '' wenn die Dauer ungültig ist oder schon zehn Timer laufen. */
    public function Start(string $Name, int $Seconds): string
    {
        $timers = $this->Timers();
        if ($Seconds < 1 || $Seconds > self::MAX_SECONDS || count($timers) >= self::MAX_TIMERS) {
            $this->LogMessage($this->Translate(count($timers) >= self::MAX_TIMERS ? 'Maximum number of timers reached' : 'Invalid duration'), KL_WARNING);
            return '';
        }
        $name = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $Name) ?? ''), 0, self::MAX_NAME);
        $id = bin2hex(random_bytes(4));
        $timers[] = ['id' => $id, 'name' => $name === '' ? $this->Translate('Timer') : $name, 'duration' => $Seconds,
            'state' => 'running', 'endsAt' => $this->Now() + $Seconds * 1000, 'remaining' => 0, 'rangAt' => 0, 'actionAt' => 0];
        $this->Store($timers);
        return $id;
    }

    public function Pause(string $TimerID): bool
    {
        return $this->Change($TimerID, function (array $timer): ?array {
            if ($timer['state'] !== 'running') {
                return null;
            }
            return ['state' => 'paused', 'remaining' => max(1, (int) ceil(($timer['endsAt'] - $this->Now()) / 1000)), 'endsAt' => 0] + $timer;
        });
    }

    public function Resume(string $TimerID): bool
    {
        return $this->Change($TimerID, fn (array $timer): ?array => $timer['state'] !== 'paused' ? null
            : ['state' => 'running', 'endsAt' => $this->Now() + $timer['remaining'] * 1000, 'remaining' => 0] + $timer);
    }

    /** Verlängert einen Timer; ein klingelnder läuft mit dieser Zeit neu an. */
    public function Extend(string $TimerID, int $Seconds): bool
    {
        if ($Seconds < 1 || $Seconds > self::MAX_SECONDS) {
            return false;
        }
        return $this->Change($TimerID, function (array $timer) use ($Seconds): ?array {
            $duration = min(self::MAX_SECONDS, $timer['duration'] + $Seconds);
            return match ($timer['state']) {
                'paused'  => ['remaining' => min(self::MAX_SECONDS, $timer['remaining'] + $Seconds), 'duration' => $duration] + $timer,
                'ringing' => ['state' => 'running', 'endsAt' => $this->Now() + $Seconds * 1000, 'duration' => $Seconds, 'rangAt' => 0, 'actionAt' => 0] + $timer,
                default   => ['endsAt' => $timer['endsAt'] + $Seconds * 1000, 'duration' => $duration] + $timer,
            };
        });
    }

    /** Beendet einen Timer, auch einen klingelnden. */
    public function Stop(string $TimerID): bool
    {
        $timers = $this->Timers();
        $rest = array_values(array_filter($timers, static fn (array $timer): bool => $timer['id'] !== $TimerID));
        if (count($rest) === count($timers)) {
            return false;
        }
        $this->Store($rest);
        return true;
    }

    public function StopAll(): void
    {
        $this->Store([]);
    }

    /** Bestätigt alle klingelnden Timer. */
    public function Acknowledge(): void
    {
        $this->Store(array_values(array_filter($this->Timers(), static fn (array $timer): bool => $timer['state'] !== 'ringing')));
    }

    /** Alle Timer als JSON: id, name, duration, state (running, paused, ringing), remaining (Sekunden). */
    public function GetTimers(): string
    {
        $now = $this->Now();
        return json_encode(array_map(static fn (array $timer): array => [
            'id' => $timer['id'], 'name' => $timer['name'], 'duration' => $timer['duration'], 'state' => $timer['state'],
            'remaining' => $timer['state'] === 'running' ? max(0, (int) ceil(($timer['endsAt'] - $now) / 1000)) : $timer['remaining'],
        ], $this->Timers()), JSON_UNESCAPED_UNICODE);
    }

    /** Timer `Tick`: abgelaufene Timer klingeln lassen, Aktion wiederholen, ausgeklingelte entfernen. */
    public function Tick(): void
    {
        $now = $this->Now();
        $repeat = max(0, $this->ReadPropertyInteger('RepeatSeconds')) * 1000;
        $ringFor = max(1, $this->ReadPropertyInteger('RingMinutes')) * 60000;
        $timers = [];
        $act = false;
        foreach ($this->Timers() as $timer) {
            if ($timer['state'] === 'running' && $timer['endsAt'] <= $now) {
                $timer = ['state' => 'ringing', 'endsAt' => 0, 'rangAt' => $now, 'actionAt' => 0] + $timer;
            }
            if ($timer['state'] === 'ringing') {
                if ($now - $timer['rangAt'] >= $ringFor) {
                    continue;
                }
                if ($timer['actionAt'] === 0 || ($repeat > 0 && $now - $timer['actionAt'] >= $repeat)) {
                    $timer['actionAt'] = $now;
                    $act = true;
                }
            }
            $timers[] = $timer;
        }
        $this->Store($timers);
        if ($act) {
            $this->RunRingAction();
        }
    }

    // ── Kachel ──

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'Ringing') {
            if (!(bool) $Value) {
                $this->Acknowledge();
            }
            return;
        }
        $data = is_string($Value) ? json_decode($Value, true) : null;
        $id = is_array($data) && is_string($data['id'] ?? null) && preg_match('/^[a-f0-9]{8}$/', $data['id']) ? $data['id'] : '';
        $seconds = is_array($data) && is_int($data['seconds'] ?? null) ? $data['seconds'] : 0;
        switch ($Ident) {
            case 'Start':
                if ($this->Start(is_array($data) && is_string($data['name'] ?? null) ? $data['name'] : '', $seconds) === '') {
                    // Die Kachel hat schon einen Platzhalter gezeigt: den echten Stand nachschicken
                    $this->Push();
                }
                return;
            case 'Pause':
                $this->Pause($id);
                return;
            case 'Resume':
                $this->Resume($id);
                return;
            case 'Extend':
                $this->Extend($id, $seconds);
                return;
            case 'Stop':
                $this->Stop($id);
                return;
        }
        throw new InvalidArgumentException('Invalid ident');
    }

    public function GetVisualizationTile(): string
    {
        $texts = [];
        foreach (self::TILE_TEXTS as $text) {
            $texts[$text] = $this->Translate($text);
        }
        $config = ['timers' => $this->TileTimers(), 'now' => $this->Now(), 'presets' => $this->Presets(), 'quick' => $this->QuickMinutes(),
            'sound' => $this->ReadPropertyBoolean('Sound'), 'max' => self::MAX_TIMERS, 'texts' => $texts];
        return strtr((string) file_get_contents(__DIR__ . '/module.html'), [
            '/*CONFIG*/null' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP),
        ]);
    }

    public function GetConfigurationForm(): string
    {
        return (string) file_get_contents(__DIR__ . '/form.json');
    }

    // ── Intern ──

    /** Uhrzeit in Millisekunden (in Tests überschrieben). */
    protected function Now(): int
    {
        return (int) (microtime(true) * 1000);
    }

    /** @return list<array{id: string, name: string, duration: int, state: string, endsAt: int, remaining: int, rangAt: int, actionAt: int}> */
    private function Timers(): array
    {
        $timers = json_decode($this->ReadAttributeString('Timers'), true);
        return is_array($timers) ? array_values($timers) : [];
    }

    /** Ändert einen Timer über $Change (null = keine Änderung möglich). */
    private function Change(string $TimerID, callable $Change): bool
    {
        $timers = $this->Timers();
        foreach ($timers as $index => $timer) {
            if ($timer['id'] === $TimerID) {
                $changed = $Change($timer);
                if ($changed === null) {
                    return false;
                }
                $timers[$index] = $changed;
                $this->Store($timers);
                return true;
            }
        }
        return false;
    }

    /** Timer merken, Variablen und Kacheln nachziehen (nur bei Änderung) und den Wecker auf das nächste Ereignis stellen. */
    private function Store(array $Timers): void
    {
        $json = json_encode($Timers, JSON_UNESCAPED_UNICODE);
        if ($json !== $this->ReadAttributeString('Timers')) {
            $this->WriteAttributeString('Timers', $json);
            $this->UpdateVariables($Timers);
            $this->Push();
        }
        $now = $this->Now();
        $repeat = max(0, $this->ReadPropertyInteger('RepeatSeconds')) * 1000;
        $ringFor = max(1, $this->ReadPropertyInteger('RingMinutes')) * 60000;
        $next = null;
        foreach ($Timers as $timer) {
            $due = match ($timer['state']) {
                'running' => $timer['endsAt'],
                'ringing' => $repeat > 0 ? min($timer['rangAt'] + $ringFor, $timer['actionAt'] + $repeat) : $timer['rangAt'] + $ringFor,
                default   => null,
            };
            $next = $due === null ? $next : min($next ?? $due, $due);
        }
        $this->SetTimerInterval('Tick', $next === null ? 0 : max(100, $next - $now + 20));
    }

    private function UpdateVariables(array $Timers): void
    {
        $running = array_values(array_filter($Timers, static fn (array $timer): bool => $timer['state'] === 'running'));
        usort($running, static fn (array $a, array $b): int => $a['endsAt'] <=> $b['endsAt']);
        $ringing = array_values(array_filter($Timers, static fn (array $timer): bool => $timer['state'] === 'ringing'));
        $set = function (string $ident, mixed $value): void {
            if ($this->GetValue($ident) !== $value) {
                $this->SetValue($ident, $value);
            }
        };
        $set('Running', count($running));
        $set('NextName', $running[0]['name'] ?? '');
        $set('NextEnd', $running === [] ? '' : date('H:i:s', intdiv($running[0]['endsAt'], 1000)));
        // Erst der Name, dann der Schalter: Ereignisse auf „Klingelt“ finden den Namen schon vor
        $set('RingingName', implode(', ', array_column($ringing, 'name')));
        $set('Ringing', $ringing !== []);
    }

    private function TileTimers(): array
    {
        return array_map(static fn (array $timer): array => array_intersect_key($timer, array_flip(['id', 'name', 'duration', 'state', 'endsAt', 'remaining'])), $this->Timers());
    }

    private function Push(): void
    {
        $this->UpdateVisualizationValue(json_encode(['type' => 'timers', 'timers' => $this->TileTimers(), 'now' => $this->Now()], JSON_UNESCAPED_UNICODE));
    }

    /** Die gewählte Aktion in einem eigenen Durchlauf starten: eine lange Ansage hält die Instanz sonst auf. */
    private function RunRingAction(): void
    {
        $action = json_decode($this->ReadPropertyString('RingAction'), true);
        if (!is_array($action) || !is_string($action['actionID'] ?? null) || $action['actionID'] === '') {
            return;
        }
        $parameters = is_array($action['parameters'] ?? null) ? $action['parameters'] : [];
        IPS_RunScriptText('IPS_RunAction(' . var_export($action['actionID'], true) . ', json_decode(' . var_export(json_encode($parameters), true) . ', true));');
    }

    /** @return list<array{name: string, seconds: int}> Vorlagen aus dem Formular */
    private function Presets(): array
    {
        $presets = [];
        foreach (json_decode($this->ReadPropertyString('Presets'), true) ?: [] as $row) {
            $seconds = (int) ($row['Minutes'] ?? 0) * 60 + (int) ($row['Seconds'] ?? 0);
            $name = mb_substr(trim((string) ($row['Name'] ?? '')), 0, self::MAX_NAME);
            if ($name !== '' && $seconds >= 1 && $seconds <= self::MAX_SECONDS && count($presets) < 12) {
                $presets[] = ['name' => $name, 'seconds' => $seconds];
            }
        }
        return $presets;
    }

    /** @return list<int> Minuten der Schnellstart-Knöpfe */
    private function QuickMinutes(): array
    {
        $minutes = array_filter(array_map('intval', preg_split('/[\s,;]+/', $this->ReadPropertyString('QuickMinutes')) ?: []),
            static fn (int $minute): bool => $minute >= 1 && $minute <= 1440);
        return array_slice(array_values(array_unique($minutes)), 0, 8);
    }
}
