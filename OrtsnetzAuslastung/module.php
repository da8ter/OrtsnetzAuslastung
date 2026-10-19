<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/OnaPayload.php';

/**
 * Sendet Netzspannungsmessungen alle 5 Minuten an ortsnetz-auslastung.de und zeigt die Antwort
 * (Ampel je Phase, Speicherladeempfehlung, Redispatch in der Umgebung) als Variablen.
 * Quelle sind vorhandene Symcon-Variablen; Min/Max/Mittel der Spannungen und der Frequenz
 * bildet das Modul selbst aus allen Aktualisierungen im Intervall.
 */
class OrtsnetzAuslastung extends IPSModuleStrict
{
    private const LOCATION_CONTROL = '{45E97A63-F870-408A-B259-2933F7EABF74}';
    // Aeltere Werte gelten als ausgefallene Messung: lieber nicht senden als Stand von gestern melden
    private const STALE_S = 900;
    // Schutz der oeffentlichen API vor wiederholtem Druecken von "Jetzt senden"
    private const MIN_GAP_S = 60;

    private const STATUS_FAILED = 200;
    private const STATUS_NO_LOCATION = 201;
    private const STATUS_NO_VOLTAGE = 202;
    private const STATUS_SKIPPED = 203;
    private const STATUS_REJECTED = 204;
    private const STATUS_BLOCKED = 205;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Active', false);
        $this->RegisterPropertyString('Location', '{"latitude":0,"longitude":0}');
        $this->RegisterPropertyInteger('Precision', 3);
        $this->RegisterPropertyInteger('VoltageL1', 0);
        $this->RegisterPropertyInteger('VoltageL2', 0);
        $this->RegisterPropertyInteger('VoltageL3', 0);
        $this->RegisterPropertyInteger('Frequency', 0);
        $this->RegisterPropertyBoolean('MinMaxAvg', true);
        $this->RegisterPropertyInteger('CurrentL1', 0);
        $this->RegisterPropertyInteger('CurrentL2', 0);
        $this->RegisterPropertyInteger('CurrentL3', 0);
        $this->RegisterPropertyInteger('GridPower', 0);
        $this->RegisterPropertyInteger('GridPowerFactor', 1);
        $this->RegisterPropertyBoolean('GridPowerInvert', false);
        $this->RegisterPropertyInteger('BatterySOC', 0);
        $this->RegisterPropertyInteger('PVForecast', 0);
        $this->RegisterPropertyFloat('PlantCapacity', 0);
        $this->RegisterPropertyFloat('BatteryCapacity', 0);
        $this->RegisterPropertyString('SmartmeterModel', '');

        $this->RegisterTimer('Upload', 0, 'ONA_Upload($_IPS[\'TARGET\']);');

        $this->RegisterVariableInteger('STATUS', $this->Translate('Grid status'), $this->LightPresentation(), 10);
        $this->RegisterVariableInteger('STORAGE', $this->Translate('Storage recommendation'), $this->StoragePresentation(), 50);
        $this->RegisterVariableInteger('REDISPATCH', $this->Translate('Redispatch nearby'), $this->RedispatchPresentation(), 60);
        $this->RegisterVariableInteger('REDISPATCH_UNITS', $this->Translate('Redispatch: affected plants'), $this->CountPresentation(), 61);
        $this->RegisterVariableInteger('REDISPATCH_MEASURES', $this->Translate('Redispatch: measures'), $this->CountPresentation(), 62);
        $this->RegisterVariableInteger('REDISPATCH_UPDATED', $this->Translate('Redispatch: data as of'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME,
        ], 63);
        $this->RegisterVariableInteger('LAST_UPLOAD', $this->Translate('Last upload'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME,
        ], 70);
        $this->RegisterVariableString('LAST_MESSAGE', $this->Translate('Last message'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Information',
        ], 71);
        // Vor der ersten Antwort ist nichts bekannt: 0 hiesse sonst "Gruen" bzw. "Keine Aktion"
        if ($this->GetValue('LAST_UPLOAD') === 0) {
            foreach (['STATUS', 'STORAGE', 'REDISPATCH', 'REDISPATCH_UNITS', 'REDISPATCH_MEASURES'] as $ident) {
                $this->SetValue($ident, OnaPayload::UNKNOWN);
            }
        }
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        // Darstellung bestehender Variablen nachziehen (bis Build 1: Aufzaehlung, die die Visu ohne Aktion als Zahl zeigte)
        $this->MaintainVariable('STATUS', $this->Translate('Grid status'), VARIABLETYPE_INTEGER, $this->LightPresentation(), 10, true);
        $this->MaintainVariable('STORAGE', $this->Translate('Storage recommendation'), VARIABLETYPE_INTEGER, $this->StoragePresentation(), 50, true);
        $this->MaintainVariable('REDISPATCH', $this->Translate('Redispatch nearby'), VARIABLETYPE_INTEGER, $this->RedispatchPresentation(), 60, true);

