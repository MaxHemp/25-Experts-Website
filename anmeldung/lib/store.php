<?php
/**
 * 25 EXPERTS – Datenablage der Anmeldungen.
 *
 * Primär SQLite (PDO) in data/anmeldungen.sqlite; fällt pdo_sqlite auf dem Server, wird automatisch
 * eine JSON-Datei data/anmeldungen.json mit Dateisperre benutzt (STORE_BACKEND 'auto' | 'sqlite' | 'json').
 * Beide Varianten speichern jeden Datensatz als JSON-Dokument (Spalten: id, token, data) plus Zähler
 * (Rechnungs-/Ticketnummern). Die Datenmenge ist klein (25 Plätze, einige hundert Anmeldungen), deshalb
 * werden Listen und Filter im PHP-Speicher gebildet; das hält beide Backends identisch und einfach.
 *
 * Nutzung:
 *   $store = X25Store::open($dataDir, $backend);
 *   $store->transaction(function (X25Store $s) { ... });   // exklusive Sperre (Zähler, Platzvergabe)
 *   $s->insert(array $rec): int  |  $s->get(int $id): ?array  |  $s->findByToken(string $t): ?array
 *   $s->update(int $id, array $fields): void  |  $s->all(): array (neueste zuerst)  |  $s->nextNumber('rechnung'): int
 *   $s->deleteWhere(callable $pred): int
 */
declare(strict_types=1);

final class X25Store
{
    private ?PDO $pdo = null;
    private string $jsonFile = '';
    /** @var resource|null */
    private $lock = null;
    private int $depth = 0;
    public string $backend;

