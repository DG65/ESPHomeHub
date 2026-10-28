<?php

declare(strict_types=1);

// ===========================================================================
// Noise_NNpsk0_25519_ChaChaPoly_SHA256 — die Verschlüsselung der ESPHome-API.
//
// Nachgebaut nach der Noise-Spezifikation (Rev. 34) und dem Ablauf in
// aioesphomeapi (_frame_helper/noise.py, MIT). Nur PHP-sodium und hash_hmac,
// keine Bibliothek. Byte-gleich gegen die Python-Bibliothek noiseprotocol
// geprüft (.tools/test-esphomehub.php).
//
// Symcon legt das Modulobjekt bei jedem Aufruf neu an. Deshalb lässt sich
// jeder Zustand (Handshake wie Sitzung) als Feld exportieren und wieder laden;
// das Gerätemodul legt ihn zwischen ReceiveData-Aufrufen im Puffer ab.
// Klassennamen mit Modul-Präfix: PHP-Klassen sind in Symcon prozessweit global.
// ===========================================================================

final class ESPHomeHubCipher
{
    private ?string $k;
    private int $n;

    public function __construct(?string $k = null, int $n = 0)
    {
        $this->k = $k;
        $this->n = $n;
    }

    public function setzeSchluessel(string $k): void
    {
        $this->k = $k;
        $this->n = 0;
    }

    /** Noise-Nonce für ChaChaPoly: 4 Nullbytes + 64-Bit-Zähler little-endian. */
    private function nonce(): string
    {
        return "\0\0\0\0" . pack('P', $this->n);
    }

    public function verschluesseln(string $ad, string $klartext): string
    {
        if ($this->k === null) {
            return $klartext;
        }
        $c = sodium_crypto_aead_chacha20poly1305_ietf_encrypt($klartext, $ad, $this->nonce(), $this->k);
        $this->n++;
        return $c;
    }

    /** @throws ESPHomeHubSchluesselFehler bei falschem Schlüssel oder verfälschten Daten */
    public function entschluesseln(string $ad, string $geheim): string
    {
        if ($this->k === null) {
            return $geheim;
        }
        $p = sodium_crypto_aead_chacha20poly1305_ietf_decrypt($geheim, $ad, $this->nonce(), $this->k);
        if ($p === false) {
            throw new ESPHomeHubSchluesselFehler('Entschlüsseln fehlgeschlagen');
        }
        $this->n++;
        return $p;
    }

    public function export(): array
    {
        return ['k' => $this->k === null ? null : bin2hex($this->k), 'n' => $this->n];
    }

    public static function import(array $d): self
    {
        return new self(isset($d['k']) ? (string)hex2bin((string)$d['k']) : null, (int)($d['n'] ?? 0));
    }
}

final class ESPHomeHubSchluesselFehler extends RuntimeException
{
}

final class ESPHomeHubNoise
{
    public const PROTOKOLL = 'Noise_NNpsk0_25519_ChaChaPoly_SHA256';
    public const PROLOG = "NoiseAPIInit\0\0";

    private string $h;
    private string $ck;
    private ESPHomeHubCipher $cs;
    private string $eGeheim;

    private function __construct(string $h, string $ck, ESPHomeHubCipher $cs, string $eGeheim)
    {
        $this->h = $h;
        $this->ck = $ck;
        $this->cs = $cs;
        $this->eGeheim = $eGeheim;
    }

    /** Neuer Handshake als Initiator; $ephemer nur für Testvektoren. */
    public static function starten(?string $ephemer = null): self
    {
        $h = strlen(self::PROTOKOLL) <= 32 ? str_pad(self::PROTOKOLL, 32, "\0") : hash('sha256', self::PROTOKOLL, true);
        $n = new self($h, $h, new ESPHomeHubCipher(), $ephemer ?? random_bytes(32));
        $n->mischeHash(self::PROLOG);
        return $n;
    }

    /** Base64-Schlüssel aus der Geräte-YAML → 32 Byte, sonst null. */
    public static function schluesselLesen(string $base64): ?string
    {
        $roh = base64_decode(trim($base64), true);
        return ($roh !== false && strlen($roh) === 32) ? $roh : null;
    }

    private function mischeHash(string $d): void
    {
        $this->h = hash('sha256', $this->h . $d, true);
    }

    private function hkdf(string $ikm, int $anzahl): array
    {
        $t = hash_hmac('sha256', $ikm, $this->ck, true);
        $o1 = hash_hmac('sha256', "\x01", $t, true);
        $o2 = hash_hmac('sha256', $o1 . "\x02", $t, true);
        if ($anzahl === 2) {
            return [$o1, $o2];
        }
        return [$o1, $o2, hash_hmac('sha256', $o2 . "\x03", $t, true)];
    }

    private function mischeSchluessel(string $ikm): void
    {
        [$this->ck, $k] = $this->hkdf($ikm, 2);
        $this->cs->setzeSchluessel($k);
    }

    /** Nachricht 1 (→ psk, e) mit leerer Nutzlast: 32 Byte e + 16 Byte Prüfsumme. */
    public function nachricht1(string $psk): string
    {
        [$this->ck, $th, $k] = $this->hkdf($psk, 3);
        $this->mischeHash($th);
        $this->cs->setzeSchluessel($k);
        $e = sodium_crypto_scalarmult_base($this->eGeheim);
        $this->mischeHash($e);
        $this->mischeSchluessel($e);   // PSK-Modus: e fließt auch in den Schlüssel
        $c = $this->cs->verschluesseln($this->h, '');
        $this->mischeHash($c);
        return $e . $c;
    }

    /**
     * Nachricht 2 (← e, ee) lesen und aufteilen.
     * @return ESPHomeHubCipher[] [senden, empfangen]
     * @throws ESPHomeHubSchluesselFehler bei falschem Schlüssel
     */
    public function nachricht2(string $nachricht): array
    {
        if (strlen($nachricht) < 48) {
            throw new RuntimeException('Handshake-Antwort zu kurz (' . strlen($nachricht) . ' Byte)');
        }
        $re = substr($nachricht, 0, 32);
        $this->mischeHash($re);
        $this->mischeSchluessel($re);
        $this->mischeSchluessel(sodium_crypto_scalarmult($this->eGeheim, $re));
        $c = substr($nachricht, 32);
        $this->cs->entschluesseln($this->h, $c);
        $this->mischeHash($c);
        [$k1, $k2] = $this->hkdf('', 2);
        return [new ESPHomeHubCipher($k1), new ESPHomeHubCipher($k2)];
    }

    public function hash(): string
    {
        return $this->h;
    }

    public function export(): array
    {
        return ['h' => bin2hex($this->h), 'ck' => bin2hex($this->ck), 'cs' => $this->cs->export(), 'e' => bin2hex($this->eGeheim)];
    }

    public static function import(array $d): self
    {
        return new self((string)hex2bin((string)$d['h']), (string)hex2bin((string)$d['ck']), ESPHomeHubCipher::import((array)$d['cs']), (string)hex2bin((string)$d['e']));
    }
}