        // Ampel je Phase nur fuer eingerichtete Phasen
        foreach ([1, 2, 3] as $i) {
            $ident = 'STATUS_L' . $i;
            $keep = $this->ReadPropertyInteger('VoltageL' . $i) > 0;
            $new = $keep && @IPS_GetObjectIDByIdent($ident, $this->InstanceID) === false;
            $this->MaintainVariable($ident, sprintf($this->Translate('Grid status L%d'), $i), VARIABLETYPE_INTEGER,
                $this->LightPresentation(), 10 + $i, $keep);
            if ($new) {
                $this->SetValue($ident, OnaPayload::UNKNOWN); // bis zur naechsten Antwort unbekannt, nicht "Gruen"
            }
        }

        $this->WatchSources();
        // Ein neues Intervall: Statistik und gesendete Standortangaben gelten nicht mehr fuer die neue Einrichtung
        $this->SetBuffer('Stats', '');

        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetTimerInterval('Upload', 0);
            $this->SetStatus(IS_INACTIVE);
            return;
        }
        [$lat, $lon] = $this->Location();
        if (!OnaPayload::ValidLocation($lat, $lon)) {
            $this->SetTimerInterval('Upload', 0);
            $this->SetStatus(self::STATUS_NO_LOCATION);
            return;
        }
        if ($this->ConfiguredPhases() === []) {
            $this->SetTimerInterval('Upload', 0);
            $this->SetStatus(self::STATUS_NO_VOLTAGE);
            return;
        }
        $this->ScheduleNext();
        // Neue Einrichtung: das Ergebnis des naechsten Uploads entscheidet ueber den Status
        $this->SetStatus(IS_ACTIVE);
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
            return;
        }
        if ($Message !== VM_UPDATE || !$this->ReadPropertyBoolean('MinMaxAvg')) {
            return;
        }
        $watch = json_decode($this->GetBuffer('Watch'), true);
        $key = is_array($watch) ? ($watch[(string)$SenderID] ?? null) : null;
        if (!is_string($key) || !is_numeric($Data[0] ?? null)) {
            return;
        }
        $this->WithStats(function (array $stats) use ($key, $Data): array {
            return OnaPayload::AddSample($stats, $key, (float)$Data[0]);
        });
    }

    /** Eine Messung senden (Timer alle 5 Minuten oder Knopf). Liefert das Ergebnis als Text. */
    public function Upload(): string
    {
        if ($this->ReadPropertyBoolean('Active')) {
            $this->ScheduleNext();
        } else {
            return $this->Translate('Sending is switched off.');
        }
        $last = (int)$this->GetBuffer('LastAttempt');
        if ($last > 0 && time() - $last < self::MIN_GAP_S) {
            return $this->Translate('The last upload was less than a minute ago.');
        }
        $this->SetBuffer('LastAttempt', (string)time());

        $stats = [];
        $this->WithStats(function (array $s) use (&$stats): array {
            $stats = $s;
            return [];
        });
        [$build, $staticHash] = $this->Collect($stats);
        if (!$build['ok']) {
            $status = match ($build['error']) {
                'No valid location'              => self::STATUS_NO_LOCATION,
                'No voltage variable configured' => self::STATUS_NO_VOLTAGE,
                default                          => self::STATUS_SKIPPED,
            };
            return $this->Report($status, $this->Translate($build['error']));
        }

        $body = (string)json_encode($build['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $this->SendDebug('Upload', $body, 0);
        [$code, $response, $error] = $this->HttpPost(OnaPayload::API_URL, $body);
        $this->SendDebug('Antwort', $code . ' ' . $response . ($error !== '' ? ' (' . $error . ')' : ''), 0);

        if ($code === 202) {
            $r = OnaPayload::ParseResponse(json_decode($response, true));
            $this->SetValue('STATUS', $r['overall']);
            foreach ([1, 2, 3] as $i) {
                if ($this->ReadPropertyInteger('VoltageL' . $i) > 0) {
                    $this->SetValue('STATUS_L' . $i, $r['l' . $i]);
                }
            }
            $this->SetValue('STORAGE', $r['storage']);
            $this->SetValue('REDISPATCH', $r['redispatch']);
            $this->SetValue('REDISPATCH_UNITS', $r['units']);
            $this->SetValue('REDISPATCH_MEASURES', $r['measures']);
            $this->SetValue('REDISPATCH_UPDATED', $r['updated']);
            $this->SetValue('LAST_UPLOAD', time());
            $this->SetBuffer('StaticSent', $staticHash);
            $notes = array_map(fn (string $n): string => $this->Translate($n), $build['notes']);
            return $this->Report(IS_ACTIVE, implode(' ', array_merge([$this->Translate('Measurement accepted.')], $notes)));
        }
        if ($code === 422) {
            return $this->Report(self::STATUS_REJECTED, $this->Translate('Measurement rejected (422):') . ' ' . OnaPayload::ErrorDetail($response));
        }
        if ($code === 403) {
            return $this->Report(self::STATUS_BLOCKED, $this->Translate('The location is blocked by the service (403).'));
        }
        $detail = $code > 0 ? 'HTTP ' . $code : $error;
        return $this->Report(self::STATUS_FAILED, $this->Translate('Upload failed:') . ' ' . $detail);
    }

    /** Die Nutzlast, die jetzt gesendet wuerde, ohne zu senden (Knopf im Formular). */
    public function GetPayload(): string
    {
        $stats = json_decode($this->GetBuffer('Stats'), true);
        [$build] = $this->Collect(is_array($stats) ? $stats : []);
        if (!$build['ok']) {
            return $this->Translate($build['error']);
        }
        $text = (string)json_encode($build['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        foreach ($build['notes'] as $note) {
            $text .= "\n" . $this->Translate($note);
        }
        return $text;
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string)file_get_contents(__DIR__ . '/form.json'), true);
        [$lat, $lon, $source] = $this->Location(true);
        $digits = $this->ReadPropertyInteger('Precision');
        $form['actions'][0]['caption'] = OnaPayload::ValidLocation($lat, $lon)
            ? sprintf($this->Translate('Location sent: %s, %s (%s)'), OnaPayload::Coordinate($lat, $digits), OnaPayload::Coordinate($lon, $digits),
                $this->Translate($source === 'symcon' ? 'location of the Symcon system' : 'location of this instance'))
            : $this->Translate('No location: choose one here or set the location of the Symcon system (Location Control).');
        return (string)json_encode($form);
    }

    // ---------------------------------------------------------------- intern

    /** @return array{0:array, 1:string} Ergebnis von OnaPayload::Build und Pruefwert der Standortangaben */
    private function Collect(array $stats): array
    {
        [$lat, $lon] = $this->Location();
        $phases = [];
        foreach ([1, 2, 3] as $i) {
            $id = $this->ReadPropertyInteger('VoltageL' . $i);
            $phases[$i] = ['configured' => $id > 0, 'value' => $id > 0 ? $this->Read($id) : null];
            if ($id > 0 && IPS_VariableExists($id) && IPS_GetVariable($id)['VariableUpdated'] < time() - self::STALE_S) {
                return [['ok' => false, 'error' => 'Measured values are stale', 'payload' => [], 'notes' => []], ''];
            }
        }
        $power = $this->Read($this->ReadPropertyInteger('GridPower'));
        if ($power !== null) {
            $power *= max(1, $this->ReadPropertyInteger('GridPowerFactor'));
            // API: Bezug positiv, Einspeisung negativ
            $power = $this->ReadPropertyBoolean('GridPowerInvert') ? -$power : $power;
        }
        $static = [
            'plant_capacity_kwp' => $this->ReadPropertyFloat('PlantCapacity'),
            'battery_capacity'   => $this->ReadPropertyFloat('BatteryCapacity'),
            'smartmeter_model'   => $this->ReadPropertyString('SmartmeterModel'),
        ];
        $digits = $this->ReadPropertyInteger('Precision');
        $hash = OnaPayload::StaticHash($static, OnaPayload::Coordinate($lat, $digits), OnaPayload::Coordinate($lon, $digits));
        $build = OnaPayload::Build([
            'time'               => time(),
            'latitude'           => $lat,
            'longitude'          => $lon,
            'digits'             => $digits,
            'version'            => $this->IntegrationVersion(),
            'phases'             => $phases,
            'frequency'          => $this->Read($this->ReadPropertyInteger('Frequency')),
            'stats'              => $stats,
            'minmax'             => $this->ReadPropertyBoolean('MinMaxAvg'),
            'currents'           => [1 => $this->Read($this->ReadPropertyInteger('CurrentL1')), 2 => $this->Read($this->ReadPropertyInteger('CurrentL2')), 3 => $this->Read($this->ReadPropertyInteger('CurrentL3'))],
            'grid_power_w'       => $power,
            'battery_soc'        => $this->Read($this->ReadPropertyInteger('BatterySOC')),
            'pv_forecast_kwh'    => $this->Read($this->ReadPropertyInteger('PVForecast')),
            'plant_capacity_kwp' => $static['plant_capacity_kwp'],
            // Nur bei Einrichtung, Aenderung und nach einem Neustart (Puffer leer); Auslassen loescht nichts
            'static'             => $this->GetBuffer('StaticSent') === $hash ? null : $static,
        ]);
        return [$build, $hash];
    }

    /** Zahlenwert einer Quellvariable; null, wenn nicht gewaehlt, geloescht oder nicht numerisch. */
    private function Read(int $id): ?float
    {
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return null;
        }
        $v = GetValue($id);
        return is_int($v) || is_float($v) ? (float)$v : (is_string($v) && is_numeric($v) ? (float)$v : null);
    }

    /** @return array{0:float, 1:float, 2:string} Breite, Laenge, Quelle (instance|symcon) */
    private function Location(bool $withSource = false): array
    {
        $own = json_decode($this->ReadPropertyString('Location'), true);
        $lat = (float)($own['latitude'] ?? 0);
        $lon = (float)($own['longitude'] ?? 0);
        if (OnaPayload::ValidLocation($lat, $lon)) {
            return [$lat, $lon, 'instance'];
        }
        // Rueckfall: Standort des Symcon-Systems (Location Control)
        foreach (IPS_GetInstanceListByModuleID(self::LOCATION_CONTROL) as $lc) {
            $loc = json_decode((string)@IPS_GetProperty($lc, 'Location'), true);
            $lat = (float)($loc['latitude'] ?? 0);
            $lon = (float)($loc['longitude'] ?? 0);
            if (OnaPayload::ValidLocation($lat, $lon)) {
                return [$lat, $lon, 'symcon'];
            }
        }
        return [0.0, 0.0, ''];
    }

    /** @return list<int> */
    private function ConfiguredPhases(): array
    {
        return array_values(array_filter([1, 2, 3], fn (int $i): bool => $this->ReadPropertyInteger('VoltageL' . $i) > 0));
    }

    /** Referenzen fuer alle Quellen, Aktualisierungen von Spannung und Frequenz fuer die Statistik. */
    private function WatchSources(): void
    {
        foreach ($this->GetReferenceList() as $id) {
            $this->UnregisterReference($id);
        }
        foreach ($this->GetMessageList() as $id => $messages) {
            if (in_array(VM_UPDATE, $messages, true)) {
                $this->UnregisterMessage($id, VM_UPDATE);
            }
        }
        $watch = [];
        $keys = ['VoltageL1' => 'l1', 'VoltageL2' => 'l2', 'VoltageL3' => 'l3', 'Frequency' => 'f'];
        foreach (['VoltageL1', 'VoltageL2', 'VoltageL3', 'Frequency', 'CurrentL1', 'CurrentL2', 'CurrentL3', 'GridPower', 'BatterySOC', 'PVForecast'] as $prop) {
            $id = $this->ReadPropertyInteger($prop);
            if ($id <= 0 || !IPS_VariableExists($id)) {
                continue;
            }
            $this->RegisterReference($id);
            if (isset($keys[$prop]) && $this->ReadPropertyBoolean('MinMaxAvg')) {
                $this->RegisterMessage($id, VM_UPDATE);
                $watch[(string)$id] = $keys[$prop];
            }
        }
        $this->SetBuffer('Watch', (string)json_encode($watch));
    }

    /** Lesen-Aendern-Schreiben der Statistik unter Sperre (Nachrichten und Upload laufen in verschiedenen Threads). */
    private function WithStats(callable $change): void
    {
        $name = 'ONA_Stats_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($name, 2000)) {
            return;
        }
        try {
            $stats = json_decode($this->GetBuffer('Stats'), true);
            $stats = $change(is_array($stats) ? $stats : []);
            $this->SetBuffer('Stats', $stats === [] ? '' : (string)json_encode($stats));
        } finally {
            IPS_SemaphoreLeave($name);
        }
    }

    /**
     * Naechster Lauf im 5-Minuten-Raster, je Instanz um einige Sekunden versetzt,
     * damit nicht alle Symcon-Systeme in derselben Sekunde senden.
     */
    private function ScheduleNext(): void
    {
        $offset = 5 + $this->InstanceID % 50;
        $now = time();
        $next = (intdiv($now - $offset, OnaPayload::INTERVAL) + 1) * OnaPayload::INTERVAL + $offset;
        $this->SetTimerInterval('Upload', max(1, $next - $now) * 1000);
    }

    /** Ergebnis setzen; ins Meldungsfenster nur beim Wechsel in einen Fehler, nicht alle 5 Minuten. */
    private function Report(int $status, string $message): string
    {
        if ($status !== IS_ACTIVE && $status !== $this->GetStatus()) {
            $this->LogMessage($message, KL_WARNING);
        }
        $this->SetStatus($status);
        $this->SetValue('LAST_MESSAGE', $message);
        return $message;
    }

    private function IntegrationVersion(): string
    {
        $lib = json_decode((string)@file_get_contents(__DIR__ . '/../library.json'), true);
        return 'symcon-' . (is_array($lib) ? ($lib['version'] ?? '0') . '.' . ($lib['build'] ?? '0') : '0');
    }

    /** @return array{0:int, 1:string, 2:string} HTTP-Status (0 ohne Antwort), Antworttext, Fehlertext */
    protected function HttpPost(string $url, string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_USERAGENT      => 'Symcon-OrtsnetzAuslastung/' . $this->IntegrationVersion(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $response = curl_exec($ch);
        return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), is_string($response) ? $response : '', curl_error($ch)];
    }

    /*
     * Ampel, Empfehlung und Redispatch sind reine Anzeigewerte ohne Aktion. Eine Aufzaehlung zeigt die
     * Kachel-Visu dann als nackte Zahl (0, -1; gesehen 20.10.2026, Symcon 9.1). Deshalb Wertanzeige mit
     * je einem Intervall pro Zustand: fester Text plus Farbe.
     */
    private function LightPresentation(): array
    {
        return $this->StatePresentation('Electricity', [
            [OnaPayload::UNKNOWN, 'Unknown', -1],
            [0, 'Green', 0x2E7D32],
            [1, 'Yellow', 0xF9A825],
            [2, 'Red', 0xC62828],
        ]);
    }

    private function StoragePresentation(): array
    {
        return $this->StatePresentation('Battery', [
            [OnaPayload::UNKNOWN, 'Unknown', -1],
            [0, 'No action', -1],
            [1, 'Charge storage', 0x2E7D32],
            [2, 'Discharge storage', 0xEF6C00],
        ]);
    }

    private function RedispatchPresentation(): array
    {
        return $this->StatePresentation('Factory', [
            [OnaPayload::UNKNOWN, 'Unknown', -1],
            [0, 'No', 0x2E7D32],
            [1, 'Yes', 0xEF6C00],
        ]);
    }

    private function CountPresentation(): array
    {
        return $this->StatePresentation('', [[OnaPayload::UNKNOWN, 'Unknown', -1]]);
    }

    /** @param list<array{0:int, 1:string, 2:int}> $rows Wert, Text (wird uebersetzt), Farbe (-1 = keine) */
    private function StatePresentation(string $icon, array $rows): array
    {
        $intervals = array_map(fn (array $r): array => [
            'IntervalMinValue' => $r[0], 'IntervalMaxValue' => $r[0],
            'ConstantActive' => true, 'ConstantValue' => $this->Translate($r[1]), 'ConversionFactor' => 1,
            'PrefixActive' => false, 'PrefixValue' => '', 'SuffixActive' => false, 'SuffixValue' => '',
            'DigitsActive' => false, 'DigitsValue' => 0, 'IconActive' => false, 'IconValue' => '',
            'ColorActive' => $r[2] >= 0, 'ColorValue' => $r[2], 'ContentColorActive' => false, 'ContentColorValue' => -1,
        ], $rows);
        $p = [
            'PRESENTATION'     => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'INTERVALS_ACTIVE' => true,
            'INTERVALS'        => json_encode($intervals, JSON_UNESCAPED_UNICODE),
        ];
        if ($icon !== '') {
            $p['ICON'] = $icon;
        }
        return $p;
    }
}