    public static function open(string $dataDir, string $backend = 'auto'): self
    {
        if (!is_dir($dataDir) && !@mkdir($dataDir, 0750, true)) {
            throw new RuntimeException('Datenverzeichnis nicht anlegbar: ' . basename($dataDir));
        }
        if (!is_writable($dataDir)) {
            throw new RuntimeException('Datenverzeichnis nicht beschreibbar (chmod 750/770): ' . basename($dataDir));
        }
        $s = new self();
        $canSqlite = extension_loaded('pdo_sqlite');
        if ($backend === 'auto') { $backend = $canSqlite ? 'sqlite' : 'json'; }
        if ($backend === 'sqlite' && !$canSqlite) {
            throw new RuntimeException('pdo_sqlite fehlt; STORE_BACKEND auf \'json\' stellen.');
        }
        $s->backend = $backend;
        if ($backend === 'sqlite') {
            $s->pdo = new PDO('sqlite:' . $dataDir . '/anmeldungen.sqlite', null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $s->pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;');
            $s->pdo->exec('CREATE TABLE IF NOT EXISTS anmeldungen (id INTEGER PRIMARY KEY AUTOINCREMENT, token TEXT UNIQUE NOT NULL, created_at TEXT NOT NULL, data TEXT NOT NULL)');
            $s->pdo->exec('CREATE TABLE IF NOT EXISTS zaehler (name TEXT PRIMARY KEY, wert INTEGER NOT NULL)');
        } else {
            $s->jsonFile = $dataDir . '/anmeldungen.json';
            if (!is_file($s->jsonFile)) {
                file_put_contents($s->jsonFile, json_encode(['next_id' => 1, 'zaehler' => [], 'rows' => []]), LOCK_EX);
            }
        }
        // Zugriffsschutz auch ohne funktionierende .htaccess: Dateien nur für den Webserver-Nutzer lesbar
        foreach (glob($dataDir . '/anmeldungen.*') ?: [] as $f) { @chmod($f, 0600); }
        return $s;
    }

    /** Führt $fn unter exklusiver Sperre aus (SQLite: BEGIN IMMEDIATE; JSON: flock). Verschachtelbar. */
    public function transaction(callable $fn)
    {
        $this->depth++;
        try {
            if ($this->depth === 1) {
                if ($this->pdo) { $this->pdo->exec('BEGIN IMMEDIATE'); }
                else {
                    $this->lock = fopen($this->jsonFile . '.lock', 'c');
                    if (!$this->lock || !flock($this->lock, LOCK_EX)) { throw new RuntimeException('Sperre nicht möglich'); }
                }
            }
            $r = $fn($this);
            if ($this->depth === 1 && $this->pdo) { $this->pdo->exec('COMMIT'); }
            return $r;
        } catch (Throwable $e) {
            if ($this->depth === 1 && $this->pdo && $this->pdo->inTransaction()) { $this->pdo->exec('ROLLBACK'); }
            throw $e;
        } finally {
            if ($this->depth === 1 && $this->lock) { flock($this->lock, LOCK_UN); fclose($this->lock); $this->lock = null; }
            $this->depth--;
        }
    }

    public function insert(array $rec): int
    {
        $rec['created_at'] = $rec['created_at'] ?? gmdate('c');
        if ($this->pdo) {
            $st = $this->pdo->prepare('INSERT INTO anmeldungen (token, created_at, data) VALUES (?, ?, ?)');
            $st->execute([$rec['token'], $rec['created_at'], json_encode($rec, JSON_UNESCAPED_UNICODE)]);
            $id = (int)$this->pdo->lastInsertId();
            $rec['id'] = $id;
            $this->pdo->prepare('UPDATE anmeldungen SET data = ? WHERE id = ?')->execute([json_encode($rec, JSON_UNESCAPED_UNICODE), $id]);
            return $id;
        }
        return $this->transaction(function () use ($rec) {
            $db = $this->readJson();
            $id = (int)$db['next_id']; $db['next_id'] = $id + 1;
            $rec['id'] = $id;
            $db['rows'][(string)$id] = $rec;
            $this->writeJson($db);
            return $id;
        });
    }

    public function get(int $id): ?array
    {
        if ($this->pdo) {
            $st = $this->pdo->prepare('SELECT data FROM anmeldungen WHERE id = ?');
            $st->execute([$id]);
            $row = $st->fetch();
            return $row ? json_decode((string)$row['data'], true) : null;
        }
        $db = $this->readJson();
        return $db['rows'][(string)$id] ?? null;
    }

    public function findByToken(string $token): ?array
    {
        if ($token === '') { return null; }
        if ($this->pdo) {
            $st = $this->pdo->prepare('SELECT data FROM anmeldungen WHERE token = ?');
            $st->execute([$token]);
            $row = $st->fetch();
            return $row ? json_decode((string)$row['data'], true) : null;
        }
        foreach ($this->readJson()['rows'] as $r) { if (($r['token'] ?? '') === $token) { return $r; } }
        return null;
    }

    /** Felder eines Datensatzes setzen (Merge). */
    public function update(int $id, array $fields): void
    {
        $this->transaction(function () use ($id, $fields) {
            $rec = $this->get($id);
            if ($rec === null) { throw new RuntimeException('Datensatz fehlt'); }
            $rec = array_merge($rec, $fields);
            $rec['updated_at'] = gmdate('c');
            if ($this->pdo) {
                $this->pdo->prepare('UPDATE anmeldungen SET data = ? WHERE id = ?')->execute([json_encode($rec, JSON_UNESCAPED_UNICODE), $id]);
            } else {
                $db = $this->readJson(); $db['rows'][(string)$id] = $rec; $this->writeJson($db);
            }
        });
    }

    /** Alle Datensätze, neueste zuerst. */
    public function all(): array
    {
        if ($this->pdo) {
            $out = [];
            foreach ($this->pdo->query('SELECT data FROM anmeldungen ORDER BY id DESC') as $row) { $out[] = json_decode((string)$row['data'], true); }
            return $out;
        }
        $rows = array_values($this->readJson()['rows']);
        usort($rows, static fn($a, $b) => ($b['id'] ?? 0) <=> ($a['id'] ?? 0));
        return $rows;
    }

    /** Fortlaufender Zähler (Rechnungs-/Ticketnummern); immer innerhalb transaction() aufrufen. */
    public function nextNumber(string $name): int
    {
        return $this->transaction(function () use ($name) {
            if ($this->pdo) {
                $st = $this->pdo->prepare('SELECT wert FROM zaehler WHERE name = ?'); $st->execute([$name]);
                $cur = (int)($st->fetchColumn() ?: 0);
                $this->pdo->prepare('INSERT INTO zaehler (name, wert) VALUES (?, ?) ON CONFLICT(name) DO UPDATE SET wert = excluded.wert')->execute([$name, $cur + 1]);
                return $cur + 1;
            }
            $db = $this->readJson();
            $cur = (int)($db['zaehler'][$name] ?? 0);
            $db['zaehler'][$name] = $cur + 1;
            $this->writeJson($db);
            return $cur + 1;
        });
    }

    /** Löscht alle Datensätze, für die $pred(rec) wahr ist; gibt die Anzahl zurück. Zähler bleiben (Nummern laufen weiter). */
    public function deleteWhere(callable $pred): int
    {
        return $this->transaction(function () use ($pred) {
            $n = 0;
            if ($this->pdo) {
                foreach ($this->all() as $r) {
                    if ($pred($r)) { $this->pdo->prepare('DELETE FROM anmeldungen WHERE id = ?')->execute([(int)$r['id']]); $n++; }
                }
                return $n;
            }
            $db = $this->readJson();
            foreach ($db['rows'] as $k => $r) { if ($pred($r)) { unset($db['rows'][$k]); $n++; } }
            $this->writeJson($db);
            return $n;
        });
    }

    // ---- JSON-Backend
    private function readJson(): array
    {
        $raw = (string)@file_get_contents($this->jsonFile);
        $db = json_decode($raw, true);
        if (!is_array($db)) { $db = ['next_id' => 1, 'zaehler' => [], 'rows' => []]; }
        $db['rows'] = $db['rows'] ?? []; $db['zaehler'] = $db['zaehler'] ?? []; $db['next_id'] = $db['next_id'] ?? 1;
        return $db;
    }
    private function writeJson(array $db): void
    {
        $tmp = $this->jsonFile . '.tmp';
        file_put_contents($tmp, json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        @chmod($tmp, 0600);
        rename($tmp, $this->jsonFile);
    }
}
